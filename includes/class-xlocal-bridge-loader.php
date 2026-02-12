<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Loader {
    public static function files() {
        return array(
            __DIR__ . '/Admin/class-xlocal-bridge-admin-settings.php',
            __DIR__ . '/Admin/Services/class-xlocal-bridge-admin-action-service.php',
            __DIR__ . '/Core/class-xlocal-bridge-core-receiver.php',
            __DIR__ . '/Core/class-xlocal-bridge-core-sender.php',
            __DIR__ . '/Core/class-xlocal-bridge-core-updater.php',
        );
    }

    public static function load_all() {
        foreach ( self::files() as $file ) {
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
    }
}
