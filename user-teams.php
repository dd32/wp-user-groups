<?php
/**
 * Plugin Name:       User Teams
 * Plugin URI:        https://github.com/dd32/wp-user-teams
 * Description:       Unix-style user groups for WordPress multisite, exposed as "Teams". Define teams, attach a role to each, and grant access to users by team membership across the network.
 * Version:           0.5.2
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Dion Hulse
 * Author URI:        https://dd32.id.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       user-teams
 * Network:           true
 */

namespace dd32\WordPress\UserTeams;

const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__ . '/';

// PSR-4 autoloader for `dd32\WordPress\UserTeams\…` → `src/…`. Keeps
// the plugin self-contained so it doesn't require a `composer install`
// in the deploy path.
spl_autoload_register( function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $path ) ) {
		require $path;
	}
} );

Plugin::instance();
Admin::instance();
