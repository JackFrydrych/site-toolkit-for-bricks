<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional custom login URL, without editing wp-login.php or .htaccess.
 * Kept separate from login page styling. Deactivating the plugin restores WordPress URLs.
 * Single-site focused: multisite login routing is deliberately not enabled.
 */
final class BST_Login_URL {
    public static function conflicting_plugin() {
        // Avoid competing rewrites/redirects when WPS Hide Login is installed.
        return class_exists( '\\WPS\\WPS_Hide_Login\\Plugin' ) || class_exists( 'WPS_Hide_Login' );
    }

    public static function active() {
        $settings = BST_Settings::get();
        return ! is_multisite()
            && ! self::conflicting_plugin()
            && ! empty( $settings['login']['custom_url_enabled'] )
            && self::valid_slug( $settings['login']['slug'] ?? '' );
    }

    public static function valid_slug( $slug ) {
        if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,59}$/D', $slug ) ) {
            return false;
        }
        $reserved = array(
            'wp-admin', 'wp-login', 'wp-login-php', 'wp-json', 'wp-content', 'wp-includes',
            'admin', 'login', 'logout', 'register', 'lostpassword', 'feed', 'comments',
            'xmlrpc', 'robots', 'sitemap', 'index', 'wp-cron', 'wp-signup', 'wp-activate',
        );
        return ! in_array( $slug, $reserved, true );
    }

    public static function slug_conflicts_with_content( $slug ) {
        if ( ! function_exists( 'get_page_by_path' ) ) {
            return false;
        }
        $types = get_post_types( array( 'public' => true ), 'names' );
        return (bool) get_page_by_path( $slug, OBJECT, array_values( $types ) );
    }

    public static function url( $scheme = null ) {
        $settings = BST_Settings::get();
        $slug     = $settings['login']['slug'] ?? 'moje-prihlaseni';
        if ( ! self::valid_slug( $slug ) ) {
            $slug = 'moje-prihlaseni';
        }
        if ( get_option( 'permalink_structure' ) ) {
            $url = home_url( '/' . $slug . '/' );
        } else {
            $url = add_query_arg( $slug, '1', home_url( '/' ) );
        }
        if ( 'https' === $scheme || 'login' === $scheme || 'login_post' === $scheme || is_ssl() || force_ssl_admin() ) {
            $url = set_url_scheme( $url, 'https' );
        }
        return $url;
    }

    public static function boot() {
        if ( ! self::active() ) {
            return;
        }
        add_filter( 'site_url', array( __CLASS__, 'rewrite_url' ), 10, 4 );
        add_filter( 'network_site_url', array( __CLASS__, 'rewrite_url' ), 10, 3 );
        add_filter( 'wp_redirect', array( __CLASS__, 'rewrite_redirect' ), 10, 2 );
        add_action( 'wp_loaded', array( __CLASS__, 'route_request' ), 1 );
    }

    private static function paths_equal( $a, $b ) {
        return rtrim( rawurldecode( $a ), '/' ) === rtrim( rawurldecode( $b ), '/' );
    }

    private static function legacy_login_path() {
        return (string) wp_parse_url( untrailingslashit( get_option( 'siteurl' ) ) . '/wp-login.php', PHP_URL_PATH );
    }

    public static function rewrite_url( $url, $path = '', $scheme = null, $blog_id = null ) {
        if ( ! is_string( $url ) || false === stripos( $url, 'wp-login.php' ) ) {
            return $url;
        }
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) || empty( $parsed['path'] ) ) {
            return $url;
        }
        if ( isset( $parsed['host'] ) ) {
            $trusted = array_filter( array( wp_parse_url( site_url(), PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ) ) );
            if ( ! in_array( $parsed['host'], $trusted, true ) ) {
                return $url;
            }
        }
        if ( ! self::paths_equal( $parsed['path'], self::legacy_login_path() ) && ! self::paths_equal( $parsed['path'], 'wp-login.php' ) ) {
            return $url;
        }
        $new_url = self::url( $scheme );
        if ( isset( $parsed['query'] ) && '' !== $parsed['query'] ) {
            $new_url .= ( false !== strpos( $new_url, '?' ) ? '&' : '?' ) . $parsed['query'];
        }
        if ( isset( $parsed['fragment'] ) ) {
            $new_url .= '#' . $parsed['fragment'];
        }
        return $new_url;
    }

    public static function rewrite_redirect( $location, $status = 302 ) {
        return self::rewrite_url( $location );
    }

    private static function is_custom_request( $request_path ) {
        $settings = BST_Settings::get();
        $slug = $settings['login']['slug'];
        if ( get_option( 'permalink_structure' ) ) {
            $custom_path = (string) wp_parse_url( home_url( '/' . $slug . '/' ), PHP_URL_PATH );
            return self::paths_equal( $request_path, $custom_path );
        }
        return isset( $_GET[ $slug ] ) && '1' === (string) $_GET[ $slug ];
    }

    public static function route_request() {
        global $pagenow, $error, $interim_login, $action, $user_login;

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return;
        }
        // Public AJAX and form handlers must remain accessible when logged out.
        $script = basename( isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '' );
        if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php' ), true ) ) {
            return;
        }
        if ( self::paths_equal( $path, self::legacy_login_path() ) ) {
            self::not_found();
        }
        if ( self::is_custom_request( $path ) ) {
            if ( is_user_logged_in() && empty( $_GET['action'] ) && empty( $_GET['interim-login'] ) ) {
                wp_safe_redirect( admin_url() );
                exit;
            }
            $pagenow = 'wp-login.php';
            require ABSPATH . 'wp-login.php';
            exit;
        }
        if ( is_admin() && ! is_user_logged_in() ) {
            self::not_found();
        }
    }

    private static function not_found() {
        status_header( 404 );
        nocache_headers();
        wp_die( esc_html__( 'Stránka nebyla nalezena.', 'site-toolkit-for-bricks' ), esc_html__( 'Nenalezeno', 'site-toolkit-for-bricks' ), array( 'response' => 404 ) );
    }
}
