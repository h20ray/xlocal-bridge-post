<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Xlocal_Bridge_Updater {
    const CACHE_TTL = 21600; // 6 hours.
    const INSTALLED_COMMIT_OPTION = 'xlocal_bridge_updater_installed_commit';
    const DEFAULT_REPO = 'h20ray/xlocal-bridge-post';
    const DEFAULT_BRANCH = 'main';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_on_upgrade' ), 10, 2 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_source_folder' ), 10, 4 );
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $payload = self::get_latest_payload();
        if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
            return $transient;
        }

        $plugin_file = self::plugin_basename();
        if ( ! self::is_update_available( $payload ) ) {
            return $transient;
        }

        $update = new stdClass();
        $update->id = 'xlocal-bridge-post';
        $update->slug = self::plugin_slug();
        $update->plugin = $plugin_file;
        $update->new_version = $payload['version'];
        $update->url = $payload['homepage'];
        $update->package = $payload['package'];
        $update->icons = array();
        $update->banners = array();
        $update->banners_rtl = array();
        $update->tested = '';
        $update->requires_php = PHP_VERSION;
        if ( ! empty( $payload['commit'] ) ) {
            $update->xlocal_commit = $payload['commit'];
        }

        $transient->response[ $plugin_file ] = $update;
        return $transient;
    }

    public static function plugins_api( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== self::plugin_slug() ) {
            return $result;
        }

        $payload = self::get_latest_payload();
        if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'xLocal Bridge Post';
        $info->slug = self::plugin_slug();
        $info->version = $payload['version'];
        $info->author = 'JagaWarta';
        $info->author_profile = '';
        $info->homepage = $payload['homepage'];
        $info->requires = '';
        $info->tested = '';
        $info->requires_php = PHP_VERSION;
        $info->download_link = $payload['package'];
        $info->last_updated = $payload['published_at'];
        $info->sections = array(
            'description' => 'xLocal Bridge Post syncs posts from Sender WordPress to Receiver WordPress using signed payloads.',
            'changelog' => nl2br( esc_html( $payload['changelog'] ) ),
        );

        return $info;
    }

    public static function clear_cache_on_upgrade( $upgrader, $options ) {
        if ( empty( $options['action'] ) || empty( $options['type'] ) || $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
            return;
        }
        if ( empty( $options['plugins'] ) || ! is_array( $options['plugins'] ) ) {
            return;
        }
        if ( in_array( self::plugin_basename(), $options['plugins'], true ) ) {
            $cached = get_site_transient( self::cache_key() );
            if ( self::channel() === 'commit' && is_array( $cached ) && ! empty( $cached['commit'] ) ) {
                update_option( self::INSTALLED_COMMIT_OPTION, sanitize_text_field( $cached['commit'] ), false );
            }
            delete_site_transient( self::cache_key() );
        }
    }

    public static function normalize_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::plugin_basename() ) {
            return $source;
        }

        if ( basename( untrailingslashit( $source ) ) === self::plugin_slug() ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $target = trailingslashit( $remote_source ) . self::plugin_slug();
        if ( $wp_filesystem->exists( $target ) ) {
            $wp_filesystem->delete( $target, true );
        }

        if ( $wp_filesystem->move( $source, $target ) ) {
            return $target;
        }

        return $source;
    }

    public static function is_repo_configured() {
        return self::repo() !== '';
    }

    public static function channel_label() {
        return self::channel() === 'commit' ? 'Commit' : 'Release';
    }

    public static function status_snapshot() {
        $cached = get_site_transient( self::cache_key() );
        if ( ! is_array( $cached ) ) {
            $cached = array();
        }
        return array(
            'configured'       => self::is_repo_configured(),
            'repo'             => self::repo(),
            'channel'          => self::channel(),
            'branch'           => self::branch(),
            'installed_commit' => (string) get_option( self::INSTALLED_COMMIT_OPTION, '' ),
            'cached_version'   => isset( $cached['version'] ) ? (string) $cached['version'] : '',
            'cached_commit'    => isset( $cached['commit'] ) ? (string) $cached['commit'] : '',
            'cached_at'        => isset( $cached['published_at'] ) ? (string) $cached['published_at'] : '',
        );
    }

    public static function force_refresh() {
        delete_site_transient( self::cache_key() );
        delete_site_transient( 'update_plugins' );
        if ( function_exists( 'wp_clean_plugins_cache' ) ) {
            wp_clean_plugins_cache( true );
        }
        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }
        return self::status_snapshot();
    }

    private static function is_update_available( $payload ) {
        if ( self::channel() === 'commit' ) {
            $latest_commit = isset( $payload['commit'] ) ? (string) $payload['commit'] : '';
            if ( $latest_commit === '' ) {
                return false;
            }
            $installed_commit = (string) get_option( self::INSTALLED_COMMIT_OPTION, '' );
            if ( $installed_commit === '' ) {
                update_option( self::INSTALLED_COMMIT_OPTION, sanitize_text_field( $latest_commit ), false );
                return false;
            }
            return $latest_commit !== $installed_commit;
        }

        $current_version = self::current_version();
        return version_compare( $payload['version'], $current_version, '>' );
    }

    private static function get_latest_payload() {
        $repo = self::repo();
        if ( $repo === '' ) {
            return new WP_Error( 'xlocal_updater_repo_missing', 'GitHub repository is not configured for updater.' );
        }

        $cached = get_site_transient( self::cache_key() );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
            return $cached;
        }

        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'xlocal-bridge-post-updater',
        );
        $token = self::token();
        if ( $token !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if ( self::channel() === 'commit' ) {
            $response = wp_remote_get(
                'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode( self::branch() ),
                array(
                    'timeout' => 15,
                    'headers' => $headers,
                )
            );
        } else {
            $response = wp_remote_get(
                'https://api.github.com/repos/' . $repo . '/releases/latest',
                array(
                    'timeout' => 15,
                    'headers' => $headers,
                )
            );
        }
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'xlocal_updater_http_error', 'Unable to fetch GitHub update metadata. HTTP ' . intval( $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return new WP_Error( 'xlocal_updater_invalid_payload', 'GitHub update payload is invalid.' );
        }

        if ( self::channel() === 'commit' ) {
            if ( empty( $json['sha'] ) ) {
                return new WP_Error( 'xlocal_updater_invalid_commit', 'Latest commit payload is missing SHA.' );
            }
            $sha = sanitize_text_field( (string) $json['sha'] );
            $published_at = '';
            if ( ! empty( $json['commit']['committer']['date'] ) ) {
                $published_at = sanitize_text_field( (string) $json['commit']['committer']['date'] );
            }
            if ( $published_at === '' ) {
                $published_at = gmdate( 'c' );
            }
            $timestamp = strtotime( $published_at );
            if ( ! $timestamp ) {
                $timestamp = time();
            }

            $payload = array(
                'version'      => self::current_version() . '.' . gmdate( 'YmdHis', $timestamp ),
                'changelog'    => 'Latest commit from branch ' . self::branch() . ': ' . substr( $sha, 0, 12 ),
                'homepage'     => isset( $json['html_url'] ) ? esc_url_raw( (string) $json['html_url'] ) : '',
                'package'      => 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode( $sha ),
                'published_at' => $published_at,
                'commit'       => $sha,
            );
        } else {
            if ( empty( $json['tag_name'] ) || ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
                return new WP_Error( 'xlocal_updater_invalid_release', 'Latest GitHub release payload is invalid.' );
            }
            $payload = array(
                'version'      => ltrim( sanitize_text_field( (string) $json['tag_name'] ), 'vV' ),
                'changelog'    => isset( $json['body'] ) ? sanitize_textarea_field( (string) $json['body'] ) : '',
                'homepage'     => isset( $json['html_url'] ) ? esc_url_raw( (string) $json['html_url'] ) : '',
                'package'      => isset( $json['zipball_url'] ) ? esc_url_raw( (string) $json['zipball_url'] ) : '',
                'published_at' => isset( $json['published_at'] ) ? sanitize_text_field( (string) $json['published_at'] ) : gmdate( 'c' ),
                'commit'       => '',
            );
        }

        if ( empty( $payload['version'] ) || empty( $payload['package'] ) ) {
            return new WP_Error( 'xlocal_updater_missing_fields', 'Updater payload missing version/package.' );
        }

        set_site_transient( self::cache_key(), $payload, self::CACHE_TTL );
        return $payload;
    }

    private static function plugin_basename() {
        $file = defined( 'XLOCAL_BRIDGE_POST_FILE' ) ? XLOCAL_BRIDGE_POST_FILE : dirname( __DIR__ ) . '/xlocal-bridge-post.php';
        return plugin_basename( $file );
    }

    private static function plugin_slug() {
        return dirname( self::plugin_basename() );
    }

    private static function current_version() {
        if ( defined( 'XLOCAL_BRIDGE_POST_VERSION' ) ) {
            return XLOCAL_BRIDGE_POST_VERSION;
        }
        return '0.0.0';
    }

    private static function cache_key() {
        return 'xlocal_bridge_updater_' . md5( self::repo() . '|' . self::channel() . '|' . self::branch() );
    }

    private static function repo() {
        $repo = defined( 'XLOCAL_BRIDGE_GITHUB_REPO' ) ? trim( (string) XLOCAL_BRIDGE_GITHUB_REPO ) : self::DEFAULT_REPO;
        $repo = apply_filters( 'xlocal_bridge_github_repo', $repo );
        if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
            return $repo;
        }
        return '';
    }

    private static function token() {
        $token = defined( 'XLOCAL_BRIDGE_GITHUB_TOKEN' ) ? trim( (string) XLOCAL_BRIDGE_GITHUB_TOKEN ) : '';
        $token = apply_filters( 'xlocal_bridge_github_token', $token );
        return is_string( $token ) ? trim( $token ) : '';
    }

    private static function channel() {
        $channel = defined( 'XLOCAL_BRIDGE_GITHUB_UPDATE_CHANNEL' ) ? strtolower( trim( (string) XLOCAL_BRIDGE_GITHUB_UPDATE_CHANNEL ) ) : 'commit';
        $channel = apply_filters( 'xlocal_bridge_github_update_channel', $channel );
        return $channel === 'release' ? 'release' : 'commit';
    }

    private static function branch() {
        $branch = defined( 'XLOCAL_BRIDGE_GITHUB_BRANCH' ) ? trim( (string) XLOCAL_BRIDGE_GITHUB_BRANCH ) : self::DEFAULT_BRANCH;
        $branch = apply_filters( 'xlocal_bridge_github_branch', $branch );
        $branch = sanitize_text_field( $branch );
        return $branch !== '' ? $branch : self::DEFAULT_BRANCH;
    }
}
