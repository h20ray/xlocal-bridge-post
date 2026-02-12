<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Sender {
    const QUEUE_OPTION_KEY = 'xlocal_bridge_post_sender_queue';
    const CRON_HOOK = 'xlocal_bridge_post_sender_cron';
    const META_SENT_HASH = '_xlocal_sender_last_hash';
    const META_REMOTE_INGEST = '_xlocal_ingest_id';
    const META_REMOTE_SOURCE = '_xlocal_source_url';
    private static $dispatch_guard = array();

    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'maybe_dispatch_post' ), 20, 3 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch_queue' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'register_custom_schedules' ) );
        add_action( 'init', array( __CLASS__, 'ensure_cron_schedule' ) );
    }

    public static function send_payload( $endpoint, $secret, $payload, $options ) {
        $body = wp_json_encode( $payload );
        if ( ! is_string( $body ) || $body === '' ) {
            return new WP_Error( 'xlocal_encode_failed', 'Failed to encode payload.' );
        }

        if ( ! self::is_valid_endpoint( $endpoint ) ) {
            return new WP_Error( 'xlocal_invalid_endpoint', 'Invalid sender endpoint.' );
        }

        if ( self::is_self_target( $endpoint ) ) {
            return new WP_Error( 'xlocal_self_target', 'Sender endpoint cannot target the same site.' );
        }

        $max_kb = max( 64, intval( $options['sender_max_payload_kb'] ) );
        if ( strlen( $body ) > ( $max_kb * 1024 ) ) {
            return new WP_Error( 'xlocal_payload_too_large', 'Payload exceeds sender limit.' );
        }

        $origin_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $args = array(
            'method' => 'POST',
            'timeout' => max( 3, intval( $options['sender_timeout'] ) ),
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Xlocal-Origin-Host' => sanitize_text_field( (string) $origin_host ),
            ),
            'body' => $body,
        );

        $max_retries = max( 0, intval( $options['sender_max_retries'] ) );
        $backoff_base_ms = max( 100, intval( $options['sender_backoff_base_ms'] ) );
        $attempt = 0;
        $last_error = null;
        $last_response = null;

        while ( $attempt <= $max_retries ) {
            $attempt++;
            $timestamp = time();
            $nonce = wp_generate_password( 24, false, false );
            $signature = hash_hmac( 'sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret );
            $args['headers']['X-Xlocal-Timestamp'] = $timestamp;
            $args['headers']['X-Xlocal-Nonce'] = $nonce;
            $args['headers']['X-Xlocal-Signature'] = $signature;

            $response = wp_remote_post( $endpoint, $args );
            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                self::debug_log( 'Sender transport error on attempt ' . $attempt . ': ' . $response->get_error_message(), $options );
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $response_body = wp_remote_retrieve_body( $response );
                $last_response = array(
                    'code' => $code,
                    'body' => $response_body,
                    'attempts' => $attempt,
                );
                self::store_last_result( 'HTTP ' . $code . ' (attempt ' . $attempt . ')' . "\n" . $response_body );
                if ( $code >= 200 && $code < 300 ) {
                    return $last_response;
                }
                self::debug_log( 'Sender HTTP error on attempt ' . $attempt . ': ' . $code, $options );
            }

            if ( $attempt <= $max_retries ) {
                $delay_us = (int) ( $backoff_base_ms * pow( 2, $attempt - 1 ) * 1000 );
                usleep( min( $delay_us, 3000000 ) );
            }
        }

        if ( $last_error instanceof WP_Error ) {
            self::store_last_result( $last_error->get_error_message() );
            return $last_error;
        }

        if ( is_array( $last_response ) ) {
            return $last_response;
        }

        return new WP_Error( 'xlocal_unknown_send_error', 'Send failed with unknown error.' );
    }

    public static function maybe_dispatch_post( $post_id, $post, $update ) {
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $guard_key = intval( $post_id ) . ':' . (string) $post->post_modified_gmt;
        if ( isset( self::$dispatch_guard[ $guard_key ] ) ) {
            return;
        }
        self::$dispatch_guard[ $guard_key ] = true;

        try {
            $options = Xlocal_Bridge_Settings::get_options();
            if ( ! self::is_sender_active( $options ) || empty( $options['sender_auto_send'] ) ) {
                return;
            }
            if ( $post->post_type !== $options['sender_target_post_type'] ) {
                return;
            }
            if ( $post->post_status !== 'publish' ) {
                return;
            }
            if ( ! empty( get_post_meta( $post_id, self::META_REMOTE_INGEST, true ) ) || ! empty( get_post_meta( $post_id, self::META_REMOTE_SOURCE, true ) ) ) {
                return;
            }

            $payload = self::build_payload_from_post( $post, $options );
            if ( is_wp_error( $payload ) ) {
                self::store_last_result( $payload->get_error_message() );
                return;
            }

            $payload_hash = hash( 'sha256', wp_json_encode( $payload ) );
            $last_hash = (string) get_post_meta( $post_id, self::META_SENT_HASH, true );
            if ( $update && $last_hash !== '' && hash_equals( $last_hash, $payload_hash ) ) {
                return;
            }

            if ( ! empty( $options['sender_dry_run'] ) ) {
                self::store_last_result( 'Dry run: payload prepared for post ' . $post_id );
                update_post_meta( $post_id, self::META_SENT_HASH, $payload_hash );
                return;
            }

            if ( $options['sender_sync_mode'] === 'batch' ) {
                self::queue_post( $post_id, $payload_hash );
                self::store_last_result( 'Queued post ' . $post_id . ' for batch send.' );
                return;
            }

            self::send_single_post( $post_id, $payload, $payload_hash, $options );
        } finally {
            unset( self::$dispatch_guard[ $guard_key ] );
        }
    }

    public static function process_batch_queue() {
        $options = Xlocal_Bridge_Settings::get_options();
        if ( ! self::is_sender_active( $options ) || empty( $options['sender_auto_send'] ) ) {
            return;
        }

        $queue = self::get_queue();
        if ( empty( $queue ) ) {
            return;
        }

        $batch_size = max( 1, intval( $options['sender_batch_size'] ) );
        $remaining = array();
        $processed = 0;

        foreach ( $queue as $item ) {
            if ( $processed >= $batch_size ) {
                $remaining[] = $item;
                continue;
            }
            $post_id = isset( $item['post_id'] ) ? intval( $item['post_id'] ) : 0;
            if ( $post_id <= 0 ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                continue;
            }

            $payload = self::build_payload_from_post( $post, $options );
            if ( is_wp_error( $payload ) ) {
                self::debug_log( 'Batch payload build failed for post ' . $post_id . ': ' . $payload->get_error_message(), $options );
                $remaining[] = $item;
                continue;
            }

            $payload_hash = hash( 'sha256', wp_json_encode( $payload ) );
            $sent = self::send_single_post( $post_id, $payload, $payload_hash, $options );
            if ( ! $sent ) {
                $remaining[] = $item;
            }
            $processed++;
        }

        self::set_queue( $remaining );
    }

    public static function register_custom_schedules( $schedules ) {
        if ( ! isset( $schedules['xlocal_every_five_minutes'] ) ) {
            $schedules['xlocal_every_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'Every 5 Minutes',
            );
        }
        if ( ! isset( $schedules['xlocal_every_fifteen_minutes'] ) ) {
            $schedules['xlocal_every_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => 'Every 15 Minutes',
            );
        }
        return $schedules;
    }

    public static function ensure_cron_schedule() {
        $options = Xlocal_Bridge_Settings::get_options();
        $is_batch = self::is_sender_active( $options ) && ! empty( $options['sender_auto_send'] ) && $options['sender_sync_mode'] === 'batch';
        $schedule = self::map_schedule_key( $options['sender_schedule_interval'] );

        if ( ! $is_batch ) {
            self::clear_cron_events();
            return;
        }

        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $next ) {
            wp_schedule_event( time() + 60, $schedule, self::CRON_HOOK );
            return;
        }

        $current = wp_get_schedule( self::CRON_HOOK );
        if ( $current !== $schedule ) {
            self::clear_cron_events();
            wp_schedule_event( time() + 60, $schedule, self::CRON_HOOK );
        }
    }

    private static function store_last_result( $text ) {
        $options = Xlocal_Bridge_Settings::get_options();
        $options['sender_last_push_result'] = $text;
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );
    }

    private static function is_sender_active( $options ) {
        return in_array( $options['mode'], array( 'sender', 'both' ), true );
    }

    private static function is_valid_endpoint( $endpoint ) {
        return is_string( $endpoint ) && filter_var( $endpoint, FILTER_VALIDATE_URL );
    }

    private static function is_self_target( $endpoint ) {
        $target = wp_parse_url( $endpoint, PHP_URL_HOST );
        $local = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $target ) || empty( $local ) ) {
            return false;
        }
        return strtolower( $target ) === strtolower( $local );
    }

    /**
     * Explicit bulk send for a single post.
     *
     * This is used by the Bulk Send admin tool and intentionally ignores
     * sender_auto_send and save_post dispatch guards, but still enforces:
     * - correct post type
     * - correct status (publish by default)
     * - not a remote-ingested post
     *
     * Returns one of: sent, skipped_same_hash, skipped_remote, skipped_post_type, skipped_status, error.
     */
    public static function bulk_send_post( WP_Post $post, $options = null, $required_status = 'publish', $required_post_type = '' ) {
        $post_id = intval( $post->ID );

        if ( ! $options ) {
            $options = Xlocal_Bridge_Settings::get_options();
        }

        if ( ! self::is_sender_active( $options ) ) {
            return 'error';
        }

        if ( $required_post_type === '' ) {
            $required_post_type = $options['sender_target_post_type'];
        }

        if ( $post->post_type !== $required_post_type ) {
            return 'skipped_post_type';
        }

        if ( $required_status && $post->post_status !== $required_status ) {
            return 'skipped_status';
        }

        if ( ! empty( get_post_meta( $post_id, self::META_REMOTE_INGEST, true ) ) || ! empty( get_post_meta( $post_id, self::META_REMOTE_SOURCE, true ) ) ) {
            return 'skipped_remote';
        }

        $payload = self::build_payload_from_post( $post, $options );
        if ( is_wp_error( $payload ) ) {
            self::store_last_result( $payload->get_error_message() );
            return 'error';
        }

        $payload_hash = hash( 'sha256', wp_json_encode( $payload ) );
        $last_hash    = (string) get_post_meta( $post_id, self::META_SENT_HASH, true );
        if ( $last_hash !== '' && hash_equals( $last_hash, $payload_hash ) ) {
            return 'skipped_same_hash';
        }

        if ( ! empty( $options['sender_dry_run'] ) ) {
            self::store_last_result( 'Dry run (bulk): payload prepared for post ' . $post_id );
            update_post_meta( $post_id, self::META_SENT_HASH, $payload_hash );
            return 'sent';
        }

        $sent = self::send_single_post( $post_id, $payload, $payload_hash, $options );
        return $sent ? 'sent' : 'error';
    }

    private static function send_single_post( $post_id, $payload, $payload_hash, $options ) {
        $endpoint = rtrim( $options['sender_main_base_url'], '/' ) . $options['sender_ingest_path'];
        $secret = Xlocal_Bridge_Settings::get_sender_secret();
        if ( empty( $secret ) || empty( $endpoint ) ) {
            self::store_last_result( 'Missing sender endpoint or secret.' );
            return false;
        }

        $result = self::send_payload( $endpoint, $secret, $payload, $options );
        if ( is_wp_error( $result ) ) {
            self::debug_log( 'Send failed for post ' . $post_id . ': ' . $result->get_error_message(), $options );
            return false;
        }

        $code = isset( $result['code'] ) ? intval( $result['code'] ) : 500;
        if ( $code >= 200 && $code < 300 ) {
            update_post_meta( $post_id, self::META_SENT_HASH, $payload_hash );
            return true;
        }

        self::debug_log( 'Send returned non-2xx for post ' . $post_id . ': ' . $code, $options );
        return false;
    }

    private static function build_payload_from_post( $post, $options ) {
        $post_id = intval( $post->ID );
        $source_url = get_permalink( $post_id );
        if ( empty( $source_url ) ) {
            return new WP_Error( 'xlocal_missing_permalink', 'Missing source URL for post.' );
        }

        $content_html = (string) $post->post_content;
        $img_urls = self::extract_img_srcs( $content_html );
        $featured = self::build_featured_image_data( $post_id );
        if ( ! empty( $featured['url'] ) ) {
            $img_urls[] = $featured['url'];
        }

        if ( ! empty( $options['sender_ensure_cdn_urls'] ) ) {
            $cdn_host = strtolower( (string) wp_parse_url( $options['sender_cdn_base'], PHP_URL_HOST ) );
            if ( empty( $cdn_host ) ) {
                return new WP_Error( 'xlocal_cdn_base_missing', 'CDN base is required when CDN enforcement is enabled.' );
            }
            foreach ( $img_urls as $url ) {
                $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
                if ( $host && $host !== $cdn_host ) {
                    return new WP_Error( 'xlocal_non_cdn_url', 'Detected non-CDN image URL in payload.' );
                }
            }
        }

        $payload = array(
            'ingest_id' => wp_generate_uuid4(),
            'source_url' => esc_url_raw( $source_url ),
            'source_hash' => hash( 'sha256', $post->post_modified_gmt . '|' . $post->post_title . '|' . $content_html ),
            'title' => (string) $post->post_title,
            'content_html' => $content_html,
            'excerpt' => (string) $post->post_excerpt,
            'status' => sanitize_key( $options['sender_default_status'] ),
            'date_gmt' => (string) $post->post_date_gmt,
            'media_manifest' => self::build_media_manifest( $img_urls ),
        );

        if ( ! empty( $featured ) ) {
            $payload['featured_image'] = $featured;
        }

        if ( ! empty( $options['sender_include_author'] ) ) {
            $user = get_user_by( 'id', $post->post_author );
            if ( $user ) {
                $payload['author'] = array(
                    'name' => $user->user_login,
                    'email' => $user->user_email,
                );
            }
        }

        if ( ! empty( $options['sender_send_taxonomies'] ) ) {
            $payload['categories'] = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
            $payload['tags'] = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
        }

        return $payload;
    }

    private static function build_featured_image_data( $post_id ) {
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumbnail_id ) {
            return array();
        }
        $src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
        if ( ! is_array( $src ) || empty( $src[0] ) ) {
            return array();
        }
        $alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
        return array(
            'url' => esc_url_raw( $src[0] ),
            'alt' => sanitize_text_field( (string) $alt ),
            'width' => isset( $src[1] ) ? intval( $src[1] ) : 0,
            'height' => isset( $src[2] ) ? intval( $src[2] ) : 0,
        );
    }

    private static function build_media_manifest( $urls ) {
        $manifest = array();
        foreach ( array_values( array_unique( array_filter( $urls ) ) ) as $url ) {
            $manifest[] = array( 'url' => esc_url_raw( $url ) );
        }
        return $manifest;
    }

    private static function extract_img_srcs( $html ) {
        $urls = array();
        if ( ! is_string( $html ) || $html === '' ) {
            return $urls;
        }
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $urls[] = $src;
            }
        }
        return $urls;
    }

    private static function queue_post( $post_id, $payload_hash ) {
        $queue = self::get_queue();
        foreach ( $queue as $item ) {
            if ( isset( $item['post_id'] ) && intval( $item['post_id'] ) === intval( $post_id ) ) {
                return;
            }
        }
        $queue[] = array(
            'post_id' => intval( $post_id ),
            'hash' => sanitize_text_field( (string) $payload_hash ),
            'queued_at' => time(),
        );
        self::set_queue( $queue );
    }

    private static function get_queue() {
        $queue = get_option( self::QUEUE_OPTION_KEY, array() );
        return is_array( $queue ) ? $queue : array();
    }

    private static function set_queue( $queue ) {
        update_option( self::QUEUE_OPTION_KEY, array_values( $queue ), false );
    }

    private static function map_schedule_key( $key ) {
        if ( $key === 'fifteen_minutes' ) {
            return 'xlocal_every_fifteen_minutes';
        }
        if ( $key === 'hourly' ) {
            return 'hourly';
        }
        return 'xlocal_every_five_minutes';
    }

    private static function clear_cron_events() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    private static function debug_log( $message, $options ) {
        if ( empty( $options['sender_debug_logs'] ) ) {
            return;
        }
        $prefix = gmdate( 'Y-m-d H:i:s' ) . ' UTC - ';
        $existing = isset( $options['sender_debug_log_history'] ) ? (string) $options['sender_debug_log_history'] : '';
        $lines = array_filter( explode( "\n", $existing ) );
        $lines[] = $prefix . sanitize_text_field( $message );
        $lines = array_slice( $lines, -200 );
        $options['sender_debug_log_history'] = implode( "\n", $lines );
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );
    }
}
