<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Receiver {
    const NONCE_PREFIX = 'xlocal_ingest_nonce_';
    const RATE_PREFIX = 'xlocal_ingest_rate_';
    const META_LAST_INGESTED_AT = '_xlocal_last_ingested_at';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route(
            'xlocal/v1',
            '/ingest',
            array(
                'methods'  => 'POST',
                'callback' => array( __CLASS__, 'handle_ingest' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public static function handle_ingest( WP_REST_Request $request ) {
        $options = Xlocal_Bridge_Settings::get_options();

        if ( $options['mode'] === 'sender' || empty( $options['receiver_enabled'] ) ) {
            self::log_ingest( $options, 'error', 'Receiver disabled; ingest rejected.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'receiver_disabled' ), 503 );
        }

        if ( ! empty( $options['receiver_require_tls'] ) && ! is_ssl() ) {
            self::log_ingest( $options, 'error', 'TLS required; ingest rejected.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'tls_required' ), 400 );
        }

        $secret = Xlocal_Bridge_Settings::get_receiver_secret();
        if ( empty( $secret ) ) {
            self::log_ingest( $options, 'error', 'Receiver secret missing; ingest rejected.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_secret' ), 500 );
        }

        if ( ! self::check_rate_limit( $options ) ) {
            self::log_ingest( $options, 'error', 'Rate limited ingest.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'rate_limited' ), 429 );
        }

        if ( ! self::check_ip_allowlist( $options ) ) {
            self::log_ingest(
                $options,
                'error',
                'IP not in allowlist.',
                array(
                    'remote_ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
                )
            );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'ip_not_allowed' ), 403 );
        }

        $raw_body = file_get_contents( 'php://input' );
        if ( $raw_body === false ) {
            self::log_ingest( $options, 'error', 'Ingest empty body.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'empty_body' ), 400 );
        }
        $max_kb = max( 64, intval( $options['receiver_max_payload_kb'] ) );
        if ( strlen( $raw_body ) > ( $max_kb * 1024 ) ) {
            self::log_ingest( $options, 'error', 'Payload too large.', array( 'bytes' => strlen( $raw_body ), 'limit_kb' => $max_kb ) );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'payload_too_large' ), 413 );
        }

        $timestamp = isset( $_SERVER['HTTP_X_XLOCAL_TIMESTAMP'] ) ? intval( $_SERVER['HTTP_X_XLOCAL_TIMESTAMP'] ) : 0;
        $nonce = isset( $_SERVER['HTTP_X_XLOCAL_NONCE'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_NONCE'] ) : '';
        $signature = isset( $_SERVER['HTTP_X_XLOCAL_SIGNATURE'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_SIGNATURE'] ) : '';
        $origin_host = isset( $_SERVER['HTTP_X_XLOCAL_ORIGIN_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_ORIGIN_HOST'] ) : '';
        $local_host = wp_parse_url( home_url(), PHP_URL_HOST );

        if ( $origin_host !== '' && $local_host && strtolower( $origin_host ) === strtolower( $local_host ) ) {
            self::log_ingest( $options, 'error', 'Self-origin request rejected.', array( 'origin_host' => $origin_host ) );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'self_origin_rejected' ), 409 );
        }

        if ( ! self::verify_timestamp_nonce( $timestamp, $nonce, $options ) ) {
            self::log_ingest( $options, 'error', 'Invalid timestamp/nonce.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_timestamp_or_nonce' ), 401 );
        }

        if ( ! self::verify_signature( $secret, $timestamp, $nonce, $raw_body, $signature ) ) {
            self::log_ingest( $options, 'error', 'Invalid signature.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_signature' ), 401 );
        }
        self::remember_nonce( $nonce, $options );

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            self::log_ingest( $options, 'error', 'Invalid JSON payload.', array() );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_json' ), 400 );
        }

        $required = array( 'ingest_id', 'source_url', 'title', 'content_html' );
        foreach ( $required as $field ) {
            if ( empty( $payload[ $field ] ) ) {
                self::log_ingest( $options, 'error', 'Payload missing required field.', array( 'field' => $field ) );
                return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_field', 'field' => $field ), 400 );
            }
        }

        $content_insight = self::build_content_insight( $payload );
        if ( ! self::validate_media_domains( $payload, $options ) ) {
            self::log_ingest( $options, 'error', 'Media domain rejected by allowlist.', $content_insight );
            return new WP_REST_Response( array( 'success' => false, 'error' => 'media_domain_not_allowed' ), 400 );
        }

        $post_id = self::upsert_post( $payload, $options );
        if ( is_wp_error( $post_id ) ) {
            self::log_ingest( $options, 'error', 'Upsert failed: ' . $post_id->get_error_message(), $content_insight );
            return new WP_REST_Response( array( 'success' => false, 'error' => $post_id->get_error_message() ), 500 );
        }

        self::log_ingest(
            $options,
            'success',
            'Ingest completed.',
            array_merge(
                $content_insight,
                array(
                    'ingest_id' => isset( $payload['ingest_id'] ) ? sanitize_text_field( (string) $payload['ingest_id'] ) : '',
                    'source_url' => isset( $payload['source_url'] ) ? esc_url_raw( (string) $payload['source_url'] ) : '',
                    'post_id' => isset( $post_id['post_id'] ) ? intval( $post_id['post_id'] ) : 0,
                    'action' => isset( $post_id['action'] ) ? sanitize_text_field( (string) $post_id['action'] ) : '',
                    'prepend_featured_applied' => ! empty( $post_id['prepend_featured_applied'] ),
                    'featured_mode' => isset( $post_id['featured_mode'] ) ? sanitize_text_field( (string) $post_id['featured_mode'] ) : '',
                    'featured_status' => isset( $post_id['featured_status'] ) ? sanitize_text_field( (string) $post_id['featured_status'] ) : '',
                    'featured_error' => isset( $post_id['featured_error'] ) ? sanitize_text_field( (string) $post_id['featured_error'] ) : '',
                    'category_assigned_count' => isset( $post_id['category_assigned_count'] ) ? intval( $post_id['category_assigned_count'] ) : 0,
                    'tag_assigned_count' => isset( $post_id['tag_assigned_count'] ) ? intval( $post_id['tag_assigned_count'] ) : 0,
                    'category_taxonomy_supported' => ! empty( $post_id['category_taxonomy_supported'] ),
                    'tag_taxonomy_supported' => ! empty( $post_id['tag_taxonomy_supported'] ),
                    'category_taxonomy_error' => isset( $post_id['category_taxonomy_error'] ) ? sanitize_text_field( (string) $post_id['category_taxonomy_error'] ) : '',
                    'tag_taxonomy_error' => isset( $post_id['tag_taxonomy_error'] ) ? sanitize_text_field( (string) $post_id['tag_taxonomy_error'] ) : '',
                    'payload_categories' => isset( $post_id['payload_categories'] ) && is_array( $post_id['payload_categories'] ) ? array_values( array_map( 'sanitize_text_field', $post_id['payload_categories'] ) ) : array(),
                    'normalized_categories' => isset( $post_id['normalized_categories'] ) && is_array( $post_id['normalized_categories'] ) ? array_values( array_map( 'sanitize_text_field', $post_id['normalized_categories'] ) ) : array(),
                    'category_term_ids' => isset( $post_id['category_term_ids'] ) && is_array( $post_id['category_term_ids'] ) ? array_values( array_map( 'intval', $post_id['category_term_ids'] ) ) : array(),
                    'payload_tags' => isset( $post_id['payload_tags'] ) && is_array( $post_id['payload_tags'] ) ? array_values( array_map( 'sanitize_text_field', $post_id['payload_tags'] ) ) : array(),
                    'normalized_tags' => isset( $post_id['normalized_tags'] ) && is_array( $post_id['normalized_tags'] ) ? array_values( array_map( 'sanitize_text_field', $post_id['normalized_tags'] ) ) : array(),
                )
            )
        );

        return new WP_REST_Response(
            array(
                'success' => true,
                'post_id' => $post_id['post_id'],
                'action'  => $post_id['action'],
            ),
            200
        );
    }

    private static function verify_signature( $secret, $timestamp, $nonce, $raw_body, $signature ) {
        if ( empty( $timestamp ) || empty( $nonce ) || empty( $signature ) ) {
            return false;
        }
        $data = $timestamp . "\n" . $nonce . "\n" . $raw_body;
        $computed = hash_hmac( 'sha256', $data, $secret );
        return hash_equals( $computed, $signature );
    }

    private static function verify_timestamp_nonce( $timestamp, $nonce, $options ) {
        if ( empty( $timestamp ) || empty( $nonce ) ) {
            return false;
        }
        $skew = intval( $options['receiver_clock_skew'] );
        $now = time();
        if ( abs( $now - $timestamp ) > $skew ) {
            return false;
        }

        $nonce_key = self::NONCE_PREFIX . md5( $nonce );
        if ( get_transient( $nonce_key ) ) {
            return false;
        }
        return true;
    }

    private static function remember_nonce( $nonce, $options ) {
        if ( empty( $nonce ) ) {
            return;
        }
        $ttl = max( 60, intval( $options['receiver_nonce_ttl'] ) );
        $nonce_key = self::NONCE_PREFIX . md5( $nonce );
        set_transient( $nonce_key, 1, $ttl );
    }

    private static function check_ip_allowlist( $options ) {
        $list = isset( $options['receiver_ip_allowlist'] ) ? trim( $options['receiver_ip_allowlist'] ) : '';
        if ( $list === '' ) {
            return true;
        }
        $allowed = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $list ) ) );
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        if ( $remote === '' ) {
            return false;
        }
        return in_array( $remote, $allowed, true );
    }

    private static function check_rate_limit( $options ) {
        $limit = intval( $options['receiver_rate_limit'] );
        if ( $limit <= 0 ) {
            return true;
        }
        $minute = gmdate( 'YmdHi' );
        $key = self::RATE_PREFIX . $minute;
        $count = intval( get_transient( $key ) );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, 60 );
        return true;
    }

    private static function validate_media_domains( $payload, $options ) {
        if ( empty( $options['receiver_reject_non_allowed_media'] ) ) {
            return true;
        }

        $allowlist = isset( $options['receiver_allowed_media_domains'] ) ? trim( $options['receiver_allowed_media_domains'] ) : '';
        if ( $allowlist === '' ) {
            return false;
        }
        $allowed = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/\r\n|\r|\n/', $allowlist ) ) ) );

        $urls = array();
        if ( ! empty( $payload['content_html'] ) ) {
            $urls = array_merge( $urls, self::extract_img_srcs( $payload['content_html'] ) );
        }
        if ( ! empty( $payload['featured_image']['url'] ) ) {
            $urls[] = $payload['featured_image']['url'];
        }
        if ( ! empty( $payload['media_manifest'] ) && is_array( $payload['media_manifest'] ) ) {
            foreach ( $payload['media_manifest'] as $item ) {
                if ( isset( $item['url'] ) ) {
                    $urls[] = $item['url'];
                }
            }
        }

        foreach ( $urls as $url ) {
            $host = strtolower( parse_url( $url, PHP_URL_HOST ) );
            if ( empty( $host ) || ! in_array( $host, $allowed, true ) ) {
                return false;
            }
        }
        return true;
    }

    private static function extract_img_srcs( $html ) {
        $urls = array();
        if ( ! is_string( $html ) || $html === '' ) {
            return $urls;
        }
        if ( preg_match_all( '/<img[^>]+>/i', $html, $img_tags ) ) {
            foreach ( $img_tags[0] as $img_tag ) {
                $src = self::extract_attribute_value( $img_tag, 'src' );
                if ( $src !== '' ) {
                    $urls[] = $src;
                }
                $data_src = self::extract_attribute_value( $img_tag, 'data-src' );
                if ( $data_src !== '' ) {
                    $urls[] = $data_src;
                }
                $srcset = self::extract_attribute_value( $img_tag, 'srcset' );
                if ( $srcset !== '' ) {
                    foreach ( explode( ',', $srcset ) as $candidate ) {
                        $candidate = trim( $candidate );
                        if ( $candidate === '' ) {
                            continue;
                        }
                        $parts = preg_split( '/\s+/', $candidate );
                        if ( ! empty( $parts[0] ) ) {
                            $urls[] = $parts[0];
                        }
                    }
                }
                $data_srcset = self::extract_attribute_value( $img_tag, 'data-srcset' );
                if ( $data_srcset !== '' ) {
                    foreach ( explode( ',', $data_srcset ) as $candidate ) {
                        $candidate = trim( $candidate );
                        if ( $candidate === '' ) {
                            continue;
                        }
                        $parts = preg_split( '/\s+/', $candidate );
                        if ( ! empty( $parts[0] ) ) {
                            $urls[] = $parts[0];
                        }
                    }
                }
            }
        }
        return $urls;
    }

    private static function extract_attribute_value( $tag, $attribute ) {
        $attribute = strtolower( (string) $attribute );
        if ( $attribute === '' ) {
            return '';
        }
        if ( preg_match( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag, $matches ) ) {
            return html_entity_decode( (string) $matches[2], ENT_QUOTES, 'UTF-8' );
        }
        return '';
    }

    private static function upsert_post( $payload, $options ) {
        $ingest_id = sanitize_text_field( $payload['ingest_id'] );
        $existing_by_ingest = self::find_post_by_meta( '_xlocal_ingest_id', $ingest_id, $options['receiver_default_post_type'] );
        if ( $existing_by_ingest ) {
            return array( 'post_id' => $existing_by_ingest, 'action' => 'noop_duplicate' );
        }

        $meta_key = $options['receiver_source_url_meta_key'];
        $source_url = esc_url_raw( $payload['source_url'] );
        $existing_id = 0;
        if ( $options['receiver_dedup_mode'] === 'source_hash+source_url' && ! empty( $payload['source_hash'] ) ) {
            $existing_id = self::find_post_by_meta_two(
                $meta_key,
                $source_url,
                '_xlocal_source_hash',
                sanitize_text_field( $payload['source_hash'] ),
                $options['receiver_default_post_type']
            );
        }
        if ( ! $existing_id ) {
            $existing_id = self::find_post_by_meta( $meta_key, $source_url, $options['receiver_default_post_type'] );
        }
        $action = $existing_id ? 'updated' : 'created';

        $title = sanitize_text_field( $payload['title'] );
        $content = is_string( $payload['content_html'] ) ? $payload['content_html'] : '';
        $excerpt = isset( $payload['excerpt'] ) ? sanitize_text_field( $payload['excerpt'] ) : '';

        $prepend_featured_applied = false;
        $content = self::maybe_prepend_featured_image_to_content( $content, $payload, $options, $prepend_featured_applied );

        if ( ! empty( $options['receiver_sanitize_html'] ) ) {
            $content = self::sanitize_html( $content, $options );
        }

        $post_type = $options['receiver_default_post_type'];
        $status = $options['receiver_default_status'];
        if ( ! empty( $options['receiver_allow_sender_override_status'] ) && ! empty( $payload['status'] ) ) {
            $status = sanitize_key( $payload['status'] );
        }
        $allowed_statuses = array( 'publish', 'pending', 'draft' );
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            $status = 'pending';
        }

        $author_id = self::resolve_author( $payload, $options );

        $post_data = array(
            'post_type' => $post_type,
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_author' => $author_id,
        );

        if ( ! $existing_id && ! empty( $payload['date_gmt'] ) ) {
            $date_gmt = sanitize_text_field( $payload['date_gmt'] );
            $post_data['post_date_gmt'] = $date_gmt;
            $post_data['post_date'] = get_date_from_gmt( $date_gmt );
        }

        $now_gmt = current_time( 'mysql', true );
        $update_allowed = self::should_update_content( $existing_id, $options );

        if ( $existing_id ) {
            $post_data['ID'] = $existing_id;
            if ( ! $update_allowed ) {
                unset( $post_data['post_title'], $post_data['post_content'], $post_data['post_excerpt'] );
            }
            $post_id = wp_update_post( $post_data, true );
        } else {
            $post_id = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_xlocal_ingest_id', $ingest_id );
        update_post_meta( $post_id, $meta_key, $source_url );
        if ( ! empty( $payload['source_hash'] ) ) {
            update_post_meta( $post_id, '_xlocal_source_hash', sanitize_text_field( $payload['source_hash'] ) );
        }

        $featured_result = array(
            'mode' => '',
            'status' => '',
            'error' => '',
        );
        if ( ! empty( $payload['featured_image'] ) && is_array( $payload['featured_image'] ) ) {
            $featured_result = self::apply_featured_image( $post_id, $payload['featured_image'], $options );
        }

        if ( ! empty( $payload['media_manifest'] ) && is_array( $payload['media_manifest'] ) ) {
            update_post_meta( $post_id, '_xlocal_media_manifest', wp_json_encode( $payload['media_manifest'] ) );
        }

        update_post_meta( $post_id, self::META_LAST_INGESTED_AT, $now_gmt );

        $taxonomy_result = self::apply_taxonomies( $post_id, $payload, $options );

        return array(
            'post_id' => $post_id,
            'action' => $action,
            'prepend_featured_applied' => $prepend_featured_applied ? 1 : 0,
            'featured_mode' => $featured_result['mode'] !== '' ? $featured_result['mode'] : get_post_meta( $post_id, '_xlocal_featured_image_mode', true ),
            'featured_status' => $featured_result['status'] !== '' ? $featured_result['status'] : get_post_meta( $post_id, '_xlocal_featured_image_ingest_status', true ),
            'featured_error' => $featured_result['error'] !== '' ? $featured_result['error'] : get_post_meta( $post_id, '_xlocal_featured_image_ingest_error', true ),
            'category_assigned_count' => isset( $taxonomy_result['category_assigned_count'] ) ? intval( $taxonomy_result['category_assigned_count'] ) : 0,
            'tag_assigned_count' => isset( $taxonomy_result['tag_assigned_count'] ) ? intval( $taxonomy_result['tag_assigned_count'] ) : 0,
            'category_taxonomy_supported' => ! empty( $taxonomy_result['category_taxonomy_supported'] ) ? 1 : 0,
            'tag_taxonomy_supported' => ! empty( $taxonomy_result['tag_taxonomy_supported'] ) ? 1 : 0,
            'category_taxonomy_error' => isset( $taxonomy_result['category_taxonomy_error'] ) ? sanitize_text_field( (string) $taxonomy_result['category_taxonomy_error'] ) : '',
            'tag_taxonomy_error' => isset( $taxonomy_result['tag_taxonomy_error'] ) ? sanitize_text_field( (string) $taxonomy_result['tag_taxonomy_error'] ) : '',
            'payload_categories' => isset( $taxonomy_result['payload_categories'] ) && is_array( $taxonomy_result['payload_categories'] ) ? array_values( array_map( 'sanitize_text_field', $taxonomy_result['payload_categories'] ) ) : array(),
            'normalized_categories' => isset( $taxonomy_result['normalized_categories'] ) && is_array( $taxonomy_result['normalized_categories'] ) ? array_values( array_map( 'sanitize_text_field', $taxonomy_result['normalized_categories'] ) ) : array(),
            'category_term_ids' => isset( $taxonomy_result['category_term_ids'] ) && is_array( $taxonomy_result['category_term_ids'] ) ? array_values( array_map( 'intval', $taxonomy_result['category_term_ids'] ) ) : array(),
            'payload_tags' => isset( $taxonomy_result['payload_tags'] ) && is_array( $taxonomy_result['payload_tags'] ) ? array_values( array_map( 'sanitize_text_field', $taxonomy_result['payload_tags'] ) ) : array(),
            'normalized_tags' => isset( $taxonomy_result['normalized_tags'] ) && is_array( $taxonomy_result['normalized_tags'] ) ? array_values( array_map( 'sanitize_text_field', $taxonomy_result['normalized_tags'] ) ) : array(),
        );
    }

    private static function should_update_content( $post_id, $options ) {
        if ( ! $post_id ) {
            return true;
        }
        if ( $options['receiver_update_strategy'] === 'overwrite_all' ) {
            return true;
        }

        $locked = get_post_meta( $post_id, '_xlocal_locked', true );
        if ( $locked ) {
            return false;
        }

        $last_ingested = get_post_meta( $post_id, self::META_LAST_INGESTED_AT, true );
        if ( $last_ingested ) {
            $post = get_post( $post_id );
            if ( $post && strtotime( $post->post_modified_gmt ) > strtotime( $last_ingested ) ) {
                return false;
            }
        }
        return true;
    }

    private static function resolve_author( $payload, $options ) {
        $allowed_roles = array( 'administrator', 'editor', 'author' );

        // Random editor mode: pick random editor; fallback to admin if no editors.
        if ( $options['receiver_author_mode'] === 'random_editor' ) {
            $editors = get_users(
                array(
                    'role__in' => array( 'editor' ),
                    'fields' => 'ID',
                )
            );
            if ( ! empty( $editors ) ) {
                $index = array_rand( $editors );
                return intval( $editors[ $index ] );
            }

            // Fallback to first administrator if no editors exist.
            $admins = get_users(
                array(
                    'role__in' => array( 'administrator' ),
                    'number' => 1,
                    'fields' => 'ID',
                )
            );
            if ( ! empty( $admins ) ) {
                return intval( $admins[0] );
            }
        }

        if ( $options['receiver_author_mode'] === 'by_name' && ! empty( $payload['author']['name'] ) ) {
            $name = sanitize_text_field( $payload['author']['name'] );
            $user = get_user_by( 'login', $name );
            if ( ! $user ) {
                $user = get_user_by( 'display_name', $name );
            }
            if ( $user && ! empty( $user->roles ) && array_intersect( $user->roles, $allowed_roles ) ) {
                return $user->ID;
            }
        }

        if ( $options['receiver_author_mode'] === 'by_email' && ! empty( $payload['author']['email'] ) ) {
            $email = sanitize_email( $payload['author']['email'] );
            $user = get_user_by( 'email', $email );
            if ( $user && ! empty( $user->roles ) && array_intersect( $user->roles, $allowed_roles ) ) {
                return $user->ID;
            }
        }

        $fixed = intval( $options['receiver_fixed_author_id'] );
        if ( $fixed > 0 ) {
            return $fixed;
        }

        return get_current_user_id();
    }

    private static function apply_taxonomies( $post_id, $payload, $options ) {
        $result = array(
            'category_assigned_count' => 0,
            'tag_assigned_count' => 0,
            'category_taxonomy_supported' => false,
            'tag_taxonomy_supported' => false,
            'category_taxonomy_error' => '',
            'tag_taxonomy_error' => '',
            'payload_categories' => array(),
            'normalized_categories' => array(),
            'category_term_ids' => array(),
            'payload_tags' => array(),
            'normalized_tags' => array(),
        );

        $post_type = get_post_type( $post_id );

        if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
            $result['payload_categories'] = array_values( array_map( 'sanitize_text_field', $payload['categories'] ) );
            $cats = self::normalize_terms( $payload['categories'], $options['receiver_category_mapping_rules'], $options['receiver_tag_normalization'] );
            $result['normalized_categories'] = array_values( array_map( 'sanitize_text_field', $cats ) );
            $result['category_taxonomy_supported'] = (bool) is_object_in_taxonomy( $post_type, 'category' );
            if ( ! $result['category_taxonomy_supported'] ) {
                $result['category_taxonomy_error'] = 'Taxonomy "category" is not registered for post type "' . sanitize_key( (string) $post_type ) . '".';
            } else {
                $cat_term_ids = self::resolve_term_ids( $cats, 'category', ! empty( $options['receiver_auto_create_categories'] ) );
                $result['category_term_ids'] = $cat_term_ids;
                $set_cats = wp_set_post_terms( $post_id, $cat_term_ids, 'category', false );
                if ( is_wp_error( $set_cats ) ) {
                    $result['category_taxonomy_error'] = $set_cats->get_error_message();
                } elseif ( is_array( $set_cats ) ) {
                    $result['category_assigned_count'] = count( $set_cats );
                }
            }
        }

        if ( ! empty( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
            $result['payload_tags'] = array_values( array_map( 'sanitize_text_field', $payload['tags'] ) );
            $tags = self::normalize_terms( $payload['tags'], '', $options['receiver_tag_normalization'] );
            $result['normalized_tags'] = array_values( array_map( 'sanitize_text_field', $tags ) );
            $result['tag_taxonomy_supported'] = (bool) is_object_in_taxonomy( $post_type, 'post_tag' );
            if ( ! $result['tag_taxonomy_supported'] ) {
                $result['tag_taxonomy_error'] = 'Taxonomy "post_tag" is not registered for post type "' . sanitize_key( (string) $post_type ) . '".';
            } else {
                if ( $options['receiver_auto_create_tags'] ) {
                    self::ensure_terms_exist( $tags, 'post_tag' );
                }
                $set_tags = wp_set_post_terms( $post_id, $tags, 'post_tag', false );
                if ( is_wp_error( $set_tags ) ) {
                    $result['tag_taxonomy_error'] = $set_tags->get_error_message();
                } elseif ( is_array( $set_tags ) ) {
                    $result['tag_assigned_count'] = count( $set_tags );
                }
            }
        }

        return $result;
    }

    private static function resolve_term_ids( $terms, $taxonomy, $allow_create ) {
        $ids = array();
        foreach ( (array) $terms as $term_name ) {
            $term_name = sanitize_text_field( (string) $term_name );
            if ( $term_name === '' ) {
                continue;
            }

            $term = term_exists( $term_name, $taxonomy );
            if ( ! $term && $allow_create ) {
                $created = wp_insert_term( $term_name, $taxonomy );
                if ( ! is_wp_error( $created ) && ! empty( $created['term_id'] ) ) {
                    $ids[] = intval( $created['term_id'] );
                }
                continue;
            }

            if ( is_array( $term ) && ! empty( $term['term_id'] ) ) {
                $ids[] = intval( $term['term_id'] );
            } elseif ( is_int( $term ) && $term > 0 ) {
                $ids[] = intval( $term );
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private static function normalize_terms( $terms, $mapping_rules, $normalize ) {
        $terms = array_map(
            function( $term ) {
                $decoded = html_entity_decode( (string) $term, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                return sanitize_text_field( $decoded );
            },
            $terms
        );
        $map = self::parse_mapping_rules( $mapping_rules );
        if ( ! empty( $map ) ) {
            $terms = array_map( function( $term ) use ( $map ) {
                $key = strtolower( $term );
                return isset( $map[ $key ] ) ? $map[ $key ] : $term;
            }, $terms );
        }
        if ( $normalize ) {
            $terms = array_map( function( $term ) {
                return trim( strtolower( $term ) );
            }, $terms );
        }
        return array_values( array_unique( array_filter( $terms ) ) );
    }

    private static function parse_mapping_rules( $rules ) {
        $rules = trim( (string) $rules );
        if ( $rules === '' ) {
            return array();
        }
        $map = array();
        $lines = preg_split( '/\r\n|\r|\n/', $rules );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '->', $line ) );
            if ( count( $parts ) === 2 ) {
                $map[ strtolower( $parts[0] ) ] = $parts[1];
            }
        }
        return $map;
    }

    private static function ensure_terms_exist( $terms, $taxonomy ) {
        foreach ( $terms as $term ) {
            if ( $term === '' ) {
                continue;
            }
            if ( ! term_exists( $term, $taxonomy ) ) {
                wp_insert_term( $term, $taxonomy );
            }
        }
    }

    private static function store_featured_meta( $post_id, $image ) {
        if ( empty( $image['url'] ) ) {
            return;
        }
        update_post_meta( $post_id, '_xlocal_featured_image_url', esc_url_raw( $image['url'] ) );
        if ( ! empty( $image['alt'] ) ) {
            update_post_meta( $post_id, '_xlocal_featured_image_alt', sanitize_text_field( $image['alt'] ) );
        }
        if ( ! empty( $image['width'] ) ) {
            update_post_meta( $post_id, '_xlocal_featured_image_w', intval( $image['width'] ) );
        }
        if ( ! empty( $image['height'] ) ) {
            update_post_meta( $post_id, '_xlocal_featured_image_h', intval( $image['height'] ) );
        }
    }

    private static function apply_featured_image( $post_id, $image, $options ) {
        $result = array(
            'mode' => '',
            'status' => '',
            'error' => '',
        );

        self::store_featured_meta( $post_id, $image );

        $mode = isset( $options['receiver_featured_image_mode'] ) ? $options['receiver_featured_image_mode'] : 'meta_only';
        $mode = sanitize_key( $mode );
        $result['mode'] = $mode;
        update_post_meta( $post_id, '_xlocal_featured_image_mode', $mode );

        if ( $mode !== 'virtual_attachment' ) {
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'meta_only' );
            delete_post_meta( $post_id, '_xlocal_featured_image_ingest_error' );
            $result['status'] = 'meta_only';
            return $result;
        }

        if ( empty( $image['url'] ) ) {
            $error = 'Featured payload is missing URL.';
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'missing_url' );
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_error', $error );
            $result['status'] = 'missing_url';
            $result['error'] = $error;
            return $result;
        }

        $attachment_id = self::find_attachment_by_source_url( $image['url'] );
        if ( ! $attachment_id ) {
            $attachment_id = self::sideload_featured_attachment( $image['url'], $post_id, isset( $image['alt'] ) ? $image['alt'] : '' );
        }

        if ( is_int( $attachment_id ) && $attachment_id > 0 ) {
            set_post_thumbnail( $post_id, $attachment_id );
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'attached:' . intval( $attachment_id ) );
            delete_post_meta( $post_id, '_xlocal_featured_image_ingest_error' );
            $result['status'] = 'attached:' . intval( $attachment_id );
            return $result;
        }

        $error_message = 'Unknown media sideload failure.';
        $error_code = '';
        if ( is_wp_error( $attachment_id ) ) {
            $error_message = $attachment_id->get_error_message();
            $error_code = (string) $attachment_id->get_error_code();
        }
        $status = 'attachment_failed';
        if ( $error_code !== '' ) {
            $status .= ':' . sanitize_key( $error_code );
        }

        update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', $status );
        update_post_meta( $post_id, '_xlocal_featured_image_ingest_error', sanitize_text_field( $error_message ) );
        $result['status'] = $status;
        $result['error'] = sanitize_text_field( $error_message );
        return $result;
    }

    private static function find_attachment_by_source_url( $url ) {
        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'   => '_xlocal_featured_source_url',
                        'value' => esc_url_raw( $url ),
                    ),
                ),
            )
        );
        if ( ! empty( $query->posts ) ) {
            return intval( $query->posts[0] );
        }
        return 0;
    }

    public static function allow_sideload_image_extensions( $extensions ) {
        $extensions = is_array( $extensions ) ? $extensions : array();
        $extensions = array_map( 'strtolower', $extensions );
        $extensions[] = 'avif';
        return array_values( array_unique( $extensions ) );
    }

    private static function sideload_featured_attachment( $url, $post_id, $alt ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        add_filter( 'image_sideload_extensions', array( __CLASS__, 'allow_sideload_image_extensions' ) );
        $attachment_id = media_sideload_image( esc_url_raw( $url ), $post_id, null, 'id' );
        remove_filter( 'image_sideload_extensions', array( __CLASS__, 'allow_sideload_image_extensions' ) );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        update_post_meta( intval( $attachment_id ), '_xlocal_featured_source_url', esc_url_raw( $url ) );
        if ( is_string( $alt ) && $alt !== '' ) {
            update_post_meta( intval( $attachment_id ), '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }
        return intval( $attachment_id );
    }

    private static function sanitize_html( $html, $options ) {
        if ( $options['receiver_strip_scripts_iframes'] ) {
            $html = preg_replace( '/<\s*(script|iframe)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html );
        }
        $allowed = self::allowed_tags_profile( $options );
        $html = wp_kses( $html, $allowed );
        if ( $options['receiver_strip_inline_styles'] ) {
            $html = preg_replace( '/\sstyle=("|\')[^"\']*("|\')/i', '', $html );
        }
        return $html;
    }

    private static function maybe_prepend_featured_image_to_content( $content, $payload, $options, &$applied ) {
        $applied = false;
        if ( empty( $options['receiver_prepend_featured_if_missing'] ) ) {
            return $content;
        }
        if ( ! empty( self::extract_img_srcs( $content ) ) ) {
            return $content;
        }
        if ( empty( $payload['featured_image']['url'] ) ) {
            return $content;
        }
        $img_url = esc_url_raw( (string) $payload['featured_image']['url'] );
        if ( $img_url === '' ) {
            return $content;
        }

        $alt = '';
        if ( ! empty( $payload['featured_image']['alt'] ) ) {
            $alt = sanitize_text_field( (string) $payload['featured_image']['alt'] );
        }
        $width = ! empty( $payload['featured_image']['width'] ) ? intval( $payload['featured_image']['width'] ) : 0;
        $height = ! empty( $payload['featured_image']['height'] ) ? intval( $payload['featured_image']['height'] ) : 0;
        $img_html = '<p><img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $alt ) . '"';
        if ( $width > 0 ) {
            $img_html .= ' width="' . intval( $width ) . '"';
        }
        if ( $height > 0 ) {
            $img_html .= ' height="' . intval( $height ) . '"';
        }
        $img_html .= ' loading="eager" /></p>';
        $applied = true;
        return $img_html . "\n" . $content;
    }

    private static function build_content_insight( $payload ) {
        $content_html = isset( $payload['content_html'] ) && is_string( $payload['content_html'] ) ? $payload['content_html'] : '';
        $content_urls = self::extract_img_srcs( $content_html );
        $manifest_urls = array();
        if ( ! empty( $payload['media_manifest'] ) && is_array( $payload['media_manifest'] ) ) {
            foreach ( $payload['media_manifest'] as $item ) {
                if ( ! empty( $item['url'] ) ) {
                    $manifest_urls[] = esc_url_raw( (string) $item['url'] );
                }
            }
        }
        $featured_url = '';
        if ( ! empty( $payload['featured_image']['url'] ) ) {
            $featured_url = esc_url_raw( (string) $payload['featured_image']['url'] );
        }
        return array(
            'content_bytes' => strlen( $content_html ),
            'content_image_count' => count( $content_urls ),
            'content_image_hosts' => self::host_list( $content_urls ),
            'manifest_count' => count( $manifest_urls ),
            'manifest_hosts' => self::host_list( $manifest_urls ),
            'featured_host' => strtolower( (string) wp_parse_url( $featured_url, PHP_URL_HOST ) ),
            'has_featured_payload' => $featured_url !== '',
            'payload_category_count' => ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) ? count( $payload['categories'] ) : 0,
            'payload_tag_count' => ( ! empty( $payload['tags'] ) && is_array( $payload['tags'] ) ) ? count( $payload['tags'] ) : 0,
        );
    }

    private static function host_list( $urls ) {
        $hosts = array();
        foreach ( (array) $urls as $url ) {
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            if ( $host !== '' ) {
                $hosts[ $host ] = true;
            }
        }
        return array_keys( $hosts );
    }

    private static function log_ingest( $options, $status, $message, $context ) {
        if ( empty( $options['receiver_enable_log'] ) ) {
            return;
        }
        $context = self::compact_log_context( $status, $context );
        $stored = Xlocal_Bridge_Settings::get_options();
        $existing = isset( $stored['receiver_debug_log_history'] ) ? (string) $stored['receiver_debug_log_history'] : '';
        $lines = array_filter( explode( "\n", $existing ) );
        $entry = array(
            'timestamp_utc' => gmdate( 'Y-m-d H:i:s' ),
            'status' => sanitize_key( (string) $status ),
            'message' => sanitize_text_field( (string) $message ),
            'context' => is_array( $context ) ? $context : array(),
        );
        $line = wp_json_encode( $entry );
        if ( is_string( $line ) && $line !== '' ) {
            $lines[] = $line;
        }
        $retain = isset( $stored['receiver_retain_logs_days'] ) ? intval( $stored['receiver_retain_logs_days'] ) : 30;
        $cap = max( 50, min( 1000, $retain * 20 ) );
        $lines = array_slice( $lines, -$cap );
        $stored['receiver_debug_log_history'] = implode( "\n", $lines );
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $stored );
    }

    private static function compact_log_context( $status, $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }

        $is_success = sanitize_key( (string) $status ) === 'success';
        if ( $is_success ) {
            unset( $context['payload_categories'] );
            unset( $context['normalized_categories'] );
            unset( $context['payload_tags'] );
            unset( $context['normalized_tags'] );
            unset( $context['category_term_ids'] );
        }

        // Trim large arrays for cleaner production logs while keeping signal.
        foreach ( array( 'content_image_hosts', 'manifest_hosts' ) as $host_key ) {
            if ( isset( $context[ $host_key ] ) && is_array( $context[ $host_key ] ) && count( $context[ $host_key ] ) > 10 ) {
                $context[ $host_key ] = array_slice( $context[ $host_key ], 0, 10 );
                $context[ $host_key . '_truncated' ] = true;
            }
        }

        $clean = array();
        foreach ( $context as $key => $value ) {
            if ( is_string( $value ) && trim( $value ) === '' ) {
                continue;
            }
            if ( is_array( $value ) && empty( $value ) ) {
                continue;
            }
            if ( $value === null ) {
                continue;
            }
            $clean[ sanitize_key( (string) $key ) ] = $value;
        }
        return $clean;
    }

    private static function allowed_tags_profile( $options ) {
        $profile = $options['receiver_allowed_profile'];
        if ( $profile === 'custom' ) {
            $custom = json_decode( $options['receiver_custom_allowed'], true );
            if ( is_array( $custom ) ) {
                return $custom;
            }
        }
        if ( $profile === 'strict' ) {
            return array(
                'p' => array(),
                'br' => array(),
                'strong' => array(),
                'em' => array(),
                'a' => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ),
                'img' => array(
                    'src' => true,
                    'srcset' => true,
                    'sizes' => true,
                    'data-src' => true,
                    'data-srcset' => true,
                    'alt' => true,
                    'width' => true,
                    'height' => true,
                    'loading' => true,
                    'decoding' => true,
                    'fetchpriority' => true,
                    'class' => true,
                ),
                'ul' => array(),
                'ol' => array(),
                'li' => array(),
                'blockquote' => array(),
            );
        }
        return wp_kses_allowed_html( 'post' );
    }

    private static function find_post_by_meta( $meta_key, $meta_value, $post_type ) {
        $query = new WP_Query(
            array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => array(
                    array(
                        'key' => $meta_key,
                        'value' => $meta_value,
                    ),
                ),
            )
        );
        if ( ! empty( $query->posts ) ) {
            return intval( $query->posts[0] );
        }
        return 0;
    }

    private static function find_post_by_meta_two( $meta_key_a, $meta_value_a, $meta_key_b, $meta_value_b, $post_type ) {
        $query = new WP_Query(
            array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => array(
                    array(
                        'key' => $meta_key_a,
                        'value' => $meta_value_a,
                    ),
                    array(
                        'key' => $meta_key_b,
                        'value' => $meta_value_b,
                    ),
                ),
            )
        );
        if ( ! empty( $query->posts ) ) {
            return intval( $query->posts[0] );
        }
        return 0;
    }
}
