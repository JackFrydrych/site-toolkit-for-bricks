<?php
/**
 * Plugin Name: Site Toolkit for Bricks
 * Description: Dynamic Bricks data, custom fields, login branding and URL, admin menu cleanup, comments management and revision limits.
 * Version: 1.4.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: ElegantWeb
 * Author URI: https://elegantweb.cz/
 * Text Domain: site-toolkit-for-bricks
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/JackFrydrych/site-toolkit-for-bricks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * An earlier ZIP release used a different WordPress plugin directory. Do not load
 * both editions simultaneously: their PHP class names and settings are shared.
 * The original plugin can be deactivated without losing any saved settings.
 */
if ( defined( 'BST_VERSION' ) || class_exists( 'BST_Settings', false ) ) {
    add_action( 'admin_notices', static function () {
        if ( current_user_can( 'activate_plugins' ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Site Toolkit for Bricks: the old Bricks Site Toolkit plugin is still active. Deactivate the old plugin to use this version. Your previous settings will be preserved.', 'site-toolkit-for-bricks' ) . '</p></div>';
        }
    } );
    return;
}

if ( ! defined( 'BST_VERSION' ) ) {
    define( 'BST_VERSION', '1.4.0' );
    define( 'BST_FILE', __FILE__ );
    define( 'BST_PATH', plugin_dir_path( __FILE__ ) );
    define( 'BST_URL', plugin_dir_url( __FILE__ ) );
}

require_once BST_PATH . 'includes/class-bst-settings.php';
require_once BST_PATH . 'includes/class-bst-bricks.php';
require_once BST_PATH . 'includes/class-bst-modules.php';
require_once BST_PATH . 'includes/class-bst-media-optimizer.php';
require_once BST_PATH . 'includes/class-bst-login-url.php';
require_once BST_PATH . 'includes/class-bst-github-updater.php';

// External GitHub releases: never confuse this plugin with WordPress.org listings.
BST_GitHub_Updater::boot();

add_action( 'plugins_loaded', static function () {
    BST_Login_URL::boot();
    BST_Bricks::boot();
    BST_Modules::boot();
    if ( is_admin() ) {
        require_once BST_PATH . 'includes/class-bst-admin.php';
        BST_Admin::boot();
    }
} );
