<?php
/**
 * Plugin Name: xLocal Bridge Post
 * Description: Unified Sender + Receiver bridge for pushing finalized content with CDN-only media URLs.
 * Version: 0.5.1
 * Author: JagaWarta
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'XLOCAL_BRIDGE_POST_VERSION' ) ) {
    define( 'XLOCAL_BRIDGE_POST_VERSION', '0.5.1' );
}
if ( ! defined( 'XLOCAL_BRIDGE_POST_FILE' ) ) {
    define( 'XLOCAL_BRIDGE_POST_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/class-xlocal-bridge-settings.php';
require_once __DIR__ . '/includes/class-xlocal-bridge-receiver.php';
require_once __DIR__ . '/includes/class-xlocal-bridge-sender.php';
require_once __DIR__ . '/includes/class-xlocal-bridge-updater.php';

class Xlocal_Bridge_Post {
    public static function init() {
        Xlocal_Bridge_Settings::init();
        Xlocal_Bridge_Receiver::init();
        Xlocal_Bridge_Sender::init();
        Xlocal_Bridge_Updater::init();
    }
}

Xlocal_Bridge_Post::init();
