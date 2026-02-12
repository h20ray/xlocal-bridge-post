<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Layout_Trait {
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = self::get_options();
        $mode = $options['mode'];

        $tabs = array(
            'overview' => array(
                'label' => 'Overview',
                'desc' => 'Choose mode and verify essentials before sending or receiving.',
            ),
        );
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            $tabs['receiver'] = array(
                'label' => 'Receiver',
                'desc' => 'Security, content policy, and media rules for ingest.',
            );
            $tabs['receiver_debug'] = array(
                'label' => 'Receiver Debug',
                'desc' => 'Content-aware ingest diagnostics.',
            );
        }
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $tabs['sender'] = array(
                'label' => 'Sender',
                'desc' => 'Endpoint, publishing, and CDN output rules.',
            );
        }
        $tabs['advanced'] = array(
            'label' => 'Advanced',
            'desc' => 'Sanitization, dedup, and logging controls.',
        );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $tabs['sender_debug'] = array(
                'label' => 'Sender Debug',
                'desc' => 'Payload-level sender diagnostics.',
            );
        }
        $tabs['logs'] = array(
            'label' => 'Logs',
            'desc' => 'Diagnostics and update status.',
        );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $tabs['bulk_send'] = array(
                'label' => 'Bulk Send',
                'desc' => 'Backfill old posts with deterministic, test-friendly sending.',
            );
        }
        $tabs['documentation'] = array(
            'label' => 'Documentation',
            'desc' => 'Step-by-step guide for non-technical users.',
        );

        echo '<div class="wrap xlocal-admin">';
        echo '<div class="xlocal-hero">';
        echo '<div class="xlocal-hero-text">';
        echo '<span class="xlocal-chip">Bridge</span>';
        echo '<h1>xLocal Bridge Post</h1>';
        echo '<p>Unified Sender + Receiver settings with clarity and control.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="xlocal-layout">';
        echo '<form method="post" action="options.php" class="xlocal-card xlocal-elevated">';
        settings_fields( 'xlocal_bridge_post' );

        echo '<div class="xlocal-tabs" role="tablist" aria-label="xLocal settings tabs">';
        foreach ( $tabs as $id => $tab ) {
            printf(
                '<button type="button" class="xlocal-tab" data-tab="%1$s" id="xlocal-tab-%1$s" role="tab" aria-controls="xlocal-panel-%1$s" aria-selected="false" tabindex="-1">%2$s</button>',
                esc_attr( $id ),
                esc_html( $tab['label'] )
            );
        }
        echo '</div>';

        self::render_overview_tab( $mode, $options );
        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            self::render_receiver_tab();
            self::render_receiver_debug_tab( isset( $options['receiver_debug_log_history'] ) ? $options['receiver_debug_log_history'] : '' );
        }
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_sender_tab();
        }
        self::render_advanced_tab( $mode );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_sender_debug_tab(
                $options['sender_last_push_result'],
                isset( $options['sender_debug_log_history'] ) ? $options['sender_debug_log_history'] : '',
                isset( $options['sender_debug_payload_history'] ) ? $options['sender_debug_payload_history'] : ''
            );
        }
        self::render_logs_tab( $mode );
        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            self::render_bulk_send_tab( $options );
        }
        self::render_documentation_tab();

        submit_button();
        echo '</form>';

        echo '<div class="xlocal-card xlocal-side">';
        echo '<section class="xlocal-side-section">';
        echo '<h2>Status</h2>';
        self::render_status_badges( $options );
        echo '</section>';
        echo '<section class="xlocal-side-section">';
        echo '<h2>Quick Checklist</h2>';
        echo '<ul>';
        echo '<li>Mode set correctly for this site.</li>';
        if ( $mode === 'sender' ) {
            echo '<li>Sender endpoint points to receiver site.</li>';
            echo '<li>Sender secret matches receiver secret.</li>';
            echo '<li>Send Test Payload succeeds before enabling Auto Send.</li>';
        } elseif ( $mode === 'receiver' ) {
            echo '<li>Receiver is enabled and secret is configured.</li>';
            echo '<li>Allowed media domains are set correctly.</li>';
            echo '<li>TLS requirement and rate limit are configured for your environment.</li>';
        } else {
            echo '<li>Secrets match between sender and receiver.</li>';
            echo '<li>CDN domain allowlisted on receiver.</li>';
            echo '<li>Test payload succeeds.</li>';
        }
        echo '</ul>';
        echo '<div class="xlocal-note"><strong>Tip:</strong> Use <code>wp-config.php</code> to lock the shared secret.</div>';
        echo '</section>';
        echo '<section class="xlocal-side-section">';
        echo '<h2>About</h2>';
        echo '<p>xLocal Bridge Post securely syncs content between WordPress sites with sender and receiver control.</p>';
        echo '</section>';
        echo '<section class="xlocal-side-section xlocal-author">';
        echo '<h2>Author</h2>';
        echo '<p><strong>Andoru Ray</strong></p>';
        echo '<p><a href="mailto:andoru@tujuhcahaya.com">andoru@tujuhcahaya.com</a></p>';
        echo '</section>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }
    private static function render_status_badges( $options ) {
        $badges = array();
        $mode = $options['mode'];
        $badges[] = array( 'label' => 'Mode: ' . ucfirst( $mode ), 'state' => 'ok' );
        if ( class_exists( 'Xlocal_Bridge_Updater' ) ) {
            $updater_ok = Xlocal_Bridge_Updater::is_repo_configured();
            $updater_channel = Xlocal_Bridge_Updater::channel_label();
            $badges[] = array(
                'label' => $updater_ok ? 'Updater: Configured (' . $updater_channel . ')' : 'Updater: GitHub Repo Missing',
                'state' => $updater_ok ? 'ok' : 'warn',
            );
        }

        if ( in_array( $mode, array( 'receiver', 'both' ), true ) ) {
            $badges[] = array( 'label' => $options['receiver_enabled'] ? 'Receiver Enabled' : 'Receiver Disabled', 'state' => $options['receiver_enabled'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'Receiver Secret Set' : 'Receiver Secret Missing', 'state' => Xlocal_Bridge_Settings::get_receiver_secret() ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['receiver_allowed_media_domains'] ? 'CDN Allowlist Set' : 'CDN Allowlist Missing', 'state' => $options['receiver_allowed_media_domains'] ? 'ok' : 'warn' );
        }

        if ( in_array( $mode, array( 'sender', 'both' ), true ) ) {
            $badges[] = array( 'label' => $options['sender_auto_send'] ? 'Sender Auto Send Enabled' : 'Sender Auto Send Disabled', 'state' => $options['sender_auto_send'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['sender_main_base_url'] ? 'Sender Endpoint Set' : 'Sender Endpoint Missing', 'state' => $options['sender_main_base_url'] ? 'ok' : 'warn' );
            $badges[] = array( 'label' => Xlocal_Bridge_Settings::get_sender_secret() ? 'Sender Secret Set' : 'Sender Secret Missing', 'state' => Xlocal_Bridge_Settings::get_sender_secret() ? 'ok' : 'warn' );
            $badges[] = array( 'label' => $options['sender_cdn_base'] ? 'CDN Base Set' : 'CDN Base Missing', 'state' => $options['sender_cdn_base'] ? 'ok' : 'warn' );
        }

        echo '<div class="xlocal-status-row">';
        foreach ( $badges as $badge ) {
            $class = $badge['state'] === 'ok' ? 'xlocal-status xlocal-status-ok' : 'xlocal-status xlocal-status-warn';
            printf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $badge['label'] ) );
        }
        echo '</div>';
    }
}
