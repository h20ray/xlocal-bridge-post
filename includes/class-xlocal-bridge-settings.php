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
            'receiver_rate_limit' => 0,
            'receiver_require_tls' => 1,
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
            'sender_timeout' => 15,
            'sender_max_retries' => 3,
            'sender_backoff_base_ms' => 500,
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
        foreach ( $defaults as $key => $value ) {
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
                if ( strpos( $key, 'allowlist' ) !== false || strpos( $key, 'mapping_rules' ) !== false || strpos( $key, 'custom_allowed' ) !== false ) {
                    $output[ $key ] = sanitize_textarea_field( $raw );
                } else {
                    $output[ $key ] = sanitize_text_field( $raw );
                }
                continue;
            }
            $output[ $key ] = $raw;
        }
        return array_merge( $defaults, $output );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'settings_page_xlocal-bridge-post' ) {
            return;
        }
        $base_url = plugin_dir_url( __FILE__ );
        $base_url = str_replace( '/includes', '', $base_url );
        wp_enqueue_style( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.css', array(), '0.3.0' );
        wp_enqueue_script( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.js', array(), '0.3.0', true );
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
            'receiver' => array(
                'label' => 'Receiver',
                'desc' => 'Security, content policy, and media rules for ingest.',
            ),
            'sender' => array(
                'label' => 'Sender',
                'desc' => 'Endpoint, publishing, and CDN output rules.',
            ),
            'advanced' => array(
                'label' => 'Advanced',
                'desc' => 'Sanitization, dedup, and logging controls.',
            ),
            'logs' => array(
                'label' => 'Logs',
                'desc' => 'Diagnostics and last push result.',
            ),
        );

        echo '<div class="wrap xlocal-admin">';
        echo '<div class="xlocal-hero">';
        echo '<div class="xlocal-hero-text">';
        echo '<span class="xlocal-chip">Bridge</span>';
        echo '<h1>xLocal Bridge Post</h1>';
        echo '<p>Unified Sender + Receiver settings with premium controls and clarity.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="xlocal-layout">';
        echo '<form method="post" action="options.php" class="xlocal-card xlocal-elevated">';
        settings_fields( 'xlocal_bridge_post' );

        echo '<div class="xlocal-tabs" role="tablist">';
        foreach ( $tabs as $id => $tab ) {
            printf( '<button type="button" class="xlocal-tab" data-tab="%s" role="tab">%s</button>', esc_attr( $id ), esc_html( $tab['label'] ) );
        }
        echo '</div>';

        self::render_overview_tab( $mode, $options );
        self::render_receiver_tab();
        self::render_sender_tab();
        self::render_advanced_tab();
        self::render_logs_tab( $options['sender_last_push_result'] );

        submit_button();
        echo '</form>';

        echo '<div class="xlocal-card xlocal-side">';
        echo '<h2>Status</h2>';
        self::render_status_badges( $options );
        echo '<h2>Quick Checklist</h2>';
        echo '<ul>';
        echo '<li>Mode set correctly for this site.</li>';
        echo '<li>Secrets match between sender and receiver.</li>';
        echo '<li>CDN domain allowlisted on receiver.</li>';
        echo '<li>Test payload succeeds.</li>';
        echo '</ul>';
        echo '<div class="xlocal-note"><strong>Tip:</strong> Use <code>wp-config.php</code> to lock the shared secret.</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private static function render_overview_tab( $mode, $options ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="overview">';
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
    }

    private static function render_receiver_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="receiver">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Receiver</h2>';
        echo '<p>Security and content rules for ingesting posts.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        self::render_field( 'receiver_enabled', 'Enable Receiver', 'checkbox', 'Toggle ingest endpoint.' );
        self::render_field( 'receiver_secret', 'Shared Secret', 'password', 'HMAC secret. Must match sender.' );
        self::render_field( 'receiver_clock_skew', 'Allowed Clock Skew (seconds)', 'number', 'Default 300 seconds.' );
        self::render_field( 'receiver_nonce_ttl', 'Nonce TTL (seconds)', 'number', 'Default 600 seconds.' );
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
        echo '<div class="xlocal-tab-panel" data-tab-panel="sender">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Sender</h2>';
        echo '<p>Main endpoint, publishing defaults, and CDN output rules.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        self::render_field( 'sender_main_base_url', 'Main Site Base URL', 'text', 'Example: https://www.example.com' );
        self::render_field( 'sender_ingest_path', 'Ingest Endpoint Path', 'text', 'Default /wp-json/xlocal/v1/ingest' );
        self::render_field( 'sender_secret', 'Shared Secret', 'password', 'Must match receiver.' );
        self::render_field( 'sender_timeout', 'Request Timeout (seconds)', 'number', 'Default 15 seconds.' );
        self::render_field( 'sender_max_retries', 'Max Retries', 'number', 'Default 3 retries.' );
        self::render_field( 'sender_backoff_base_ms', 'Backoff Base (ms)', 'number', 'Default 500ms.' );
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
        echo '</div>';
    }

    private static function render_advanced_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="advanced">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Advanced</h2>';
        echo '<p>Deduplication, sanitization, and logging controls.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
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
        self::render_field( 'sender_debug_logs', 'Sender Debug Logs', 'checkbox', 'Store detailed sender logs.' );
        echo '</table>';
        echo '</div>';
    }

    private static function render_logs_tab( $last_push_result ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="logs">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Logs</h2>';
        echo '<p>Recent diagnostics and last sender response.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Last Push Result</th><td>';
        printf( '<textarea readonly rows="6">%s</textarea>', esc_textarea( $last_push_result ) );
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';
    }

    private static function render_field( $key, $label, $type, $help = '' ) {
        $options = self::get_options();
        $value = isset( $options[ $key ] ) ? $options[ $key ] : '';
        echo '<tr>'; 
        printf( '<th scope="row">%s</th>', esc_html( $label ) );
        echo '<td>';
        switch ( $type ) {
            case 'checkbox':
                printf( '<label class="xlocal-toggle"><input type="checkbox" name="%s[%s]" value="1" %s /><span></span></label>', esc_attr( self::OPTION_KEY ), esc_attr( $key ), checked( $value, 1, false ) );
                break;
            case 'textarea':
                printf( '<textarea name="%s[%s]" rows="4" cols="50">%s</textarea>', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_textarea( $value ) );
                break;
            case 'number':
                printf( '<input type="number" name="%s[%s]" value="%s" class="small-text" />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ) );
                break;
            case 'password':
                printf( '<input type="password" name="%s[%s]" value="%s" />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ) );
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
                $modes = array( 'fixed_author' => 'Fixed Author', 'by_name' => 'By Name', 'by_email' => 'By Email' );
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
                printf( '<input type="text" name="%s[%s]" value="%s" class="regular-text" />', esc_attr( self::OPTION_KEY ), esc_attr( $key ), esc_attr( $value ) );
                break;
        }
        if ( $help ) {
            echo '</td>';
            printf( '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">%s</span></span></td>', esc_html( $help ) );
            echo '</tr>';
            return;
        }
        echo '</td><td class="xlocal-help-cell"></td></tr>';
    }

    private static function render_status_badges( $options ) {
        $badges = array();
        $mode = $options['mode'];
        $badges[] = array( 'label' => 'Mode: ' . ucfirst( $mode ), 'state' => 'ok' );

        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            $badges[] = array( 'label' => $options['receiver_enabled'] ? 'Receiver Enabled' : 'Receiver Disabled', 'state' => $options['receiver_enabled'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'Receiver Secret Set' : 'Receiver Secret Missing', 'state' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['receiver_allowed_media_domains'] ? 'CDN Allowlist Set' : 'CDN Allowlist Missing', 'state' => $options['receiver_allowed_media_domains'] ? 'ok' : 'warn' );
        }

        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
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
        if ( empty( $endpoint ) || empty( $secret ) ) {
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
