<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Front-end and admin behavior controlled by each module switch. */
final class BST_Modules {
    public static function boot() {
        if ( BST_Settings::enabled( 'comments' ) ) {
            self::disable_comments();
        }
        if ( BST_Settings::enabled( 'media' ) ) {
            BST_Media_Optimizer::boot();
        }
        if ( BST_Settings::enabled( 'revisions' ) ) {
            add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'revisions_to_keep' ), 99, 2 );
        }
        if ( BST_Settings::enabled( 'login' ) ) {
            add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
            add_filter( 'login_headerurl', array( __CLASS__, 'login_logo_link' ) );
            add_filter( 'login_headertext', array( __CLASS__, 'login_logo_title' ) );
        }
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'cleanup_admin_menu' ), 999 );
        }
    }

    public static function revisions_to_keep( $number, $post ) {
        $settings = BST_Settings::get();
        return max( 0, absint( $settings['revisions_limit'] ) );
    }

    private static function disable_comments() {
        add_action( 'init', array( __CLASS__, 'remove_comment_support' ), 999 );
        add_filter( 'comments_open', '__return_false', 99 );
        add_filter( 'pings_open', '__return_false', 99 );
        add_filter( 'comments_array', '__return_empty_array', 99 );
        add_filter( 'get_comments_number', '__return_zero', 99 );
        add_filter( 'rest_endpoints', array( __CLASS__, 'filter_comment_routes' ) );
        add_filter( 'xmlrpc_methods', array( __CLASS__, 'filter_comment_xmlrpc_methods' ) );
        add_action( 'template_redirect', array( __CLASS__, 'block_comment_feeds' ), 0 );
        add_action( 'admin_bar_menu', static function ( $bar ) {
            $bar->remove_node( 'comments' );
        }, 999 );
        add_action( 'wp_dashboard_setup', static function () {
            remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
            remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
        }, 99 );
        add_action( 'admin_init', array( __CLASS__, 'redirect_comments_admin' ) );
        add_filter( 'manage_posts_columns', array( __CLASS__, 'hide_comments_column' ) );
        add_filter( 'manage_pages_columns', array( __CLASS__, 'hide_comments_column' ) );
        add_action( 'wp_enqueue_scripts', static function () {
            wp_dequeue_script( 'comment-reply' );
        }, 100 );
    }

    public static function remove_comment_support() {
        foreach ( get_post_types( array(), 'names' ) as $type ) {
            if ( post_type_supports( $type, 'comments' ) ) {
                remove_post_type_support( $type, 'comments' );
            }
            if ( post_type_supports( $type, 'trackbacks' ) ) {
                remove_post_type_support( $type, 'trackbacks' );
            }
        }
    }

    public static function filter_comment_routes( $routes ) {
        foreach ( array_keys( $routes ) as $route ) {
            if ( preg_match( '~^/wp/v2/comments(?:/|$)~', $route ) ) {
                unset( $routes[ $route ] );
            }
        }
        return $routes;
    }

    public static function filter_comment_xmlrpc_methods( $methods ) {
        foreach ( array( 'wp.newComment', 'wp.getComment', 'wp.getComments', 'wp.editComment', 'wp.deleteComment', 'wp.getCommentStatusList' ) as $method ) {
            unset( $methods[ $method ] );
        }
        return $methods;
    }

    public static function block_comment_feeds() {
        if ( is_comment_feed() ) {
            wp_die( 'Komentářové kanály jsou na tomto webu deaktivovány.', 'Nenalezeno', array( 'response' => 404 ) );
        }
    }

    public static function hide_comments_column( $columns ) {
        unset( $columns['comments'] );
        return $columns;
    }

    public static function redirect_comments_admin() {
        global $pagenow;
        if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) ) {
            wp_safe_redirect( admin_url( 'index.php' ) );
            exit;
        }
    }

    public static function cleanup_admin_menu() {
        if ( BST_Settings::enabled( 'comments' ) ) {
            remove_menu_page( 'edit-comments.php' );
            remove_submenu_page( 'options-general.php', 'options-discussion.php' );
        }
        if ( ! BST_Settings::enabled( 'menu' ) ) {
            return;
        }
        $settings = BST_Settings::get();
        foreach ( $settings['menu_hidden'] as $slug ) {
            if ( 'bst-toolkit' !== $slug ) {
                remove_menu_page( $slug );
            }
        }
    }

    public static function login_logo_link() {
        $settings = BST_Settings::get();
        return ! empty( $settings['login']['logo_url'] ) ? $settings['login']['logo_url'] : home_url( '/' );
    }

    public static function login_logo_title() {
        return get_bloginfo( 'name' );
    }

    public static function login_styles() {
        $settings = BST_Settings::get();
        $login    = $settings['login'];
        $logo_id  = absint( $login['logo'] );
        if ( ! $logo_id && ! empty( $settings['data']['logo'] ) ) {
            $logo_id = absint( $settings['data']['logo'] );
        }
        $logo  = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
        $bg    = ! empty( $login['background_id'] ) ? wp_get_attachment_image_url( absint( $login['background_id'] ), 'full' ) : '';
        $width = absint( $login['logo_width'] );
        $css = 'body.login{background:' . esc_attr( $login['background'] ) . ';' . ( $bg ? 'background-image:url("' . esc_url( $bg ) . '");background-size:cover;background-position:center;' : '' ) . '}';
        $css .= 'body.login #loginform,body.login #registerform,body.login #lostpasswordform{background:' . esc_attr( $login['panel'] ) . ';border:0;border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,.09);padding:30px;}';
        $css .= 'body.login .button-primary{background:' . esc_attr( $login['button'] ) . ';border-color:' . esc_attr( $login['button'] ) . ';border-radius:7px;}';
        $css .= 'body.login #nav a,body.login #backtoblog a,body.login .privacy-policy-link{color:' . esc_attr( $login['links'] ) . '!important;}';
        if ( $logo ) {
            $css .= 'body.login #login h1 a{background-image:url("' . esc_url( $logo ) . '");background-size:contain;background-position:center;width:100%;max-width:' . $width . 'px;height:90px;margin:0 auto 20px;}';
        }
        wp_add_inline_style( 'login', $css );
    }
}
