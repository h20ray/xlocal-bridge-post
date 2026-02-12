<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Tabs_Sender_Advanced_Trait {
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

}
