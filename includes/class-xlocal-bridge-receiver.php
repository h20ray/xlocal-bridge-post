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
            return new WP_REST_Response( array( 'success' => false, 'error' => 'receiver_disabled' ), 503 );
        }

        if ( ! empty( $options['receiver_require_tls'] ) && ! is_ssl() ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'tls_required' ), 400 );
        }

        $secret = Xlocal_Bridge_Settings::get_receiver_secret();
        if ( empty( $secret ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_secret' ), 500 );
        }

        if ( ! self::check_rate_limit( $options ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'rate_limited' ), 429 );
        }

        if ( ! self::check_ip_allowlist( $options ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'ip_not_allowed' ), 403 );
        }

        $raw_body = file_get_contents( 'php://input' );
        if ( $raw_body === false ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'empty_body' ), 400 );
        }
        $max_kb = max( 64, intval( $options['receiver_max_payload_kb'] ) );
        if ( strlen( $raw_body ) > ( $max_kb * 1024 ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'payload_too_large' ), 413 );
        }

        $timestamp = isset( $_SERVER['HTTP_X_XLOCAL_TIMESTAMP'] ) ? intval( $_SERVER['HTTP_X_XLOCAL_TIMESTAMP'] ) : 0;
        $nonce = isset( $_SERVER['HTTP_X_XLOCAL_NONCE'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_NONCE'] ) : '';
        $signature = isset( $_SERVER['HTTP_X_XLOCAL_SIGNATURE'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_SIGNATURE'] ) : '';
        $origin_host = isset( $_SERVER['HTTP_X_XLOCAL_ORIGIN_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_X_XLOCAL_ORIGIN_HOST'] ) : '';
        $local_host = wp_parse_url( home_url(), PHP_URL_HOST );

        if ( $origin_host !== '' && $local_host && strtolower( $origin_host ) === strtolower( $local_host ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'self_origin_rejected' ), 409 );
        }

        if ( ! self::verify_timestamp_nonce( $timestamp, $nonce, $options ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_timestamp_or_nonce' ), 401 );
        }

        if ( ! self::verify_signature( $secret, $timestamp, $nonce, $raw_body, $signature ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_signature' ), 401 );
        }
        self::remember_nonce( $nonce, $options );

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_json' ), 400 );
        }

        $required = array( 'ingest_id', 'source_url', 'title', 'content_html' );
        foreach ( $required as $field ) {
            if ( empty( $payload[ $field ] ) ) {
                return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_field', 'field' => $field ), 400 );
            }
        }

        if ( ! self::validate_media_domains( $payload, $options ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'media_domain_not_allowed' ), 400 );
        }

        $post_id = self::upsert_post( $payload, $options );
        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => $post_id->get_error_message() ), 500 );
        }

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

        if ( ! empty( $payload['featured_image'] ) && is_array( $payload['featured_image'] ) ) {
            self::apply_featured_image( $post_id, $payload['featured_image'], $options );
        }

        if ( ! empty( $payload['media_manifest'] ) && is_array( $payload['media_manifest'] ) ) {
            update_post_meta( $post_id, '_xlocal_media_manifest', wp_json_encode( $payload['media_manifest'] ) );
        }

        update_post_meta( $post_id, self::META_LAST_INGESTED_AT, $now_gmt );

        self::apply_taxonomies( $post_id, $payload, $options );

        return array( 'post_id' => $post_id, 'action' => $action );
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
        if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
            $cats = self::normalize_terms( $payload['categories'], $options['receiver_category_mapping_rules'], $options['receiver_tag_normalization'] );
            if ( $options['receiver_auto_create_categories'] ) {
                self::ensure_terms_exist( $cats, 'category' );
            }
            wp_set_post_terms( $post_id, $cats, 'category', false );
        }

        if ( ! empty( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
            $tags = self::normalize_terms( $payload['tags'], '', $options['receiver_tag_normalization'] );
            if ( $options['receiver_auto_create_tags'] ) {
                self::ensure_terms_exist( $tags, 'post_tag' );
            }
            wp_set_post_terms( $post_id, $tags, 'post_tag', false );
        }
    }

    private static function normalize_terms( $terms, $mapping_rules, $normalize ) {
        $terms = array_map( 'sanitize_text_field', $terms );
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
        self::store_featured_meta( $post_id, $image );

        $mode = isset( $options['receiver_featured_image_mode'] ) ? $options['receiver_featured_image_mode'] : 'meta_only';
        update_post_meta( $post_id, '_xlocal_featured_image_mode', sanitize_key( $mode ) );
        if ( $mode !== 'virtual_attachment' ) {
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'meta_only' );
            return;
        }

        if ( empty( $image['url'] ) ) {
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'missing_url' );
            return;
        }

        $attachment_id = self::find_attachment_by_source_url( $image['url'] );
        if ( ! $attachment_id ) {
            $attachment_id = self::sideload_featured_attachment( $image['url'], $post_id, isset( $image['alt'] ) ? $image['alt'] : '' );
        }
        if ( $attachment_id ) {
            set_post_thumbnail( $post_id, $attachment_id );
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'attached:' . intval( $attachment_id ) );
        } else {
            update_post_meta( $post_id, '_xlocal_featured_image_ingest_status', 'attachment_failed' );
        }
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

        $attachment_id = media_sideload_image( esc_url_raw( $url ), $post_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            return 0;
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
