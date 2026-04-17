<?php
/**
 * Plugin Name:       Access Groups
 * Plugin URI:        https://github.com/dd32/wp-user-access-groups
 * Description:       A third layer on the WordPress permission model: attach a role to a group, drop users into the group, and members get that role on every site the group applies to.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Access Groups contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       access-groups
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

define( 'ACCESS_GROUPS_VERSION', '0.2.0' );
define( 'ACCESS_GROUPS_FILE', __FILE__ );
define( 'ACCESS_GROUPS_PATH', plugin_dir_path( __FILE__ ) );

require_once ACCESS_GROUPS_PATH . 'includes/class-access-groups.php';
require_once ACCESS_GROUPS_PATH . 'includes/class-access-groups-admin.php';

Access_Groups::instance();
Access_Groups_Admin::instance();
