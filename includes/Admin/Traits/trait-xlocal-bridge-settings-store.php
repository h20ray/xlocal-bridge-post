<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Store_Trait {
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
            'receiver_debug_log_history' => '',
            'receiver_prepend_featured_if_missing' => 1,

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
            'sender_debug_payload_history' => '',
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
            'receiver_prepend_featured_if_missing',
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
                if ( strpos( $key, 'allowlist' ) !== false || strpos( $key, 'mapping_rules' ) !== false || strpos( $key, 'custom_allowed' ) !== false || strpos( $key, 'debug_log_history' ) !== false || strpos( $key, 'debug_payload_history' ) !== false ) {
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

        $existing = self::get_options();
        $runtime_keys = array(
            'sender_last_push_result',
            'sender_debug_log_history',
            'sender_debug_payload_history',
            'receiver_debug_log_history',
        );
        foreach ( $runtime_keys as $runtime_key ) {
            if ( ! isset( $input[ $runtime_key ] ) && isset( $existing[ $runtime_key ] ) ) {
                $merged[ $runtime_key ] = $existing[ $runtime_key ];
            }
        }

        return $merged;
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'settings_page_xlocal-bridge-post' ) {
            return;
        }
        $base_url = plugin_dir_url( __FILE__ );
        $base_url = str_replace( '/includes', '', $base_url );
        $asset_version = defined( 'XLOCAL_BRIDGE_POST_VERSION' ) ? XLOCAL_BRIDGE_POST_VERSION : '0.5.2';
        wp_enqueue_style( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.css', array(), $asset_version );
        wp_enqueue_script( 'xlocal-bridge-post-admin', $base_url . 'admin/admin.js', array(), $asset_version, true );
    }
}
