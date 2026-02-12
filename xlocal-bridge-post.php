<?php
/**
 * Plugin Name: xLocal Bridge Post
 * Description: Unified Sender + Receiver bridge for pushing finalized content with CDN-only media URLs.
 * Plugin URI: https://github.com/h20ray/xlocal-bridge-post
 * Update URI: https://github.com/h20ray/xlocal-bridge-post
 * Version: 0.5.2
 * Author: JagaWarta
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'XLOCAL_BRIDGE_POST_VERSION' ) ) {
    define( 'XLOCAL_BRIDGE_POST_VERSION', '0.5.2' );
}
if ( ! defined( 'XLOCAL_BRIDGE_POST_FILE' ) ) {
    define( 'XLOCAL_BRIDGE_POST_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/class-xlocal-bridge-loader.php';
Xlocal_Bridge_Loader::load_all();

class Xlocal_Bridge_Post {
    public static function init() {
        Xlocal_Bridge_Settings::init();
        Xlocal_Bridge_Receiver::init();
        Xlocal_Bridge_Sender::init();
        Xlocal_Bridge_Updater::init();
    }
}

Xlocal_Bridge_Post::init();
