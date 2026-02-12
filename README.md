# xLocal Bridge Post

xLocal Bridge Post is a WordPress plugin for **syncing posts from one WordPress site (Sender) to another (Receiver)** using a signed JSON payload over HTTPS.

### Typical problem this solves

Kamu punya satu WordPress yang menjalankan **AI rewrite dari RSS dan Sumber Lainya**:

- Worker AI ini terus-menerus menarik RSS, rewriting artikel, memproses gambar, dan publish.
- Semua proses berat (AI, HTTP requests, image, dsb.) terjadi di **situs utama** yang juga dipakai pengunjung.
- Akhirnya, ketika load lagi tinggi, CPU/RAM naik dan **seluruh website ikut melambat atau bahkan down**.

Dengan xLocal Bridge Post kamu bisa pisahkan beban itu:

- **Site A (Worker / Sender)**: tempat AI rewrite dan semua proses berat dijalankan.
- **Site B (Main / Receiver)**: hanya menerima hasil akhir yang sudah bersih (judul, konten, kategori, media CDN) lewat endpoint aman.
- Pengunjung hanya mengakses Site B yang ringan, sementara Site A boleh kerja seberat apapun tanpa menjatuhkan main site.

Jadi secara sederhana: *satu site fokus produksi konten (berat), satu site fokus serve pembaca (ringan)*.

The focus is:

- **Clean CDN-only media**: receiver can strictly enforce allowed image domains.
- **Author & taxonomy mapping**: flexible but safe mapping for authors, categories, and tags.
- **No duplicates**: receiver deduplicates by source URL/hash and ingest ID.
- **Bulk Import**: backfill older posts from sender without creating double posts.

---

## 1. Concepts

- **Sender site**: tempat konten dibuat. Plugin men-serialize post menjadi JSON dan kirim ke Receiver.
- **Receiver site**: tempat konten ditampilkan. Plugin memverifikasi signature, validasi payload, lalu membuat / mengupdate post.
- **Shared Secret**: kunci HMAC yang sama di kedua site. Tanpa ini, payload akan ditolak.
- **CDN-only media (opsional)**: Receiver bisa menolak semua `<img>` yang bukan dari domain yang sudah di-whitelist.

Mode plugin:

- `Sender only`
- `Receiver only`
- `Sender + Receiver` (disarankan hanya untuk testing).

---

## 2. Installation

1. Copy folder `xlocal-bridge-post` ke direktori `wp-content/plugins/`.
2. Di kedua site (Sender & Receiver), aktifkan plugin di **Plugins → Installed Plugins**.
3. Buka **Settings → xLocal Bridge Post** untuk konfigurasi.

---

## 3. Basic setup (minimal working flow)

### Step 1 – Configure Receiver (main site)

On the **Receiver** site:

1. Go to **Settings → xLocal Bridge Post → Overview**:
   - **Mode**: `Receiver`.
2. Go to **Receiver** tab:
   - **Enable Receiver**: ON.
   - **Require TLS**: ON (disarankan).
   - **Shared Secret**: set nilai random 24+ karakter (catat untuk Sender).
   - **Default Post Type**: `post` (atau custom post type lain).
   - **Default Status**: `pending` (aman untuk awal).
   - **Allowed Media Domains**: isi domain CDN final (misalnya `cdn.example.com`).
   - **Reject Non-Allowed <img src>**: ON jika mau full CDN-enforced.
3. Save changes.

### Step 2 – Configure Sender (worker site)

On the **Sender** site:

1. Go to **Settings → xLocal Bridge Post → Overview**:
   - **Mode**: `Sender`.
2. Go to **Sender** tab:
   - **Main Site Base URL**: URL Receiver; example `https://main.example.com`.
   - **Ingest Endpoint Path**: biasanya `/wp-json/xlocal/v1/ingest`.
   - **Shared Secret**: sama persis dengan Receiver.
   - **Target Post Type**: `post` (atau sama dengan Receiver).
   - **Default Status to Send**: biasanya `pending` atau `publish`.
   - **Include Author**: ON jika ingin mapping author.
   - **Send Taxonomies**: ON untuk kirim kategori & tag.
3. Klik **Save Changes**.
4. Di tab **Sender**, gunakan tombol **Send Test Payload**.
   - Di Receiver, cek bahwa respon `200` dan tidak ada error di Logs / error log server.

Jika test sudah jalan, baru aktifkan:

- **Auto Send on Publish/Update** di Sender.

---

## 4. How sending works (Sender flow)

On the Sender site:

- Plugin hook ke `save_post`:
  - Hanya jalan ketika:
    - Mode termasuk `Sender`.
    - `sender_auto_send` = ON.
    - `post_type` = `sender_target_post_type`.
    - `post_status` = `publish`.
    - Post bukan hasil remote ingest (`_xlocal_ingest_id` & `_xlocal_source_url` kosong).
- Payload yang dikirim berisi:
  - `ingest_id`: UUID baru setiap payload.
  - `source_url`: permalink penuh post di Sender.
  - `source_hash`: hash dari title + content + modified date (untuk dedup).
  - `title`, `content_html`, `excerpt`.
  - `status`: berdasarkan `sender_default_status` (boleh di-override config Receiver).
  - `date_gmt`: tanggal publish asli di Sender.
  - `media_manifest`: daftar URL image (konten + featured image).
  - Opsional:
    - `author` (name + email) jika `sender_include_author` aktif.
    - `categories` dan `tags` jika `sender_send_taxonomies` aktif.

Mode kirim:

- **Immediate**: dikirim langsung saat post disimpan.
- **Batch**: post dimasukkan antrian, lalu dikirim lewat WP-Cron (hook internal).

Plugin juga menyimpan hash terakhir yang dikirim (`_xlocal_sender_last_hash`), sehingga:

- Jika konten tidak berubah, payload tidak dikirim ulang (mengurangi noise).

---

## 5. How receiving works (Receiver flow)

Endpoint Receiver:

- `POST /wp-json/xlocal/v1/ingest`
- Header yang wajib:
  - `X-Xlocal-Timestamp`
  - `X-Xlocal-Nonce`
  - `X-Xlocal-Signature` (HMAC-SHA256)
  - `X-Xlocal-Origin-Host`

Flow di Receiver:

1. **Mode & enabled check**  
   - Mode harus `Receiver` atau `Both`.
   - `receiver_enabled` harus ON.
2. **TLS check**  
   - Jika `receiver_require_tls` ON, request harus HTTPS.
3. **Signature + nonce validation**  
   - Timestamp harus dalam window `receiver_clock_skew`.
   - Nonce tidak boleh pernah dipakai (dicek via transient).
   - Signature HMAC harus valid berdasarkan Shared Secret.
4. **Payload validation**  
   - JSON valid.
   - Field wajib: `ingest_id`, `source_url`, `title`, `content_html`.
   - Jika `receiver_reject_non_allowed_media` ON:
     - Semua `<img src>` harus dari domain yang ada di `receiver_allowed_media_domains`.
5. **Dedup & upsert**  
   - Pertama, cek `_xlocal_ingest_id`:
     - Jika ada match → `action = noop_duplicate` (tidak buat post baru).
   - Lalu, dedup berdasarkan:
     - `receiver_source_url_meta_key` + `source_hash` (mode `source_hash+source_url`), atau
     - hanya `receiver_source_url_meta_key` (mode `source_url`).
   - Hasil:
     - Jika tidak ada post → `created`.
     - Jika ada post → `updated` (dengan kontrol update strategy).
6. **Update strategy**  
   - `receiver_update_strategy = overwrite_all`:
     - Konten dari Sender akan selalu menimpa post lama (kecuali post dikunci).
   - `preserve_manual_edits`:
     - Jika post sudah di-edit secara manual di Receiver (lebih baru dari ingested_at) → konten tidak di-overwrite.
7. **Author mapping** (lihat di bawah).
8. **Taxonomy mapping**  
   - Kategori & tag akan dinormalisasi dan bisa auto-create sesuai opsi.

---

## 6. Author mapping (safe roles, random editor, fixed)

Receiver mengontrol bagaimana `post_author` ditentukan lewat **Author Mapping Mode**:

- **Fixed Author** (`fixed_author`):
  - Selalu pakai user ID yang di-set di `receiver_fixed_author_id`.
- **By Name** (`by_name`):
  - Mencari user berdasarkan `author.name`:
    - `user_login`, lalu fallback ke `display_name`.
  - Hanya menerima roles: `administrator`, `editor`, `author`.
- **By Email** (`by_email`):
  - Mencari user berdasarkan `author.email`.
  - Hanya menerima roles: `administrator`, `editor`, `author`.
- **Random Editor (safe roles)** (`random_editor`):
  - Pilih secara acak satu user dengan role `editor`.
  - Jika tidak ada editor, fallback ke `administrator` pertama.

Jika semua mode di atas tidak menemukan user:

- Jika `receiver_fixed_author_id` > 0 → pakai fixed author tersebut.
- Jika tidak, fallback ke `get_current_user_id()` (biasanya 0 untuk request anonim).

Ini memastikan subscriber / contributor tidak tiba-tiba jadi penulis di main site.

---

## 7. Categories & tags

Jika `sender_send_taxonomies` di Sender = ON:

- Payload akan berisi:
  - `categories`: nama kategori (`category` taxonomy).
  - `tags`: nama tag (`post_tag` taxonomy).

Di Receiver:

- Kategori:
  - Dinormalisasi (optional lowercasing).
  - Bisa di-map melalui `receiver_category_mapping_rules`  
    (contoh: `News -> Updates`).
  - Jika `receiver_auto_create_categories` ON:
    - Kategori yang belum ada akan dibuat otomatis.
- Tag:
  - Dinormalisasi serupa.
  - Jika `receiver_auto_create_tags` ON:
    - Tag baru akan dibuat otomatis.

---

## 8. Bulk Import (backfill older posts)

Kadang kamu baru install plugin ini setelah sudah punya banyak konten di Sender.  
Bulk Import membantu **mengirim post lama** tanpa menulis script manual, dan tetap aman dari duplikasi.

### Where to find it

On the **Sender** site:

- Go to **Settings → xLocal Bridge Post → Bulk Import**.
- Tab ini hanya aktif kalau Mode = `Sender` atau `Both`.

### What Bulk Import does

- Menjalankan query post di Sender sesuai filter yang kamu pilih.
- Untuk setiap post:
  - Membuat payload sama persis seperti auto-send biasa.
  - Memanggil jalur kirim yang sama (HMAC, endpoint, dll).
  - Menggunakan `_xlocal_sender_last_hash` untuk skip payload yang identik.
- Di Receiver:
  - Dedup dan update tetap di-handle oleh logic yang sama (`upsert_post`).

### Bulk Import filters

Di tab Bulk Import kamu bisa atur:

- **Bulk Post Type**:
  - Post type yang akan diproses (default: `sender_target_post_type`).
- **Bulk Status**:
  - `publish`, `pending`, atau `draft` (default: `publish`).
- **Only Posts After Date**:
  - Opsional; untuk membatasi hanya post setelah tanggal tertentu.
- **Batch Size**:
  - Berapa banyak post yang diproses tiap run (default 25, maksimal 200).

Kamu bisa menjalankan Bulk Import berkali-kali:

- Post yang belum pernah dikirim → akan dikirim.
- Post dengan payload yang sama seperti terakhir → akan di-skip.
- Post yang berasal dari Receiver (punya meta ingest/source) → di-skip, supaya tidak loop.

---

## 9. Backfilling strategy (recommended)

Untuk migrasi awal banyak konten:

1. Set up Sender & Receiver dengan status default `pending` di Receiver.
2. Di Sender, buka tab **Bulk Import**:
   - Post type: `post`.
   - Status: `publish`.
   - Date filter: isi tanggal beberapa bulan terakhir dulu (jangan semua sekaligus).
   - Batch size: mulai dari 20–50.
3. Jalankan Bulk Import.
4. Review konten di Receiver (kategori, author, media).
5. Ulangi Bulk Import dengan range tanggal lebih lama sampai semua konten yang diinginkan terkirim.

Kalau kamu update konten lama di Sender:

- Auto-send normal tetap akan jalan di hook `save_post`.
- Receiver akan update atau skip sesuai `receiver_update_strategy`.

---

## 10. Security notes (short)

- **Shared Secret** adalah kunci utama:
  - Gunakan nilai random panjang.
  - Jika bocor, ganti di kedua site.
- Batasi akses ke halaman setting hanya untuk admin yang kamu percaya.
- Gunakan HTTPS di kedua site, terutama jika `receiver_require_tls` diaktifkan.
- Jika environment mendukung, gunakan IP allowlist di Receiver untuk lapisan ekstra.

---

## 11. Versioning

- Plugin header version: `0.5.0`
- Admin assets (`admin.css`, `admin.js`) juga memakai `0.5.0` untuk cache-busting.

