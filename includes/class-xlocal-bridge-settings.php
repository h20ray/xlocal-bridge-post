<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Settings {
    const OPTION_KEY = 'xlocal_bridge_post';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_xlocal_sender_test', array( __CLASS__, 'handle_test_payload' ) );
        add_action( 'admin_post_xlocal_bulk_send_run', array( __CLASS__, 'handle_bulk_send' ) );
        add_action( 'admin_post_xlocal_check_updates_now', array( __CLASS__, 'handle_check_updates_now' ) );
        add_action( 'admin_post_xlocal_update_plugin_now', array( __CLASS__, 'handle_update_plugin_now' ) );
        add_action( 'admin_post_xlocal_clear_sender_debug_logs', array( __CLASS__, 'handle_clear_sender_debug_logs' ) );
        add_action( 'admin_post_update', array( __CLASS__, 'maybe_handle_bulk_send_from_update_route' ) );
        add_action( 'admin_post_xlocal_bulk_import_run', array( __CLASS__, 'handle_bulk_import' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
    }

    public static function defaults() {
        return array(
            'mode' => 'both',

            // Receiver
            'receiver_enabled' => 0,
            'receiver_secret' => '',
            'receiver_clock_skew' => 300,
            'receiver_nonce_ttl' => 600,
            'receiver_ip_allowlist' => '',
            'receiver_rate_limit' => 60,
            'receiver_require_tls' => 1,
            'receiver_max_payload_kb' => 512,
            'receiver_default_post_type' => 'post',
            'receiver_default_status' => 'pending',
            'receiver_allow_sender_override_status' => 0,
            'receiver_author_mode' => 'fixed_author',
            'receiver_fixed_author_id' => 0,
            'receiver_dedup_mode' => 'source_url',
            'receiver_source_url_meta_key' => '_xlocal_source_url',
            'receiver_update_strategy' => 'overwrite_all',
            'receiver_auto_create_categories' => 1,
            'receiver_auto_create_tags' => 1,
            'receiver_category_mapping_rules' => '',
            'receiver_tag_normalization' => 1,
            'receiver_allowed_media_domains' => '',
            'receiver_reject_non_allowed_media' => 1,
            'receiver_featured_image_mode' => 'meta_only',
            'receiver_sanitize_html' => 1,
            'receiver_allowed_profile' => 'standard',
            'receiver_custom_allowed' => '',
            'receiver_strip_inline_styles' => 1,
            'receiver_strip_scripts_iframes' => 1,
            'receiver_enable_log' => 0,
            'receiver_log_storage' => 'postmeta',
            'receiver_retain_logs_days' => 30,

            // Sender
            'sender_main_base_url' => '',
            'sender_ingest_path' => '/wp-json/xlocal/v1/ingest',
            'sender_secret' => '',
            'sender_auto_send' => 0,
            'sender_timeout' => 15,
            'sender_max_retries' => 3,
            'sender_backoff_base_ms' => 500,
            'sender_max_payload_kb' => 512,
            'sender_target_post_type' => 'post',
            'sender_default_status' => 'pending',
            'sender_include_author' => 0,
            'sender_author_name' => '',
            'sender_send_taxonomies' => 1,
            'sender_cdn_base' => '',
            'sender_ensure_cdn_urls' => 1,
            'sender_inject_dimensions' => 1,
            'sender_image_format_preference' => 'webp',
            'sender_sync_mode' => 'immediate',
            'sender_batch_size' => 10,
            'sender_schedule_interval' => 'five_minutes',
            'sender_dry_run' => 0,
            'sender_debug_logs' => 0,
            'sender_last_push_result' => '',
            'sender_debug_log_history' => '',
        );
    }

    public static function get_options() {
        $defaults = self::defaults();
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array_merge( $defaults, $stored );
    }

    public static function get_receiver_secret() {
        if ( defined( 'XLOCAL_BRIDGE_SECRET' ) && XLOCAL_BRIDGE_SECRET ) {
            return XLOCAL_BRIDGE_SECRET;
        }
        $options = self::get_options();
        return $options['receiver_secret'];
    }

    public static function get_sender_secret() {
        if ( defined( 'XLOCAL_BRIDGE_SECRET' ) && XLOCAL_BRIDGE_SECRET ) {
            return XLOCAL_BRIDGE_SECRET;
        }
        $options = self::get_options();
        return $options['sender_secret'];
    }

    public static function register_menu() {
        add_options_page(
            'xLocal Bridge Post',
            'xLocal Bridge Post',
            'manage_options',
            'xlocal-bridge-post',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'xlocal_bridge_post', self::OPTION_KEY, array( __CLASS__, 'sanitize_options' ) );
    }

    public static function sanitize_options( $input ) {
        $defaults = self::defaults();
        $output = array();
        $checkbox_keys = array(
            'receiver_enabled',
            'receiver_require_tls',
            'receiver_allow_sender_override_status',
            'receiver_auto_create_categories',
            'receiver_auto_create_tags',
            'receiver_tag_normalization',
            'receiver_reject_non_allowed_media',
            'receiver_sanitize_html',
            'receiver_strip_inline_styles',
            'receiver_strip_scripts_iframes',
            'receiver_enable_log',
            'sender_auto_send',
            'sender_include_author',
            'sender_send_taxonomies',
            'sender_ensure_cdn_urls',
            'sender_inject_dimensions',
            'sender_dry_run',
            'sender_debug_logs',
        );
        foreach ( $defaults as $key => $value ) {
            if ( ! isset( $input[ $key ] ) && in_array( $key, $checkbox_keys, true ) ) {
                $output[ $key ] = 0;
                continue;
            }
            if ( ! isset( $input[ $key ] ) ) {
                continue;
            }
            $raw = $input[ $key ];
            if ( is_int( $value ) ) {
                $output[ $key ] = intval( $raw );
                continue;
            }
            if ( is_bool( $value ) ) {
                $output[ $key ] = $raw ? 1 : 0;
                continue;
            }
            if ( is_string( $value ) ) {
                if ( strpos( $key, 'allowlist' ) !== false || strpos( $key, 'mapping_rules' ) !== false || strpos( $key, 'custom_allowed' ) !== false || strpos( $key, 'debug_log_history' ) !== false ) {
                    $output[ $key ] = sanitize_textarea_field( $raw );
                } else {
                    $output[ $key ] = sanitize_text_field( $raw );
                }
                continue;
            }
            $output[ $key ] = $raw;
        }

        $merged = array_merge( $defaults, $output );
        $merged['mode'] = in_array( $merged['mode'], array( 'receiver', 'sender', 'both' ), true ) ? $merged['mode'] : 'both';
        $merged['receiver_default_status'] = in_array( $merged['receiver_default_status'], array( 'publish', 'pending', 'draft' ), true ) ? $merged['receiver_default_status'] : 'pending';
        $merged['sender_default_status'] = in_array( $merged['sender_default_status'], array( 'publish', 'pending', 'draft' ), true ) ? $merged['sender_default_status'] : 'pending';
        $merged['sender_sync_mode'] = in_array( $merged['sender_sync_mode'], array( 'immediate', 'batch' ), true ) ? $merged['sender_sync_mode'] : 'immediate';
        $merged['receiver_author_mode'] = in_array( $merged['receiver_author_mode'], array( 'fixed_author', 'by_name', 'by_email', 'random_editor' ), true ) ? $merged['receiver_author_mode'] : 'fixed_author';
        $merged['sender_schedule_interval'] = in_array( $merged['sender_schedule_interval'], array( 'five_minutes', 'fifteen_minutes', 'hourly' ), true ) ? $merged['sender_schedule_interval'] : 'five_minutes';
        $merged['receiver_default_post_type'] = sanitize_key( $merged['receiver_default_post_type'] );
        $merged['sender_target_post_type'] = sanitize_key( $merged['sender_target_post_type'] );
        $merged['sender_main_base_url'] = untrailingslashit( esc_url_raw( $merged['sender_main_base_url'] ) );
        $merged['sender_ingest_path'] = '/' . ltrim( sanitize_text_field( $merged['sender_ingest_path'] ), '/' );
        $merged['receiver_clock_skew'] = max( 30, min( 3600, intval( $merged['receiver_clock_skew'] ) ) );
        $merged['receiver_nonce_ttl'] = max( 60, min( 86400, intval( $merged['receiver_nonce_ttl'] ) ) );
        $merged['receiver_rate_limit'] = max( 0, intval( $merged['receiver_rate_limit'] ) );
        $merged['receiver_max_payload_kb'] = max( 64, min( 10240, intval( $merged['receiver_max_payload_kb'] ) ) );
        $merged['sender_timeout'] = max( 3, min( 120, intval( $merged['sender_timeout'] ) ) );
        $merged['sender_max_retries'] = max( 0, min( 8, intval( $merged['sender_max_retries'] ) ) );
        $merged['sender_backoff_base_ms'] = max( 100, min( 10000, intval( $merged['sender_backoff_base_ms'] ) ) );
        $merged['sender_max_payload_kb'] = max( 64, min( 10240, intval( $merged['sender_max_payload_kb'] ) ) );
        $merged['sender_batch_size'] = max( 1, min( 100, intval( $merged['sender_batch_size'] ) ) );

        return $merged;
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'settings_page_xlocal-bridge-post' ) {
            return;
        }
        $base_url = plugin_dir_url( __FILE__ );
        $base_url = str_replace( '/includes', '', $base_url );
        $asset_version = defined( 'XLOCAL_BRIDGE_POST_VERSION' ) ? XLOCAL_BRIDGE_POST_VERSION : '0.5.1';
        wp_enqueue_style( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.css', array(), $asset_version );
        wp_enqueue_script( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.js', array(), $asset_version, true );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = self::get_options();
        $mode = $options['mode'];

        $tabs = array(
            'overview' => array(
                'label' => 'Overview',
                'desc' => 'Choose mode and verify essentials before sending or receiving.',
            ),
        );
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            $tabs['receiver'] = array(
                'label' => 'Receiver',
                'desc' => 'Security, content policy, and media rules for ingest.',
            );
        }
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $tabs['sender'] = array(
                'label' => 'Sender',
                'desc' => 'Endpoint, publishing, and CDN output rules.',
            );
        }
        $tabs['advanced'] = array(
            'label' => 'Advanced',
            'desc' => 'Sanitization, dedup, and logging controls.',
        );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $tabs['logs'] = array(
                'label' => 'Logs',
                'desc' => 'Diagnostics and sender update status.',
            );
            $tabs['bulk_send'] = array(
                'label' => 'Bulk Send',
                'desc' => 'Backfill old posts with deterministic, test-friendly sending.',
            );
        }
        $tabs['documentation'] = array(
            'label' => 'Documentation',
            'desc' => 'Step-by-step guide for non-technical users.',
        );

        echo '<div class="wrap xlocal-admin">';
        echo '<div class="xlocal-hero">';
        echo '<div class="xlocal-hero-text">';
        echo '<span class="xlocal-chip">Bridge</span>';
        echo '<h1>xLocal Bridge Post</h1>';
        echo '<p>Unified Sender + Receiver settings with clarity and control.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="xlocal-layout">';
        echo '<form method="post" action="options.php" class="xlocal-card xlocal-elevated">';
        settings_fields( 'xlocal_bridge_post' );

        echo '<div class="xlocal-tabs" role="tablist" aria-label="xLocal settings tabs">';
        foreach ( $tabs as $id => $tab ) {
            printf(
                '<button type="button" class="xlocal-tab" data-tab="%1$s" id="xlocal-tab-%1$s" role="tab" aria-controls="xlocal-panel-%1$s" aria-selected="false" tabindex="-1">%2$s</button>',
                esc_attr( $id ),
                esc_html( $tab['label'] )
            );
        }
        echo '</div>';

        self::render_overview_tab( $mode, $options );
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            self::render_receiver_tab();
        }
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_sender_tab();
        }
        self::render_advanced_tab( $mode );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_logs_tab( $options['sender_last_push_result'], isset( $options['sender_debug_log_history'] ) ? $options['sender_debug_log_history'] : '' );
            self::render_bulk_send_tab( $options );
        }
        self::render_documentation_tab();

        submit_button();
        echo '</form>';

        echo '<div class="xlocal-card xlocal-side">';
        echo '<section class="xlocal-side-section">';
        echo '<h2>Status</h2>';
        self::render_status_badges( $options );
        echo '</section>';
        echo '<section class="xlocal-side-section">';
        echo '<h2>Quick Checklist</h2>';
        echo '<ul>';
        echo '<li>Mode set correctly for this site.</li>';
        echo '<li>Secrets match between sender and receiver.</li>';
        echo '<li>CDN domain allowlisted on receiver.</li>';
        echo '<li>Test payload succeeds.</li>';
        echo '</ul>';
        echo '<div class="xlocal-note"><strong>Tip:</strong> Use <code>wp-config.php</code> to lock the shared secret.</div>';
        echo '</section>';
        echo '<section class="xlocal-side-section">';
        echo '<h2>About</h2>';
        echo '<p>xLocal Bridge Post securely syncs content between WordPress sites with sender and receiver control.</p>';
        echo '</section>';
        echo '<section class="xlocal-side-section xlocal-author">';
        echo '<h2>Author</h2>';
        echo '<p><strong>Andoru Ray</strong></p>';
        echo '<p><a href="mailto:andoru@tujuhcahaya.com">andoru@tujuhcahaya.com</a></p>';
        echo '</section>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

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
        echo '</table>';
        echo '</div>';
    }

    private static function render_sender_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="sender" id="xlocal-panel-sender" role="tabpanel" aria-labelledby="xlocal-tab-sender">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Sender</h2>';
        echo '<p>Main endpoint, publishing defaults, and CDN output rules.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        self::render_field( 'sender_auto_send', 'Auto Send on Publish/Update', 'checkbox', 'Automatically push target post type updates.' );
        self::render_field( 'sender_main_base_url', 'Main Site Base URL', 'text', 'Example: https://www.example.com' );
        self::render_field( 'sender_ingest_path', 'Ingest Endpoint Path', 'text', 'Default /wp-json/xlocal/v1/ingest' );
        self::render_field( 'sender_secret', 'Shared Secret', 'password', 'Must match receiver.' );
        self::render_field( 'sender_timeout', 'Request Timeout (seconds)', 'number', 'Default 15 seconds.' );
        self::render_field( 'sender_max_retries', 'Max Retries', 'number', 'Default 3 retries.' );
        self::render_field( 'sender_backoff_base_ms', 'Backoff Base (ms)', 'number', 'Default 500ms.' );
        self::render_field( 'sender_max_payload_kb', 'Max Payload Size (KB)', 'number', 'Block oversized payloads before HTTP send.' );
        self::render_field( 'sender_target_post_type', 'Target Post Type', 'text', 'post or custom post type.' );
        self::render_field( 'sender_default_status', 'Default Status to Send', 'select_status', 'publish, pending, or draft.' );
        self::render_field( 'sender_include_author', 'Include Author', 'checkbox', 'Send author mapping fields.' );
        self::render_field( 'sender_author_name', 'Author Name', 'text', 'Example: editor' );
        self::render_field( 'sender_send_taxonomies', 'Send Taxonomies', 'checkbox', 'Send categories and tags.' );
        self::render_field( 'sender_cdn_base', 'CDN Base Domain', 'text', 'Example: https://cdn.example.com' );
        self::render_field( 'sender_ensure_cdn_urls', 'Ensure Final CDN URLs', 'checkbox', 'Fail if non-CDN URL detected.' );
        self::render_field( 'sender_inject_dimensions', 'Inject width/height', 'checkbox', 'Prevent CLS.' );
        self::render_field( 'sender_image_format_preference', 'Image Format', 'select_image_format', 'WebP, AVIF, or original.' );
        self::render_field( 'sender_sync_mode', 'Sync Mode', 'select_sync_mode', 'Immediate or batch.' );
        self::render_field( 'sender_batch_size', 'Batch Size', 'number', 'Default 10.' );
        self::render_field( 'sender_schedule_interval', 'Schedule', 'select_schedule', 'Batch schedule interval.' );
        self::render_field( 'sender_dry_run', 'Dry Run', 'checkbox', 'Do not actually send.' );
        echo '</table>';
        $test_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_sender_test' ), 'xlocal_sender_test' );
        echo '<p><strong>Sender Test:</strong> Sends a signed sample payload to the configured receiver endpoint.</p>';
        echo '<p><a href="' . esc_url( $test_url ) . '" class="button button-secondary">Send Test Payload</a></p>';
        echo '</div>';
    }

    private static function render_advanced_tab( $mode ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="advanced" id="xlocal-panel-advanced" role="tabpanel" aria-labelledby="xlocal-tab-advanced">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Advanced</h2>';
        echo '<p>Deduplication, sanitization, and logging controls.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            self::render_field( 'receiver_dedup_mode', 'Dedup Mode', 'select_dedup', 'source_url or source_hash+source_url.' );
            self::render_field( 'receiver_source_url_meta_key', 'Source URL Meta Key', 'text', 'Default _xlocal_source_url' );
            self::render_field( 'receiver_update_strategy', 'Update Strategy', 'select_update_strategy', 'Overwrite or preserve manual edits.' );
            self::render_field( 'receiver_auto_create_categories', 'Auto Create Categories', 'checkbox', 'Create missing categories.' );
            self::render_field( 'receiver_auto_create_tags', 'Auto Create Tags', 'checkbox', 'Create missing tags.' );
            self::render_field( 'receiver_category_mapping_rules', 'Category Mapping Rules', 'textarea', 'Example: source -> main' );
            self::render_field( 'receiver_tag_normalization', 'Tag Normalization', 'checkbox', 'Lowercase and trim.' );
            self::render_field( 'receiver_sanitize_html', 'Enable HTML Sanitization', 'checkbox', 'Run wp_kses on content.' );
            self::render_field( 'receiver_allowed_profile', 'Allowed Tags Profile', 'select_allowed_profile', 'Strict, standard, or custom.' );
            self::render_field( 'receiver_custom_allowed', 'Custom Allowed Tags (JSON)', 'textarea', 'Only used when profile is custom.' );
            self::render_field( 'receiver_strip_inline_styles', 'Strip Inline Styles', 'checkbox', 'Remove style attributes.' );
            self::render_field( 'receiver_strip_scripts_iframes', 'Strip Scripts/Iframes', 'checkbox', 'Remove script/iframe tags.' );
            self::render_field( 'receiver_enable_log', 'Enable Ingest Log', 'checkbox', 'Enable ingest log storage.' );
            self::render_field( 'receiver_log_storage', 'Log Storage', 'select_log_storage', 'DB table or postmeta.' );
            self::render_field( 'receiver_retain_logs_days', 'Retain Logs (days)', 'number', 'Default 30 days.' );
        }
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_field( 'sender_debug_logs', 'Sender Debug Logs', 'checkbox', 'Store detailed sender logs.' );
        }
        echo '</table>';
        echo '</div>';
    }

    private static function render_logs_tab( $last_push_result, $sender_debug_log_history ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="logs" id="xlocal-panel-logs" role="tabpanel" aria-labelledby="xlocal-tab-logs">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Logs</h2>';
        echo '<p>Recent diagnostics and last sender response.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Last Push Result</th><td>';
        printf( '<textarea readonly rows="6">%s</textarea>', esc_textarea( $last_push_result ) );
        echo '</td></tr>';
        echo '<tr><th scope="row">Sender Debug Log</th><td>';
        printf( '<textarea readonly rows="12">%s</textarea>', esc_textarea( $sender_debug_log_history ) );
        $clear_debug_logs_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_clear_sender_debug_logs' ), 'xlocal_clear_sender_debug_logs' );
        echo '<p><a href="' . esc_url( $clear_debug_logs_url ) . '" class="button button-secondary">Clear Sender Debug Log</a></p>';
        echo '<p class="xlocal-field-hint">Enable "Sender Debug Logs" in Advanced tab to collect timestamped sender transport/debug entries.</p>';
        echo '</td></tr>';
        if ( class_exists( 'Xlocal_Bridge_Updater' ) ) {
            $snapshot = Xlocal_Bridge_Updater::status_snapshot();
            $cached_version = ! empty( $snapshot['cached_version'] ) ? $snapshot['cached_version'] : '-';
            $cached_commit = ! empty( $snapshot['cached_commit'] ) ? substr( $snapshot['cached_commit'], 0, 12 ) : '-';
            $installed_commit = ! empty( $snapshot['installed_commit'] ) ? substr( $snapshot['installed_commit'], 0, 12 ) : '-';
            $update_available = ! empty( $snapshot['update_available'] );
            $new_version = ! empty( $snapshot['new_version'] ) ? $snapshot['new_version'] : '';
            $action_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_check_updates_now' ), 'xlocal_check_updates_now' );

            echo '<tr><th scope="row">Updater Status</th><td>';
            echo '<p>Installed Commit: <code>' . esc_html( $installed_commit ) . '</code></p>';
            echo '<p>Latest Cached Commit: <code>' . esc_html( $cached_commit ) . '</code>, Version: <code>' . esc_html( $cached_version ) . '</code></p>';
            if ( $update_available ) {
                $plugin_file = Xlocal_Bridge_Updater::plugin_file();
                $upgrade_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_update_plugin_now' ), 'xlocal_update_plugin_now' );
                echo '<p><strong>Update Available:</strong> <code>' . esc_html( $new_version ) . '</code></p>';
                echo '<p><a href="' . esc_url( $upgrade_url ) . '" class="button button-primary">Update Plugin Now</a></p>';
            } else {
                echo '<p><strong>Update Available:</strong> <code>No</code></p>';
            }
            echo '<p><a href="' . esc_url( $action_url ) . '" class="button button-secondary">Check Latest Updates Now</a></p>';
            echo '<p class="xlocal-field-hint">Forces GitHub + WordPress update refresh and returns to this page with status notice.</p>';
            echo '</td></tr>';
        }
        echo '</table>';
        echo '</div>';
    }

    private static function render_bulk_send_tab( $options ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="bulk_send" id="xlocal-panel-bulk_send" role="tabpanel" aria-labelledby="xlocal-tab-bulk_send">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Bulk Send</h2>';
        echo '<p>Premium backfill flow for safely sending older posts from this sender site to your main site.</p>';
        echo '</div>';

        $mode = isset( $options['mode'] ) ? $options['mode'] : 'both';

        if ( $mode === 'receiver' ) {
            echo '<p><strong>Bulk Send only runs on Sender sites.</strong> Set this site to Sender or Both in the Overview tab to enable.</p>';
            echo '</div>';
            return;
        }

        $bulk_post_type  = isset( $options['bulk_post_type'] ) ? sanitize_key( $options['bulk_post_type'] ) : $options['sender_target_post_type'];
        $bulk_status     = isset( $options['bulk_status'] ) ? sanitize_key( $options['bulk_status'] ) : 'publish';
        $bulk_date_after = isset( $options['bulk_date_after'] ) ? sanitize_text_field( $options['bulk_date_after'] ) : '';
        $bulk_batch_size = isset( $options['bulk_batch_size'] ) ? intval( $options['bulk_batch_size'] ) : 25;

        $allowed_statuses = array( 'publish', 'pending', 'draft' );
        if ( ! in_array( $bulk_status, $allowed_statuses, true ) ) {
            $bulk_status = 'publish';
        }

        if ( $bulk_batch_size < 1 ) {
            $bulk_batch_size = 25;
        }

        echo '<p class="xlocal-note"><strong>Confidence:</strong> Bulk Send runs oldest-first and only selects unsent posts, so each run advances deterministically. This makes it ideal for testing with batch size <code>1</code>.</p>';

        // Nonce stays inside main options form so Bulk Send can post to admin-post safely.
        wp_nonce_field( 'xlocal_bulk_send', 'xlocal_bulk_send_nonce' );
        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row">Current Sender Defaults</th>';
        echo '<td>';
        echo '<p>Post type: <code>' . esc_html( $options['sender_target_post_type'] ) . '</code></p>';
        echo '<p>Status to send: <code>' . esc_html( $options['sender_default_status'] ) . '</code></p>';
        echo '<p class="xlocal-field-hint">These are your normal sender settings. Bulk Send filters and sends based on your selection below.</p>';
        echo '</td><td class="xlocal-help-cell"></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Bulk Post Type</th>';
        echo '<td>';
        printf(
            '<input type="text" name="%s[bulk_post_type]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_post_type )
        );
        echo '<p class="xlocal-field-hint">Usually keep this the same as Sender Target Post Type.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Only posts of this type are selected and sent in this run.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Bulk Status</th>';
        echo '<td>';
        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[bulk_status]">';
        foreach ( $allowed_statuses as $status ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $status ),
                selected( $bulk_status, $status, false ),
                esc_html( ucfirst( $status ) )
            );
        }
        echo '</select>';
        echo '<p class="xlocal-field-hint">Recommended: publish. Only posts with this status will be sent.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Use publish for live content, or pending/draft if you only want staged items.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Only Posts After Date</th>';
        echo '<td>';
        printf(
            '<input type="date" name="%s[bulk_date_after]" value="%s" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_date_after )
        );
        echo '<p class="xlocal-field-hint">Optional: limit Bulk Send to posts newer than this date.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Leave empty to include all matching posts, or set a cut-off date for safer rollout.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Batch Size</th>';
        echo '<td>';
        printf(
            '<input type="number" name="%s[bulk_batch_size]" value="%d" class="small-text" min="1" max="200" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_batch_size )
        );
        echo '<p class="xlocal-field-hint">How many posts to process in one Bulk Send run. For testing, start with <code>1</code>.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Re-run as needed. Each run picks the next oldest unsent posts that match the filters.</span></span></td>';
        echo '</tr>';

        echo '</table>';

        // Use the main settings form but override action when running Bulk Send.
        $action_url = admin_url( 'admin-post.php?action=xlocal_bulk_send_run' );
        printf(
            '<button type="submit" name="action" value="xlocal_bulk_send_run" class="button button-secondary" formaction="%s">Run Bulk Send</button>',
            esc_url( $action_url )
        );

        echo '</div>';
    }

    private static function render_documentation_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="documentation" id="xlocal-panel-documentation" role="tabpanel" aria-labelledby="xlocal-tab-documentation">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Documentation</h2>';
        echo '<p>Simple guide for teams that want reliable post sync without technical setup complexity.</p>';
        echo '</div>';

        echo '<div class="xlocal-doc">';

        echo '<section class="xlocal-doc-hero">';
        echo '<h3>Start Here: 10-Minute Guided Setup</h3>';
        echo '<p>Follow this exact order once, then your team can publish normally. This tutorial is written for non-technical users.</p>';
        echo '<div class="xlocal-doc-pill-row">';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-tutorial">Step-by-step</button>';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-examples">Real examples</button>';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-troubleshooting">Troubleshooting included</button>';
        echo '</div>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>1) What this plugin does (in plain words)</h3>';
        echo '<p>This plugin sends posts from your Sender website to your Receiver website using a secure connection.</p>';
        echo '<p>Use it when content is created on one WordPress site but must appear on another site automatically.</p>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-tutorial">';
        echo '<h3>2) Guided tutorial: exact actions</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Open WordPress Admin on your destination site and set Mode to <strong>Receiver</strong>.</li>';
        echo '<li>Enable Receiver, keep Require TLS ON, then set Shared Secret (same secret must be used on sender).</li>';
        echo '<li>Fill Allowed Media Domains with your CDN host. Example: <code>cdn.example.com</code>.</li>';
        echo '<li>Save changes.</li>';
        echo '<li>Open WordPress Admin on your source site and set Mode to <strong>Sender</strong>.</li>';
        echo '<li>Turn ON Auto Send on Publish/Update.</li>';
        echo '<li>Main Site Base URL: use destination site URL. Example: <code>https://main.example.com</code>.</li>';
        echo '<li>Ingest Path: keep default unless instructed: <code>/wp-json/xlocal/v1/ingest</code>.</li>';
        echo '<li>Shared Secret: paste exactly the same secret from receiver.</li>';
        echo '<li>Save changes, then click <strong>Send Test Payload</strong>. Check Logs tab for response code 200.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-examples">';
        echo '<h3>3) Field examples you can copy</h3>';
        echo '<table class="xlocal-doc-table">';
        echo '<thead><tr><th>Field</th><th>Example Value</th><th>Where</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td>Main Site Base URL</td><td><code>https://main.example.com</code></td><td>Sender</td></tr>';
        echo '<tr><td>Ingest Endpoint Path</td><td><code>/wp-json/xlocal/v1/ingest</code></td><td>Sender</td></tr>';
        echo '<tr><td>Shared Secret</td><td><code>n7A!pQ9m@4Kz#1wL2xD8rT6y</code></td><td>Sender + Receiver</td></tr>';
        echo '<tr><td>Allowed Media Domains</td><td><code>cdn.example.com</code><br/><code>img.examplecdn.net</code></td><td>Receiver</td></tr>';
        echo '<tr><td>Target Post Type</td><td><code>post</code></td><td>Sender</td></tr>';
        echo '<tr><td>Default Status</td><td><code>pending</code></td><td>Sender/Receiver</td></tr>';
        echo '<tr><td>Rate Limit</td><td><code>60</code></td><td>Receiver</td></tr>';
        echo '<tr><td>Max Payload Size (KB)</td><td><code>512</code></td><td>Sender/Receiver</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>4) Recommended settings (safe defaults)</h3>';
        echo '<ul class="xlocal-doc-list">';
        echo '<li>Keep <strong>Require TLS</strong> enabled.</li>';
        echo '<li>Keep <strong>Enable HTML Sanitization</strong> enabled.</li>';
        echo '<li>Keep <strong>Reject Non-Allowed media</strong> enabled and fill Allowed Media Domains.</li>';
        echo '<li>Use <strong>pending</strong> as default status until your team confirms the flow.</li>';
        echo '<li>Enable <strong>Auto Send on Publish/Update</strong> only after test payload is successful.</li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>5) How daily use works</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Editor publishes or updates a post on Sender site.</li>';
        echo '<li>Plugin creates a secure payload and sends it to Receiver site.</li>';
        echo '<li>Receiver verifies signature, validates media domain, sanitizes HTML, then creates or updates post.</li>';
        echo '<li>You can review the latest response in the Logs tab.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-troubleshooting">';
        echo '<h3>6) If posts are not arriving</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Check Sender Mode and Receiver Mode are correct.</li>';
        echo '<li>Confirm Shared Secret matches exactly on both sites.</li>';
        echo '<li>Confirm Sender Base URL points to Receiver site (not same site).</li>';
        echo '<li>Use Send Test Payload and read Logs tab response.</li>';
        echo '<li>If using Batch mode, ensure WordPress cron is running on server.</li>';
        echo '<li>If media is blocked, add your CDN domain to Allowed Media Domains on Receiver.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>7) Security notes for managers</h3>';
        echo '<ul class="xlocal-doc-list">';
        echo '<li>Shared Secret is the main lock. Change it immediately if leaked.</li>';
        echo '<li>Only trusted admins should access plugin settings.</li>';
        echo '<li>Use HTTPS on both websites.</li>';
        echo '<li>Optional: use IP allowlist if your hosting network is stable.</li>';
        echo '<li>Do not disable sanitization unless your team fully understands HTML security risk.</li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>8) Suggested launch plan</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Week 1: Use Test Payload only.</li>';
        echo '<li>Week 2: Enable Auto Send for one post type with status pending.</li>';
        echo '<li>Week 3: Move to publish status after editorial review passes.</li>';
        echo '<li>Week 4: Enable Batch or Immediate mode based on your traffic pattern.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>FAQ (quick answers)</h3>';
        echo '<details class="xlocal-doc-faq"><summary>What if I do not know my CDN domain?</summary><p>Ask your hosting/CDN provider and paste only the hostname (without path), for example <code>cdn.example.com</code>.</p></details>';
        echo '<details class="xlocal-doc-faq"><summary>Can I set publish directly?</summary><p>Yes, but start with <code>pending</code> first, confirm quality, then switch to <code>publish</code>.</p></details>';
        echo '<details class="xlocal-doc-faq"><summary>Do I need Batch mode?</summary><p>Use Immediate for low volume. Use Batch if you publish many posts and want controlled periodic sending.</p></details>';
        echo '</section>';

        echo '</div>';
        echo '</div>';
    }

    private static function render_field( $key, $label, $type, $help = '' ) {
        $options = self::get_options();
        $value = isset( $options[ $key ] ) ? $options[ $key ] : '';
        $placeholder = self::get_field_placeholder( $key );
        $placeholder_attr = $placeholder !== '' ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
        echo '<tr>'; 
        printf( '<th scope="row">%s</th>', esc_html( $label ) );
        echo '<td>';
        switch ( $type ) {
            case 'checkbox':
                printf( '<label class="xlocal-toggle"><input type="checkbox" name="%s[%s]" value="1" %s /><span></span></label>', esc_attr( self::OPTION_KEY ), esc_attr( $key ), checked( $value, 1, false ) );
                break;
            case 'textarea':
                printf( '<textarea name="%s[%s]" rows="4" cols="50"%s>%s</textarea>', esc_attr( self::OPTION_KEY ), esc_attr( $key ), $placeholder_attr, esc_textarea( $value ) );
                break;
            case 'number':
                printf( '<input type="number" name="%s[%s]" value="%s" class="small-text"%s />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ), $placeholder_attr );
                break;
            case 'password':
                printf( '<input type="password" name="%s[%s]" value="%s"%s />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ), $placeholder_attr );
                break;
            case 'select_status':
                $statuses = array( 'publish', 'pending', 'draft' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $statuses as $status ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $status ), selected( $value, $status, false ), esc_html( ucfirst( $status ) ) );
                }
                echo '</select>';
                break;
            case 'select_author_mode':
                $modes = array(
                    'fixed_author' => 'Fixed Author',
                    'by_name' => 'By Name',
                    'by_email' => 'By Email',
                    'random_editor' => 'Random Editor (safe roles)',
                );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_featured_mode':
                $modes = array( 'meta_only' => 'Meta Only', 'virtual_attachment' => 'Virtual Attachment' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_dedup':
                $modes = array( 'source_url' => 'source_url', 'source_hash+source_url' => 'source_hash + source_url' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_update_strategy':
                $modes = array( 'overwrite_all' => 'Overwrite All', 'preserve_manual_edits' => 'Preserve Manual Edits' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_allowed_profile':
                $profiles = array( 'strict' => 'Strict', 'standard' => 'Standard', 'custom' => 'Custom' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $profiles as $profile => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $profile ), selected( $value, $profile, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_log_storage':
                $modes = array( 'db_table' => 'DB Table', 'postmeta' => 'Post Meta' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_image_format':
                $formats = array( 'webp' => 'WebP', 'avif' => 'AVIF', 'original' => 'Original' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $formats as $format => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $format ), selected( $value, $format, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_sync_mode':
                $modes = array( 'immediate' => 'Immediate', 'batch' => 'Batch' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $modes as $mode => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $mode ), selected( $value, $mode, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            case 'select_schedule':
                $intervals = array( 'five_minutes' => 'Every 5 minutes', 'fifteen_minutes' => 'Every 15 minutes', 'hourly' => 'Hourly' );
                echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']">';
                foreach ( $intervals as $interval => $label_item ) {
                    printf( '<option value="%s" %s>%s</option>', esc_attr( $interval ), selected( $value, $interval, false ), esc_html( $label_item ) );
                }
                echo '</select>';
                break;
            default:
                printf( '<input type="text" name="%s[%s]" value="%s" class="regular-text"%s />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ), $placeholder_attr );
                break;
        }

        $example = self::get_field_example( $key );
        if ( $example !== '' ) {
            printf( '<p class="xlocal-field-hint">%s</p>', esc_html( $example ) );
        }

        if ( $help ) {
            echo '</td>';
            printf( '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">%s</span></span></td>', esc_html( $help ) );
            echo '</tr>';
            return;
        }
        echo '</td><td class="xlocal-help-cell"></td></tr>';
    }

    private static function get_field_placeholder( $key ) {
        $placeholders = array(
            'receiver_secret' => 'Use same secret as sender',
            'receiver_clock_skew' => '300',
            'receiver_nonce_ttl' => '600',
            'receiver_max_payload_kb' => '512',
            'receiver_ip_allowlist' => "203.0.113.10\n198.51.100.21",
            'receiver_rate_limit' => '60',
            'receiver_default_post_type' => 'post',
            'receiver_fixed_author_id' => '2',
            'receiver_allowed_media_domains' => "cdn.example.com\nimg.examplecdn.net",
            'receiver_source_url_meta_key' => '_xlocal_source_url',
            'receiver_category_mapping_rules' => "News -> Updates\nSports -> Match Reports",
            'receiver_custom_allowed' => '{"p":[],"a":{"href":true},"img":{"src":true,"alt":true}}',
            'receiver_retain_logs_days' => '30',
            'sender_main_base_url' => 'https://main.example.com',
            'sender_ingest_path' => '/wp-json/xlocal/v1/ingest',
            'sender_secret' => 'Use same secret as receiver',
            'sender_timeout' => '15',
            'sender_max_retries' => '3',
            'sender_backoff_base_ms' => '500',
            'sender_max_payload_kb' => '512',
            'sender_target_post_type' => 'post',
            'sender_author_name' => 'editor',
            'sender_cdn_base' => 'https://cdn.example.com',
            'sender_batch_size' => '10',
        );
        return isset( $placeholders[ $key ] ) ? $placeholders[ $key ] : '';
    }

    private static function get_field_example( $key ) {
        $examples = array(
            'receiver_secret' => 'Example: use 24+ random characters and match it exactly on sender site.',
            'receiver_allowed_media_domains' => 'Example: one domain per line, without https:// and without /path.',
            'receiver_ip_allowlist' => 'Example: leave blank to allow all IPs, or add one IP per line for strict mode.',
            'sender_main_base_url' => 'Example: destination site URL, not this sender site URL.',
            'sender_ingest_path' => 'Usually keep default unless your receiver endpoint is customized.',
            'sender_cdn_base' => 'Example: must match your final image host if CDN enforcement is enabled.',
            'sender_auto_send' => 'Recommended: enable after Send Test Payload succeeds.',
            'sender_sync_mode' => 'Immediate = real-time. Batch = scheduled queue processing.',
            'sender_dry_run' => 'Useful for testing configuration without sending data.',
            'receiver_default_status' => 'Recommended for onboarding: pending, then move to publish after review.',
        );
        return isset( $examples[ $key ] ) ? $examples[ $key ] : '';
    }

    private static function render_status_badges( $options ) {
        $badges = array();
        $mode = $options['mode'];
        $badges[] = array( 'label' => 'Mode: ' . ucfirst( $mode ), 'state' => 'ok' );
        if ( class_exists( 'Xlocal_Bridge_Updater' ) ) {
            $updater_ok = Xlocal_Bridge_Updater::is_repo_configured();
            $updater_channel = Xlocal_Bridge_Updater::channel_label();
            $badges[] = array(
                'label' => $updater_ok ? 'Updater: Configured (' . $updater_channel . ')' : 'Updater: GitHub Repo Missing',
                'state' => $updater_ok ? 'ok' : 'warn',
            );
        }

        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            $badges[] = array( 'label' => $options['receiver_enabled'] ? 'Receiver Enabled' : 'Receiver Disabled', 'state' => $options['receiver_enabled'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'Receiver Secret Set' : 'Receiver Secret Missing', 'state' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['receiver_allowed_media_domains'] ? 'CDN Allowlist Set' : 'CDN Allowlist Missing', 'state' => $options['receiver_allowed_media_domains'] ? 'ok' : 'warn' );
        }

        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $badges[] = array( 'label' => $options['sender_auto_send'] ? 'Sender Auto Send Enabled' : 'Sender Auto Send Disabled', 'state' => $options['sender_auto_send'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['sender_main_base_url'] ? 'Sender Endpoint Set' : 'Sender Endpoint Missing', 'state' => $options['sender_main_base_url'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => Xlocal_Bridge_Settings::get_sender_secret() ? 'Sender Secret Set' : 'Sender Secret Missing', 'state' => Xlocal_Bridge_Settings::get_sender_secret() ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['sender_cdn_base'] ? 'CDN Base Set' : 'CDN Base Missing', 'state' => $options['sender_cdn_base'] ? 'ok' : 'warn' );
        }

        echo '<div class="xlocal-status-row">';
        foreach ( $badges as $badge ) {
            $class = $badge['state'] === 'ok' ? 'xlocal-status xlocal-status-ok' : 'xlocal-status xlocal-status-warn';
            printf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $badge['label'] ) );
        }
        echo '</div>';
    }

    public static function handle_test_payload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xlocal_sender_test' );
        $options = self::get_options();
        $endpoint = rtrim( $options['sender_main_base_url'], '/' ) . $options['sender_ingest_path'];
        $secret = self::get_sender_secret();
        if ( ! wp_http_validate_url( $endpoint ) || empty( $secret ) ) {
            self::set_notice( 'Missing main endpoint or secret.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }
        $payload = array(
            'ingest_id' => wp_generate_uuid4(),
            'source_url' => 'https://example.com/test-article',
            'title' => 'xLocal Test Payload',
            'content_html' => '<p>This is a test payload.</p>',
            'status' => $options['sender_default_status'],
        );
        $result = Xlocal_Bridge_Sender::send_payload( $endpoint, $secret, $payload, $options );
        if ( is_wp_error( $result ) ) {
            self::set_notice( $result->get_error_message(), 'error' );
        } else {
            self::set_notice( 'Test payload sent. Response code: ' . intval( $result['code'] ), 'success' );
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
        exit;
    }

    public static function handle_bulk_send() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $valid_nonce = false;
        if ( isset( $_POST['xlocal_bulk_send_nonce'] ) && wp_verify_nonce( $_POST['xlocal_bulk_send_nonce'], 'xlocal_bulk_send' ) ) {
            $valid_nonce = true;
        }
        // Backward compatibility for older button/action payloads.
        if ( isset( $_POST['xlocal_bulk_import_nonce'] ) && wp_verify_nonce( $_POST['xlocal_bulk_import_nonce'], 'xlocal_bulk_import' ) ) {
            $valid_nonce = true;
        }
        if ( ! $valid_nonce ) {
            self::set_notice( 'Bulk Send security check failed (invalid nonce). Please refresh the settings page and try again.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $options = self::get_options();

        if ( $options['mode'] === 'receiver' ) {
            self::set_notice( 'Bulk Send is only available on Sender sites.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $endpoint = rtrim( $options['sender_main_base_url'], '/' ) . $options['sender_ingest_path'];
        $secret   = self::get_sender_secret();
        if ( ! wp_http_validate_url( $endpoint ) || empty( $secret ) ) {
            self::set_notice( 'Bulk Send requires a valid sender endpoint and secret.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $input = isset( $_POST[ self::OPTION_KEY ] ) && is_array( $_POST[ self::OPTION_KEY ] ) ? $_POST[ self::OPTION_KEY ] : array();

        $bulk_post_type  = isset( $input['bulk_post_type'] ) ? sanitize_key( $input['bulk_post_type'] ) : $options['sender_target_post_type'];
        $bulk_status     = isset( $input['bulk_status'] ) ? sanitize_key( $input['bulk_status'] ) : 'publish';
        $bulk_date_after = isset( $input['bulk_date_after'] ) ? sanitize_text_field( $input['bulk_date_after'] ) : '';
        $bulk_batch_size = isset( $input['bulk_batch_size'] ) ? intval( $input['bulk_batch_size'] ) : 25;

        $allowed_statuses = array( 'publish', 'pending', 'draft' );
        if ( ! in_array( $bulk_status, $allowed_statuses, true ) ) {
            $bulk_status = 'publish';
        }

        if ( $bulk_batch_size < 1 ) {
            $bulk_batch_size = 25;
        } elseif ( $bulk_batch_size > 200 ) {
            $bulk_batch_size = 200;
        }

        // Persist last used bulk settings for admin convenience.
        $options['bulk_post_type']  = $bulk_post_type;
        $options['bulk_status']     = $bulk_status;
        $options['bulk_date_after'] = $bulk_date_after;
        $options['bulk_batch_size'] = $bulk_batch_size;
        update_option( self::OPTION_KEY, $options );

        $query_args = array(
            'post_type'      => $bulk_post_type,
            'post_status'    => $bulk_status,
            'posts_per_page' => $bulk_batch_size,
            'orderby'        => array(
                'date' => 'ASC',
                'ID'   => 'ASC',
            ),
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => Xlocal_Bridge_Sender::META_REMOTE_INGEST,
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => Xlocal_Bridge_Sender::META_REMOTE_SOURCE,
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => Xlocal_Bridge_Sender::META_SENT_HASH,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => Xlocal_Bridge_Sender::META_SENT_HASH,
                        'value'   => '',
                        'compare' => '=',
                    ),
                ),
            ),
        );

        if ( $bulk_date_after ) {
            $query_args['date_query'] = array(
                array(
                    'after'     => $bulk_date_after,
                    'inclusive' => true,
                ),
            );
        }

        $query = new WP_Query( $query_args );

        $processed        = 0;
        $sent             = 0;
        $skipped_same     = 0;
        $skipped_remote   = 0;
        $skipped_post_type = 0;
        $skipped_status   = 0;
        $errors           = 0;

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post instanceof WP_Post ) {
                    continue;
                }

                $processed++;

                $result = Xlocal_Bridge_Sender::bulk_send_post( $post, $options, $bulk_status, $bulk_post_type );
                switch ( $result ) {
                    case 'sent':
                        $sent++;
                        break;
                    case 'skipped_same_hash':
                        $skipped_same++;
                        break;
                    case 'skipped_remote':
                        $skipped_remote++;
                        break;
                    case 'skipped_post_type':
                        $skipped_post_type++;
                        break;
                    case 'skipped_status':
                        $skipped_status++;
                        break;
                    default:
                        $errors++;
                        break;
                }
            }
        }

        if ( $processed === 0 ) {
            self::set_notice( 'Bulk Send found no eligible unsent posts for the selected filters. Tip: set Batch Size to 1 for controlled testing.', 'success' );
        } else {
            $message = sprintf(
                'Bulk Send processed %d posts. Sent: %d, Skipped same content: %d, Skipped remote-sourced: %d, Skipped wrong post type: %d, Skipped wrong status: %d, Errors: %d. Runs are deterministic: oldest unsent posts are selected first.',
                $processed,
                $sent,
                $skipped_same,
                $skipped_remote,
                $skipped_post_type,
                $skipped_status,
                $errors
            );
            self::set_notice( $message, $errors > 0 ? 'error' : 'success' );
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
        exit;
    }

    public static function handle_bulk_import() {
        self::handle_bulk_send();
    }

    public static function maybe_handle_bulk_send_from_update_route() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $is_bulk_send = false;
        if ( isset( $_POST['action'] ) && sanitize_key( (string) $_POST['action'] ) === 'xlocal_bulk_send_run' ) {
            $is_bulk_send = true;
        }
        if ( isset( $_POST['xlocal_bulk_send_nonce'] ) || isset( $_POST['xlocal_bulk_import_nonce'] ) ) {
            $is_bulk_send = true;
        }
        if ( ! $is_bulk_send ) {
            return;
        }
        self::handle_bulk_send();
    }

    public static function handle_check_updates_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xlocal_check_updates_now' );

        if ( ! class_exists( 'Xlocal_Bridge_Updater' ) ) {
            self::set_notice( 'Updater class is not loaded.', 'error' );
            wp_safe_redirect( add_query_arg( 'xlocal_tab', 'logs', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
            exit;
        }

        $snapshot = Xlocal_Bridge_Updater::force_refresh();
        $cached_commit = ! empty( $snapshot['cached_commit'] ) ? substr( $snapshot['cached_commit'], 0, 12 ) : '-';
        $cached_version = ! empty( $snapshot['cached_version'] ) ? $snapshot['cached_version'] : '-';

        self::set_notice(
            sprintf(
                'Update refresh complete. Latest commit: %s, Version: %s. Check Plugins page for available update.',
                $cached_commit,
                $cached_version
            ),
            'success'
        );
        wp_safe_redirect( add_query_arg( 'xlocal_tab', 'logs', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
        exit;
    }

    public static function handle_update_plugin_now() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xlocal_update_plugin_now' );

        if ( ! class_exists( 'Xlocal_Bridge_Updater' ) ) {
            self::set_notice( 'Updater class is not loaded.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $snapshot = Xlocal_Bridge_Updater::force_refresh();
        if ( ! empty( $snapshot['cached_commit'] ) ) {
            Xlocal_Bridge_Updater::set_pending_commit( $snapshot['cached_commit'] );
        } else {
            Xlocal_Bridge_Updater::set_pending_commit( '' );
        }

        $plugin_file = Xlocal_Bridge_Updater::plugin_file();
        $upgrade_url = wp_nonce_url(
            self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $plugin_file ) ),
            'upgrade-plugin_' . $plugin_file
        );
        wp_safe_redirect( $upgrade_url );
        exit;
    }

    public static function handle_clear_sender_debug_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xlocal_clear_sender_debug_logs' );

        $options = self::get_options();
        $options['sender_debug_log_history'] = '';
        update_option( self::OPTION_KEY, $options );

        self::set_notice( 'Sender debug log cleared.', 'success' );
        wp_safe_redirect( add_query_arg( 'xlocal_tab', 'logs', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
        exit;
    }

    private static function set_notice( $message, $type ) {
        set_transient( 'xlocal_sender_notice', array( 'message' => $message, 'type' => $type ), 30 );
    }

    public static function admin_notices() {
        $notice = get_transient( 'xlocal_sender_notice' );
        if ( ! $notice ) {
            return;
        }
        delete_transient( 'xlocal_sender_notice' );
        $class = $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
        printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), esc_html( $notice['message'] ) );
    }
}
