<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Admin_Action_Service {
    public static function init() {
        add_action( 'admin_post_xlocal_sender_test', array( __CLASS__, 'handle_test_payload' ) );
        add_action( 'admin_post_xlocal_bulk_send_run', array( __CLASS__, 'handle_bulk_send' ) );
        add_action( 'admin_post_xlocal_check_updates_now', array( __CLASS__, 'handle_check_updates_now' ) );
        add_action( 'admin_post_xlocal_update_plugin_now', array( __CLASS__, 'handle_update_plugin_now' ) );
        add_action( 'admin_post_xlocal_clear_sender_debug_logs', array( __CLASS__, 'handle_clear_sender_debug_logs' ) );
        add_action( 'admin_post_xlocal_clear_receiver_debug_logs', array( __CLASS__, 'handle_clear_receiver_debug_logs' ) );
        add_action( 'admin_post_xlocal_bulk_import_run', array( __CLASS__, 'handle_bulk_import' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
    }

    public static function handle_test_payload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'xlocal_sender_test' );
        $options = Xlocal_Bridge_Settings::get_options();
        $endpoint = rtrim( $options['sender_main_base_url'], '/' ) . $options['sender_ingest_path'];
        $secret = Xlocal_Bridge_Settings::get_sender_secret();
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
        Xlocal_Bridge_Sender::record_debug_payload_snapshot( 'manual_test', 0, $payload, $options, $endpoint );
        $result = Xlocal_Bridge_Sender::send_payload( $endpoint, $secret, $payload, $options );
        if ( is_wp_error( $result ) ) {
            self::set_notice( $result->get_error_message(), 'error' );
        } else {
            self::set_notice( 'Test payload sent. Response code: ' . intval( $result['code'] ), 'success' );
        }
        wp_safe_redirect( add_query_arg( 'xlocal_tab', 'sender_debug', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
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
        if ( isset( $_POST['xlocal_bulk_import_nonce'] ) && wp_verify_nonce( $_POST['xlocal_bulk_import_nonce'], 'xlocal_bulk_import' ) ) {
            $valid_nonce = true;
        }
        if ( ! $valid_nonce ) {
            self::set_notice( 'Bulk Send security check failed (invalid nonce). Please refresh the settings page and try again.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $options = Xlocal_Bridge_Settings::get_options();

        if ( $options['mode'] === 'receiver' ) {
            self::set_notice( 'Bulk Send is only available on Sender sites.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $endpoint = rtrim( $options['sender_main_base_url'], '/' ) . $options['sender_ingest_path'];
        $secret   = Xlocal_Bridge_Settings::get_sender_secret();
        if ( ! wp_http_validate_url( $endpoint ) || empty( $secret ) ) {
            self::set_notice( 'Bulk Send requires a valid sender endpoint and secret.', 'error' );
            wp_safe_redirect( admin_url( 'options-general.php?page=xlocal-bridge-post' ) );
            exit;
        }

        $input = isset( $_POST[ Xlocal_Bridge_Settings::OPTION_KEY ] ) && is_array( $_POST[ Xlocal_Bridge_Settings::OPTION_KEY ] ) ? $_POST[ Xlocal_Bridge_Settings::OPTION_KEY ] : array();

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

        $options['bulk_post_type']  = $bulk_post_type;
        $options['bulk_status']     = $bulk_status;
        $options['bulk_date_after'] = $bulk_date_after;
        $options['bulk_batch_size'] = $bulk_batch_size;
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );

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

    public static function handle_check_updates_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( (string) $_REQUEST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'xlocal_check_updates_now' ) ) {
            self::set_notice( 'Security token expired. Please click "Check Latest Updates Now" again.', 'error' );
            wp_safe_redirect( add_query_arg( 'xlocal_tab', 'logs', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
            exit;
        }

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
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( (string) $_REQUEST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'xlocal_update_plugin_now' ) ) {
            self::set_notice( 'Security token expired. Please open Logs tab and click "Open Core Plugin Updates" again.', 'error' );
            wp_safe_redirect( add_query_arg( 'xlocal_tab', 'logs', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
            exit;
        }

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

        self::set_notice( 'Update metadata refreshed. Continue update from WordPress core Plugin Updates screen.', 'success' );
        wp_safe_redirect( self_admin_url( 'update-core.php' ) );
        exit;
    }

    public static function handle_clear_sender_debug_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( (string) $_REQUEST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'xlocal_clear_sender_debug_logs' ) ) {
            self::set_notice( 'Security token expired. Please retry from the Sender Debug tab.', 'error' );
            wp_safe_redirect( add_query_arg( 'xlocal_tab', 'sender_debug', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
            exit;
        }

        $options = Xlocal_Bridge_Settings::get_options();
        $options['sender_debug_log_history'] = '';
        $options['sender_debug_payload_history'] = '';
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );

        self::set_notice( 'Sender debug data cleared.', 'success' );
        $tab = in_array( $options['mode'], array( 'sender', 'both' ), true ) ? 'sender_debug' : 'logs';
        wp_safe_redirect( add_query_arg( 'xlocal_tab', $tab, admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
        exit;
    }

    public static function handle_clear_receiver_debug_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( (string) $_REQUEST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'xlocal_clear_receiver_debug_logs' ) ) {
            self::set_notice( 'Security token expired. Please retry from the Receiver Debug tab.', 'error' );
            wp_safe_redirect( add_query_arg( 'xlocal_tab', 'receiver_debug', admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
            exit;
        }

        $options = Xlocal_Bridge_Settings::get_options();
        $options['receiver_debug_log_history'] = '';
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );

        self::set_notice( 'Receiver debug data cleared.', 'success' );
        $tab = in_array( $options['mode'], array( 'receiver', 'both' ), true ) ? 'receiver_debug' : 'logs';
        wp_safe_redirect( add_query_arg( 'xlocal_tab', $tab, admin_url( 'options-general.php?page=xlocal-bridge-post' ) ) );
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
