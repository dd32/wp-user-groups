<?php
/**
 * Plugin Name:       User Groups
 * Plugin URI:        https://github.com/dd32/wp-user-groups
 * Description:       Unix-style user groups for WordPress. Define groups, attach a role to each, and grant access to users by group membership. Supports multisite, where a single group can grant access across many (or all) sites.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            wp-user-groups contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-user-groups
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_USER_GROUPS_VERSION', '0.1.0' );
define( 'WP_USER_GROUPS_FILE', __FILE__ );
define( 'WP_USER_GROUPS_PATH', plugin_dir_path( __FILE__ ) );

require_once WP_USER_GROUPS_PATH . 'includes/class-wp-user-groups.php';
require_once WP_USER_GROUPS_PATH . 'includes/class-wp-user-groups-admin.php';

WP_User_Groups::instance();
WP_User_Groups_Admin::instance();
