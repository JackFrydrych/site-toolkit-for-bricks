<?php
/**
 * Native WordPress plugin updates from public GitHub Releases.
 *
 * Release contract:
 *   - Public repository JackFrydrych/site-toolkit-for-bricks
 *   - Stable GitHub Release tag: v1.3.1 (not a draft / prerelease)
 *   - Attached asset EXACTLY: site-toolkit-for-bricks.zip
 *   - ZIP contains one top-level folder: site-toolkit-for-bricks/
 *
 * This module does not modify client settings during updates.
 * No GitHub API token is needed for a public repository.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BST_GitHub_Updater {
    private const SLUG = 'site-toolkit-for-bricks';
    private const REPO_URL = 'https://github.com/JackFrydrych/site-toolkit-for-bricks';
    private const API_URL = 'https://api.github.com/repos/JackFrydrych/site-toolkit-for-bricks/releases/latest';
    private const ASSET = 'site-toolkit-for-bricks.zip';
    private const TRANSIENT = 'bst_github_latest_release_130';
    private const CACHE_TIME = 3 * HOUR_IN_SECONDS;

    public static function boot() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 20, 3 );
    }

    public static function update( $update, $plugin_data, $plugin_file, $locales ) {
        // GitHub hosts many other plugins. Only touch this exact plugin.
        if ( plugin_basename( BST_FILE ) !== $plugin_file
            || empty( $plugin_data['UpdateURI'] )
            || self::REPO_URL !== untrailingslashit( $plugin_data['UpdateURI'] ) ) {
            return $update;
        }

        $release = self::release();
        if ( ! $release ) {
            return $update;
        }

        return array(
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires_php' => '7.4',
            // Respect the client's choice of automatic updates.
        );
    }

    /**
     * WordPress "View version details" dialog for a third-party plugin.
     */
    public static function plugin_details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args )
            || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }
        $release = self::release();
        if ( ! $release ) {
            return $result;
        }

        $info = new stdClass();
        $info->name          = 'Site Toolkit for Bricks';
        $info->slug          = self::SLUG;
        $info->version       = $release['version'];
        $info->author        = '<a href="https://elegantweb.cz/">ElegantWeb</a>';
        $info->homepage      = self::REPO_URL;
        $info->requires      = '6.2';
        $info->requires_php  = '7.4';
        $info->download_link = $release['package'];
        $info->sections      = array(
            'description' => '<p>Global dynamic fields for Bricks, login branding and login URL, admin cleanup, comments and revision management.</p>',
            'changelog'   => '<p>' . nl2br( esc_html( $release['notes'] ?: 'Release ' . $release['version'] ) ) . '</p>',
        );
        return $info;
    }

    /**
     * Returns validated release data, or false. API errors are cached briefly.
     * Rejections of a release without a valid asset are deliberate: using GitHub's
     * auto-generated source ZIP can rename the plugin directory during upgrades.
     */
    private static function release() {
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
            return $cached;
        }
        if ( 'missing' === $cached ) {
            return false;
        }

        $response = wp_remote_get( self::API_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Site-Toolkit-for-Bricks/' . BST_VERSION . ' (WordPress)',
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::TRANSIENT, 'missing', 15 * MINUTE_IN_SECONDS );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] )
            || empty( $body['tag_name'] ) || empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
            set_transient( self::TRANSIENT, 'missing', 15 * MINUTE_IN_SECONDS );
            return false;
        }

        // Tag format v1.3.1 or 1.3.1; don't install arbitrary branch names.
        $tag = $body['tag_name'];
        if ( ! is_string( $tag ) || ! preg_match( '/^v?(\d+\.\d+\.\d+(?:\.[0-9]+)?)$/', $tag, $match ) ) {
            set_transient( self::TRANSIENT, 'missing', self::CACHE_TIME );
            return false;
        }
        $version = $match[1];
        $prefix = self::REPO_URL . '/releases/download/' . rawurlencode( $tag ) . '/';
        $package = '';
        foreach ( $body['assets'] as $asset ) {
            if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] )
                || self::ASSET !== $asset['name'] || ! is_string( $asset['browser_download_url'] ) ) {
                continue;
            }
            // Strictly allow the exact ZIP on this repository's release only.
            if ( $prefix . self::ASSET !== $asset['browser_download_url'] ) {
                continue;
            }
            $package = $asset['browser_download_url'];
            break;
        }

        if ( ! $package ) {
            set_transient( self::TRANSIENT, 'missing', 15 * MINUTE_IN_SECONDS );
            return false;
        }

        $details = array(
            'version' => $version,
            'package' => $package,
            'url'     => self::REPO_URL . '/releases/tag/' . rawurlencode( $tag ),
            'notes'   => isset( $body['body'] ) && is_string( $body['body'] )
                ? substr( $body['body'], 0, 5000 ) : '',
        );
        set_transient( self::TRANSIENT, $details, self::CACHE_TIME );
        return $details;
    }
}
