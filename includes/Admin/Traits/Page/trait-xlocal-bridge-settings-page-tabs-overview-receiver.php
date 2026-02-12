<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Tabs_Overview_Receiver_Trait {
    private static function render_overview_tab( $mode, $options ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="overview" id="xlocal-panel-overview" role="tabpanel" aria-labelledby="xlocal-tab-overview">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Overview</h2>';
        echo '<p>Choose how this site participates in the bridge.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Mode</th><td>';
        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[mode]">';
        $modes = array(
            'receiver' => 'Receiver only',
            'sender' => 'Sender only',
            'both' => 'Sender + Receiver',
        );
        foreach ( $modes as $key => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $mode, $key, false ), esc_html( $label ) );
        }
        echo '</select>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Set Receiver on Main WP, Sender on Worker WP. Use Both only for testing.</span></span></td></tr>';
        echo '</table>';
        self::render_status_badges( $options );
        echo '</div>';
    }

    private static function render_receiver_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="receiver" id="xlocal-panel-receiver" role="tabpanel" aria-labelledby="xlocal-tab-receiver">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Receiver</h2>';
        echo '<p>Security and content rules for ingesting posts.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        self::render_field( 'receiver_enabled', 'Enable Receiver', 'checkbox', 'Toggle ingest endpoint.' );
        self::render_field( 'receiver_secret', 'Shared Secret', 'password', 'HMAC secret. Must match sender.' );
        self::render_field( 'receiver_clock_skew', 'Allowed Clock Skew (seconds)', 'number', 'Default 300 seconds.' );
        self::render_field( 'receiver_nonce_ttl', 'Nonce TTL (seconds)', 'number', 'Default 600 seconds.' );
        self::render_field( 'receiver_max_payload_kb', 'Max Payload Size (KB)', 'number', 'Reject oversized payloads early.' );
        self::render_field( 'receiver_ip_allowlist', 'IP Allowlist', 'textarea', 'One IP per line.' );
        self::render_field( 'receiver_rate_limit', 'Rate Limit (req/min)', 'number', '0 disables rate limiting.' );
        self::render_field( 'receiver_require_tls', 'Require TLS', 'checkbox', 'Reject non-HTTPS requests.' );
        self::render_field( 'receiver_default_post_type', 'Default Post Type', 'text', 'post or custom post type.' );
        self::render_field( 'receiver_default_status', 'Default Status', 'select_status', 'publish, pending, or draft.' );
        self::render_field( 'receiver_allow_sender_override_status', 'Allow Sender Override', 'checkbox', 'Let sender set post status.' );
        self::render_field( 'receiver_author_mode', 'Author Mapping Mode', 'select_author_mode', 'Fixed, by name, or by email.' );
        self::render_field( 'receiver_fixed_author_id', 'Fixed Author User ID', 'number', 'User ID when using fixed author.' );
        self::render_field( 'receiver_allowed_media_domains', 'Allowed Media Domains', 'textarea', 'Example: cdn.example.com' );
        self::render_field( 'receiver_reject_non_allowed_media', 'Reject Non-Allowed <img src>', 'checkbox', 'Strictly enforce CDN-only media.' );
        self::render_field( 'receiver_featured_image_mode', 'Featured Image Mode', 'select_featured_mode', 'Meta-only or virtual attachment.' );
        self::render_field( 'receiver_prepend_featured_if_missing', 'Prepend Featured Image If Content Has No Image', 'checkbox', 'If incoming content has no <img>, prepend featured image at top.' );
        echo '</table>';
        echo '</div>';
    }

    private static function render_receiver_debug_tab( $receiver_debug_log_history ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="receiver_debug" id="xlocal-panel-receiver_debug" role="tabpanel" aria-labelledby="xlocal-tab-receiver_debug">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Receiver Debug</h2>';
        echo '<p>Content-aware ingest logs: images, hosts, featured handling, taxonomy, and ingest result.</p>';
        echo '</div>';
        echo '<p class="xlocal-field-hint">Production mode: success logs are compact by default; detailed taxonomy payload internals are kept mainly for non-success diagnostics.</p>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Receiver Ingest Log</th><td>';
        $lines = array_filter( explode( "\n", (string) $receiver_debug_log_history ) );
        if ( empty( $lines ) ) {
            echo '<p>No receiver debug entries yet. Enable "Enable Ingest Log" in Advanced, then ingest one post.</p>';
        } else {
            $lines = array_reverse( $lines );
            foreach ( $lines as $line ) {
                $entry = json_decode( $line, true );
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $ts = ! empty( $entry['timestamp_utc'] ) ? sanitize_text_field( (string) $entry['timestamp_utc'] ) . ' UTC' : '-';
                $status = ! empty( $entry['status'] ) ? sanitize_text_field( (string) $entry['status'] ) : 'info';
                $message = ! empty( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
                echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:12px;background:#fff;">';
                echo '<p><strong>' . esc_html( strtoupper( $status ) ) . '</strong> | ' . esc_html( $ts ) . '</p>';
                echo '<p>' . esc_html( $message ) . '</p>';
                if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) {
                    echo '<textarea readonly rows="10" style="font-family:Menlo,Consolas,monospace;">' . esc_textarea( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea>';
                }
                echo '</div>';
            }
        }
        $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_clear_receiver_debug_logs' ), 'xlocal_clear_receiver_debug_logs' );
        echo '<p><a href="' . esc_url( $clear_url ) . '" class="button button-secondary">Clear Receiver Debug Data</a></p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';
    }

}
