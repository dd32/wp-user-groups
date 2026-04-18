<?php
/**
 * Plugin Name:       User Teams
 * Plugin URI:        https://github.com/dd32/wp-user-teams
 * Description:       Unix-style user teams for WordPress. Define teams, attach a role to each, and grant access to users by team membership. Works on single site and multisite.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            wp-user-teams contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-user-teams
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_USER_TEAMS_VERSION', '0.1.0' );
define( 'WP_USER_TEAMS_FILE', __FILE__ );
define( 'WP_USER_TEAMS_PATH', plugin_dir_path( __FILE__ ) );

require_once WP_USER_TEAMS_PATH . 'includes/class-wp-user-teams.php';
require_once WP_USER_TEAMS_PATH . 'includes/class-wp-user-teams-admin.php';

WP_User_Teams::instance();
WP_User_Teams_Admin::instance();
