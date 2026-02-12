<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/Traits/trait-xlocal-bridge-settings-store.php';
require_once __DIR__ . '/Traits/trait-xlocal-bridge-settings-page.php';

class Xlocal_Bridge_Settings {
    use Xlocal_Bridge_Settings_Store_Trait;
    use Xlocal_Bridge_Settings_Page_Trait;

    const OPTION_KEY = 'xlocal_bridge_post';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::init();
        }
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

    // Backward-compatible wrappers for legacy callbacks.
    public static function handle_test_payload() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_test_payload();
        }
    }

    public static function handle_bulk_send() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_bulk_send();
        }
    }

    public static function handle_bulk_import() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_bulk_import();
        }
    }

    public static function handle_check_updates_now() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_check_updates_now();
        }
    }

    public static function handle_update_plugin_now() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_update_plugin_now();
        }
    }

    public static function handle_clear_sender_debug_logs() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_clear_sender_debug_logs();
        }
    }

    public static function handle_clear_receiver_debug_logs() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::handle_clear_receiver_debug_logs();
        }
    }

    public static function admin_notices() {
        if ( class_exists( 'Xlocal_Bridge_Admin_Action_Service' ) ) {
            Xlocal_Bridge_Admin_Action_Service::admin_notices();
        }
    }
}
