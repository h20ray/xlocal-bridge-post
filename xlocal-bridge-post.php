<?php
/**
 * Plugin Name: xLocal Bridge Post
 * Description: Unified Sender + Receiver bridge for pushing finalized content with CDN-only media URLs.
 * Version: 0.4.0
 * Author: JagaWarta
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-xlocal-bridge-settings.php';
require_once __DIR__ . '/includes/class-xlocal-bridge-receiver.php';
require_once __DIR__ . '/includes/class-xlocal-bridge-sender.php';

class Xlocal_Bridge_Post {
    public static function init() {
        Xlocal_Bridge_Settings::init();
        Xlocal_Bridge_Receiver::init();
        Xlocal_Bridge_Sender::init();
    }
}

Xlocal_Bridge_Post::init();
