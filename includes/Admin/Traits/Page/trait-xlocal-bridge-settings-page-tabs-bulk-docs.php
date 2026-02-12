<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Xlocal_Bridge_Settings_Page_Tabs_Bulk_Docs_Trait {
    private static function render_bulk_send_tab( $options ) {
        echo '<div class="xlocal-tab-panel" data-tab-panel="bulk_send" id="xlocal-panel-bulk_send" role="tabpanel" aria-labelledby="xlocal-tab-bulk_send">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Bulk Send</h2>';
        echo '<p>Premium backfill flow for safely sending older posts from this sender site to your main site.</p>';
        echo '</div>';

        $mode = isset( $options['mode'] ) ? $options['mode'] : 'both';

        if ( $mode === 'receiver' ) {
            echo '<p><strong>Bulk Send only runs on Sender sites.</strong> Set this site to Sender or Both in the Overview tab to enable.</p>';
            echo '</div>';
            return;
        }

        $bulk_post_type  = isset( $options['bulk_post_type'] ) ? sanitize_key( $options['bulk_post_type'] ) : $options['sender_target_post_type'];
        $bulk_status     = isset( $options['bulk_status'] ) ? sanitize_key( $options['bulk_status'] ) : 'publish';
        $bulk_date_after = isset( $options['bulk_date_after'] ) ? sanitize_text_field( $options['bulk_date_after'] ) : '';
        $bulk_batch_size = isset( $options['bulk_batch_size'] ) ? intval( $options['bulk_batch_size'] ) : 25;

        $allowed_statuses = array( 'publish', 'pending', 'draft' );
        if ( ! in_array( $bulk_status, $allowed_statuses, true ) ) {
            $bulk_status = 'publish';
        }

        if ( $bulk_batch_size < 1 ) {
            $bulk_batch_size = 25;
        }

        echo '<p class="xlocal-note"><strong>Confidence:</strong> Bulk Send runs oldest-first and only selects unsent posts, so each run advances deterministically. This makes it ideal for testing with batch size <code>1</code>.</p>';

        // Nonce stays inside main options form so Bulk Send can post to admin-post safely.
        wp_nonce_field( 'xlocal_bulk_send', 'xlocal_bulk_send_nonce' );
        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row">Current Sender Defaults</th>';
        echo '<td>';
        echo '<p>Post type: <code>' . esc_html( $options['sender_target_post_type'] ) . '</code></p>';
        echo '<p>Status to send: <code>' . esc_html( $options['sender_default_status'] ) . '</code></p>';
        echo '<p class="xlocal-field-hint">These are your normal sender settings. Bulk Send filters and sends based on your selection below.</p>';
        echo '</td><td class="xlocal-help-cell"></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Bulk Post Type</th>';
        echo '<td>';
        printf(
            '<input type="text" name="%s[bulk_post_type]" value="%s" class="regular-text" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_post_type )
        );
        echo '<p class="xlocal-field-hint">Usually keep this the same as Sender Target Post Type.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Only posts of this type are selected and sent in this run.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Bulk Status</th>';
        echo '<td>';
        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[bulk_status]">';
        foreach ( $allowed_statuses as $status ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $status ),
                selected( $bulk_status, $status, false ),
                esc_html( ucfirst( $status ) )
            );
        }
        echo '</select>';
        echo '<p class="xlocal-field-hint">Recommended: publish. Only posts with this status will be sent.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Use publish for live content, or pending/draft if you only want staged items.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Only Posts After Date</th>';
        echo '<td>';
        printf(
            '<input type="date" name="%s[bulk_date_after]" value="%s" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_date_after )
        );
        echo '<p class="xlocal-field-hint">Optional: limit Bulk Send to posts newer than this date.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Leave empty to include all matching posts, or set a cut-off date for safer rollout.</span></span></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Batch Size</th>';
        echo '<td>';
        printf(
            '<input type="number" name="%s[bulk_batch_size]" value="%d" class="small-text" min="1" max="200" />',
            esc_attr( self::OPTION_KEY ),
            esc_attr( $bulk_batch_size )
        );
        echo '<p class="xlocal-field-hint">How many posts to process in one Bulk Send run. For testing, start with <code>1</code>.</p>';
        echo '</td>';
        echo '<td class="xlocal-help-cell"><span class="xlocal-help"><span class="xlocal-help-icon">i</span><span class="xlocal-help-text">Re-run as needed. Each run picks the next oldest unsent posts that match the filters.</span></span></td>';
        echo '</tr>';

        echo '</table>';

        // Use the main settings form but override action when running Bulk Send.
        $action_url = admin_url( 'admin-post.php?action=xlocal_bulk_send_run' );
        printf(
            '<button type="submit" name="action" value="xlocal_bulk_send_run" class="button button-secondary" formaction="%s">Run Bulk Send</button>',
            esc_url( $action_url )
        );

        echo '</div>';
    }

    private static function render_documentation_tab() {
        echo '<div class="xlocal-tab-panel" data-tab-panel="documentation" id="xlocal-panel-documentation" role="tabpanel" aria-labelledby="xlocal-tab-documentation">';
        echo '<div class="xlocal-section-header">';
        echo '<h2>Documentation</h2>';
        echo '<p>Simple guide for teams that want reliable post sync without technical setup complexity.</p>';
        echo '</div>';

        echo '<div class="xlocal-doc">';

        echo '<section class="xlocal-doc-hero">';
        echo '<h3>Start Here: 10-Minute Guided Setup</h3>';
        echo '<p>Follow this exact order once, then your team can publish normally. This tutorial is written for non-technical users.</p>';
        echo '<div class="xlocal-doc-pill-row">';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-tutorial">Step-by-step</button>';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-examples">Real examples</button>';
        echo '<button type="button" class="xlocal-doc-pill" data-doc-target="xlocal-doc-troubleshooting">Troubleshooting included</button>';
        echo '</div>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>1) What this plugin does (in plain words)</h3>';
        echo '<p>This plugin sends posts from your Sender website to your Receiver website using a secure connection.</p>';
        echo '<p>Use it when content is created on one WordPress site but must appear on another site automatically.</p>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-tutorial">';
        echo '<h3>2) Guided tutorial: exact actions</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Open WordPress Admin on your destination site and set Mode to <strong>Receiver</strong>.</li>';
        echo '<li>Enable Receiver, keep Require TLS ON, then set Shared Secret (same secret must be used on sender).</li>';
        echo '<li>Fill Allowed Media Domains with your CDN host. Example: <code>cdn.example.com</code>.</li>';
        echo '<li>Save changes.</li>';
        echo '<li>Open WordPress Admin on your source site and set Mode to <strong>Sender</strong>.</li>';
        echo '<li>Turn ON Auto Send on Publish/Update.</li>';
        echo '<li>Main Site Base URL: use destination site URL. Example: <code>https://main.example.com</code>.</li>';
        echo '<li>Ingest Path: keep default unless instructed: <code>/wp-json/xlocal/v1/ingest</code>.</li>';
        echo '<li>Shared Secret: paste exactly the same secret from receiver.</li>';
        echo '<li>Save changes, then click <strong>Send Test Payload</strong>. Check Sender Debug tab for payload + response diagnostics.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-examples">';
        echo '<h3>3) Field examples you can copy</h3>';
        echo '<table class="xlocal-doc-table">';
        echo '<thead><tr><th>Field</th><th>Example Value</th><th>Where</th></tr></thead>';
        echo '<tbody>';
        echo '<tr><td>Main Site Base URL</td><td><code>https://main.example.com</code></td><td>Sender</td></tr>';
        echo '<tr><td>Ingest Endpoint Path</td><td><code>/wp-json/xlocal/v1/ingest</code></td><td>Sender</td></tr>';
        echo '<tr><td>Shared Secret</td><td><code>n7A!pQ9m@4Kz#1wL2xD8rT6y</code></td><td>Sender + Receiver</td></tr>';
        echo '<tr><td>Allowed Media Domains</td><td><code>cdn.example.com</code><br/><code>img.examplecdn.net</code></td><td>Receiver</td></tr>';
        echo '<tr><td>Target Post Type</td><td><code>post</code></td><td>Sender</td></tr>';
        echo '<tr><td>Default Status</td><td><code>pending</code></td><td>Sender/Receiver</td></tr>';
        echo '<tr><td>Rate Limit</td><td><code>60</code></td><td>Receiver</td></tr>';
        echo '<tr><td>Max Payload Size (KB)</td><td><code>512</code></td><td>Sender/Receiver</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>4) Recommended settings (safe defaults)</h3>';
        echo '<ul class="xlocal-doc-list">';
        echo '<li>Keep <strong>Require TLS</strong> enabled.</li>';
        echo '<li>Keep <strong>Enable HTML Sanitization</strong> enabled.</li>';
        echo '<li>Keep <strong>Reject Non-Allowed media</strong> enabled and fill Allowed Media Domains.</li>';
        echo '<li>Use <strong>pending</strong> as default status until your team confirms the flow.</li>';
        echo '<li>Enable <strong>Auto Send on Publish/Update</strong> only after test payload is successful.</li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>5) How daily use works</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Editor publishes or updates a post on Sender site.</li>';
        echo '<li>Plugin creates a secure payload and sends it to Receiver site.</li>';
        echo '<li>Receiver verifies signature, validates media domain, sanitizes HTML, then creates or updates post.</li>';
        echo '<li>You can review the latest sender response in the Sender Debug tab.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section" id="xlocal-doc-troubleshooting">';
        echo '<h3>6) If posts are not arriving</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Check Sender Mode and Receiver Mode are correct.</li>';
        echo '<li>Confirm Shared Secret matches exactly on both sites.</li>';
        echo '<li>Confirm Sender Base URL points to Receiver site (not same site).</li>';
        echo '<li>Use Send Test Payload and read the Sender Debug tab response.</li>';
        echo '<li>If using Batch mode, ensure WordPress cron is running on server.</li>';
        echo '<li>If media is blocked, add your CDN domain to Allowed Media Domains on Receiver.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>7) Security notes for managers</h3>';
        echo '<ul class="xlocal-doc-list">';
        echo '<li>Shared Secret is the main lock. Change it immediately if leaked.</li>';
        echo '<li>Only trusted admins should access plugin settings.</li>';
        echo '<li>Use HTTPS on both websites.</li>';
        echo '<li>Optional: use IP allowlist if your hosting network is stable.</li>';
        echo '<li>Do not disable sanitization unless your team fully understands HTML security risk.</li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>8) Suggested launch plan</h3>';
        echo '<ol class="xlocal-doc-list">';
        echo '<li>Week 1: Use Test Payload only.</li>';
        echo '<li>Week 2: Enable Auto Send for one post type with status pending.</li>';
        echo '<li>Week 3: Move to publish status after editorial review passes.</li>';
        echo '<li>Week 4: Enable Batch or Immediate mode based on your traffic pattern.</li>';
        echo '</ol>';
        echo '</section>';

        echo '<section class="xlocal-doc-section">';
        echo '<h3>FAQ (quick answers)</h3>';
        echo '<details class="xlocal-doc-faq"><summary>What if I do not know my CDN domain?</summary><p>Ask your hosting/CDN provider and paste only the hostname (without path), for example <code>cdn.example.com</code>.</p></details>';
        echo '<details class="xlocal-doc-faq"><summary>Can I set publish directly?</summary><p>Yes, but start with <code>pending</code> first, confirm quality, then switch to <code>publish</code>.</p></details>';
        echo '<details class="xlocal-doc-faq"><summary>Do I need Batch mode?</summary><p>Use Immediate for low volume. Use Batch if you publish many posts and want controlled periodic sending.</p></details>';
        echo '</section>';

        echo '</div>';
        echo '</div>';
    }
}
