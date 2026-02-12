<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Tabs_Debug_Logs_Trait {
    private static function render_sender_debug_tab( $last_push_result, $sender_debug_log_history, $sender_debug_payload_history ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="sender_debug" id="xlocal-panel-sender_debug" role="tabpanel" aria-labelledby="xlocal-tab-sender_debug">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Sender Debug</h2>';
        echo '<p>Dedicated sender transport and payload diagnostics for deep analysis.</p>';
        echo '</div>';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Last Push Result</th><td>';
        printf( '<textarea readonly rows="6">%s</textarea>', esc_textarea( $last_push_result ) );
        echo '</td></tr>';

        echo '<tr><th scope="row">Sender Event Log</th><td>';
        $lines = array_filter( explode( "\n", (string) $sender_debug_log_history ) );
        if ( empty( $lines ) ) {
            echo '<p>No sender debug entries yet.</p>';
        } else {
            $lines = array_reverse( $lines );
            echo '<table class="widefat striped" style="max-height:20rem;overflow:auto;display:block;">';
            echo '<thead><tr><th style="width:14rem;">Timestamp</th><th>Message</th></tr></thead><tbody>';
            foreach ( $lines as $line ) {
                $line = (string) $line;
                $timestamp = '';
                $message = $line;
                $parts = explode( ' UTC - ', $line, 2 );
                if ( count( $parts ) === 2 ) {
                    $timestamp = $parts[0] . ' UTC';
                    $message = $parts[1];
                }
                echo '<tr>';
                echo '<td><code>' . esc_html( $timestamp ) . '</code></td>';
                echo '<td>' . esc_html( $message ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">Payload Snapshots</th><td>';
        $payload_lines = array_filter( explode( "\n", (string) $sender_debug_payload_history ) );
        if ( empty( $payload_lines ) ) {
            echo '<p>No payload snapshots yet. Enable "Sender Debug Logs", then send one post.</p>';
        } else {
            $payload_lines = array_reverse( $payload_lines );
            foreach ( $payload_lines as $line ) {
                $entry = json_decode( $line, true );
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $context = ! empty( $entry['context'] ) ? sanitize_text_field( (string) $entry['context'] ) : 'send';
                $post_id = isset( $entry['post_id'] ) ? intval( $entry['post_id'] ) : 0;
                $timestamp = ! empty( $entry['timestamp_utc'] ) ? sanitize_text_field( (string) $entry['timestamp_utc'] ) . ' UTC' : '-';
                $size_bytes = isset( $entry['payload_size_bytes'] ) ? intval( $entry['payload_size_bytes'] ) : 0;
                $endpoint = ! empty( $entry['endpoint'] ) ? esc_url_raw( (string) $entry['endpoint'] ) : '';
                $payload = isset( $entry['payload'] ) && is_array( $entry['payload'] ) ? $entry['payload'] : array();
                echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:12px;margin-bottom:12px;background:#fff;">';
                echo '<p><strong>' . esc_html( strtoupper( $context ) ) . '</strong> | Post ID: <code>' . esc_html( strval( $post_id ) ) . '</code> | ' . esc_html( $timestamp ) . ' | Size: <code>' . esc_html( strval( $size_bytes ) ) . ' bytes</code></p>';
                if ( $endpoint !== '' ) {
                    echo '<p>Endpoint: <code>' . esc_html( $endpoint ) . '</code></p>';
                }
                echo '<textarea readonly rows="14" style="font-family:Menlo,Consolas,monospace;">' . esc_textarea( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea>';
                echo '</div>';
            }
        }
        $clear_debug_logs_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_clear_sender_debug_logs' ), 'xlocal_clear_sender_debug_logs' );
        echo '<p><a href="' . esc_url( $clear_debug_logs_url ) . '" class="button button-secondary">Clear Sender Debug Data</a></p>';
        echo '<p class="xlocal-field-hint">This clears sender event logs and payload snapshots. Author email is masked in snapshots.</p>';
        echo '</td></tr>';
        echo '</table>';
        echo '</div>';
    }

    private static function render_logs_tab( $mode ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="logs" id="xlocal-panel-logs" role="tabpanel" aria-labelledby="xlocal-tab-logs">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Logs</h2>';
        echo '<p>System diagnostics and updater status.</p>';
        echo '</div>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">System Diagnostics</th><td>';
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            echo '<p>Sender payload/event diagnostics are now in the dedicated <strong>Sender Debug</strong> tab.</p>';
        } else {
            echo '<p>Receiver mode active. Use this tab for updater and system diagnostics.</p>';
        }
        echo '</td></tr>';
        if ( class_exists( 'Xlocal_Bridge_Updater' ) ) {
            $snapshot = Xlocal_Bridge_Updater::status_snapshot();
            $cached_version = ! empty( $snapshot['cached_version'] ) ? $snapshot['cached_version'] : '-';
            $cached_commit = ! empty( $snapshot['cached_commit'] ) ? substr( $snapshot['cached_commit'], 0, 12 ) : '-';
            $installed_commit = ! empty( $snapshot['installed_commit'] ) ? substr( $snapshot['installed_commit'], 0, 12 ) : '-';
            $update_available = ! empty( $snapshot['update_available'] );
            $new_version = ! empty( $snapshot['new_version'] ) ? $snapshot['new_version'] : '';
            $action_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_check_updates_now' ), 'xlocal_check_updates_now' );

            echo '<tr><th scope="row">Updater Status</th><td>';
            echo '<p>Installed Commit: <code>' . esc_html( $installed_commit ) . '</code></p>';
            echo '<p>Latest Cached Commit: <code>' . esc_html( $cached_commit ) . '</code>, Version: <code>' . esc_html( $cached_version ) . '</code></p>';
            if ( $update_available ) {
                $upgrade_url = wp_nonce_url( admin_url( 'admin-post.php?action=xlocal_update_plugin_now' ), 'xlocal_update_plugin_now' );
                echo '<p><strong>Update Available:</strong> <code>' . esc_html( $new_version ) . '</code></p>';
                echo '<p><a href="' . esc_url( $upgrade_url ) . '" class="button button-primary">Open Core Plugin Updates</a></p>';
            } else {
                echo '<p><strong>Update Available:</strong> <code>No</code></p>';
            }
            echo '<p><a href="' . esc_url( $action_url ) . '" class="button button-secondary">Check Latest Updates Now</a></p>';
            echo '<p class="xlocal-field-hint">Forces GitHub + WordPress update refresh. Use "Open Core Plugin Updates" to update from WordPress core updater screen.</p>';
            echo '</td></tr>';
        }
        echo '</table>';
        echo '</div>';
    }

}
