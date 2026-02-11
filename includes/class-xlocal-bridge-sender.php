<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Sender {
    public static function init() {
        // Placeholder: hook into worker pipeline later.
    }

    public static function send_payload( $endpoint, $secret, $payload, $options ) {
        $body = wp_json_encode( $payload );
        $timestamp = time();
        $nonce = wp_generate_password( 24, false, false );
        $signature = hash_hmac( 'sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret );

        $args = array(
            'method' => 'POST',
            'timeout' => intval( $options['sender_timeout'] ),
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Xlocal-Timestamp' => $timestamp,
                'X-Xlocal-Nonce' => $nonce,
                'X-Xlocal-Signature' => $signature,
            ),
            'body' => $body,
        );

        $response = wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            Xlocal_Bridge_Settings::get_options();
            self::store_last_result( $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        self::store_last_result( 'HTTP ' . $code . "\n" . $response_body );

        return array(
            'code' => $code,
            'body' => $response_body,
        );
    }

    private static function store_last_result( $text ) {
        $options = Xlocal_Bridge_Settings::get_options();
        $options['sender_last_push_result'] = $text;
        update_option( Xlocal_Bridge_Settings::OPTION_KEY, $options );
    }
}
