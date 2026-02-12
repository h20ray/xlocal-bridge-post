<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Fields_Trait {
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
}
