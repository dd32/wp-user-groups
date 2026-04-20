<?php
use dd32\WordPress\UserTeams\Plugin;
use dd32\WordPress\UserTeams\Admin;
/**
 * Development seed data for the local wp-env test site.
 *
 * Run via wp-cli:
 *   wp eval-file wp-content/plugins/wp-user-teams/scripts/seed.php --url=claude.home.dd32.au
 *
 * Idempotent: re-running will upsert by slug / username.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( 'Plugin' ) ) {
	WP_CLI::error( 'User Teams plugin is not loaded.' );
}

WP_CLI::log( '--- Seeding User Teams demo data ---' );

/* -------------------------------------------------------------------------
 * Sub-sites (multisite only)
 * ---------------------------------------------------------------------- */
$sites = array();
if ( is_multisite() ) {
	$domain = get_network()->domain;
	$wanted = array(
		'blog'      => 'Company Blog',
		'marketing' => 'Marketing Hub',
		'support'   => 'Support Desk',
	);
	foreach ( $wanted as $slug => $title ) {
		$path = '/' . $slug . '/';
		$site = get_blog_details( array( 'domain' => $domain, 'path' => $path ) );
		if ( $site ) {
			$sites[ $slug ] = (int) $site->blog_id;
			WP_CLI::log( "  site exists: {$slug} -> blog {$site->blog_id}" );
			continue;
		}
		$id = wpmu_create_blog( $domain, $path, $title, get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( "  could not create site {$slug}: " . $id->get_error_message() );
			continue;
		}
		$sites[ $slug ] = (int) $id;
		WP_CLI::log( "  created site: {$slug} -> blog {$id}" );
	}
}

/* -------------------------------------------------------------------------
 * Users
 * ---------------------------------------------------------------------- */
$users = array(
	'alice'  => array( 'email' => 'alice@example.test',  'name' => 'Alice Anderson',  'base_role' => 'subscriber' ),
	'bob'    => array( 'email' => 'bob@example.test',    'name' => 'Bob Brown',        'base_role' => 'subscriber' ),
	'carol'  => array( 'email' => 'carol@example.test',  'name' => 'Carol Chen',       'base_role' => 'subscriber' ),
	'dave'   => array( 'email' => 'dave@example.test',   'name' => 'Dave Davis',       'base_role' => 'subscriber' ),
	'erin'   => array( 'email' => 'erin@example.test',   'name' => 'Erin Edwards',     'base_role' => 'contributor' ),
);

$user_ids = array();
foreach ( $users as $login => $data ) {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$uid = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => 'password',
			'user_email'   => $data['email'],
			'display_name' => $data['name'],
			'role'         => $data['base_role'],
		) );
		if ( is_wp_error( $uid ) ) {
			WP_CLI::warning( "  skip user {$login}: " . $uid->get_error_message() );
			continue;
		}
		$user_ids[ $login ] = (int) $uid;
		WP_CLI::log( "  created user: {$login} (id {$uid})" );
	} else {
		$user_ids[ $login ] = (int) $user->ID;
		WP_CLI::log( "  user exists: {$login} (id {$user->ID})" );
	}
}

/* -------------------------------------------------------------------------
 * Teams
 * ---------------------------------------------------------------------- */
$team_defs = array(
	'engineering' => array(
		'name'    => 'Engineering',
		'role'    => 'editor',
		'site_slugs' => array(), // all sites
	),
	'marketing'   => array(
		'name'    => 'Marketing',
		'role'    => 'author',
		'site_slugs' => array( 'marketing', 'blog' ),
	),
	'support'     => array(
		'name'    => 'Support',
		'role'    => 'contributor',
		'site_slugs' => array( 'support' ),
	),
	'read-only'   => array(
		'name'    => 'Read-Only Observers',
		'role'    => 'subscriber',
		'site_slugs' => array(), // all sites
	),
);

$team_ids = array();
foreach ( $team_defs as $slug => $def ) {
	$existing = Plugin::get_team_by_slug( $slug );
	if ( $existing ) {
		$tid = $existing['id'];
		Plugin::update_team( $tid, array( 'name' => $def['name'], 'role' => $def['role'] ) );
		WP_CLI::log( "  team exists: {$slug} (id {$tid})" );
	} else {
		$tid = Plugin::create_team( $def['name'], $slug, $def['role'] );
		if ( is_wp_error( $tid ) ) {
			WP_CLI::warning( "  skip team {$slug}: " . $tid->get_error_message() );
			continue;
		}
		WP_CLI::log( "  created team: {$slug} (id {$tid})" );
	}
	$team_ids[ $slug ] = (int) $tid;

	if ( is_multisite() ) {
		$blog_ids = array();
		foreach ( $def['site_slugs'] as $site_slug ) {
			if ( isset( $sites[ $site_slug ] ) ) {
				$blog_ids[] = $sites[ $site_slug ];
			}
		}
		Plugin::set_team_sites( $tid, $blog_ids );
	}
}

/* -------------------------------------------------------------------------
 * Memberships
 * ---------------------------------------------------------------------- */
$memberships = array(
	'alice' => array( 'engineering' ),
	'bob'   => array( 'engineering', 'support' ),
	'carol' => array( 'marketing' ),
	'dave'  => array( 'support', 'read-only' ),
	'erin'  => array( 'read-only' ),
);

foreach ( $memberships as $login => $team_slugs ) {
	if ( ! isset( $user_ids[ $login ] ) ) {
		continue;
	}
	$ids = array();
	foreach ( $team_slugs as $slug ) {
		if ( isset( $team_ids[ $slug ] ) ) {
			$ids[] = $team_ids[ $slug ];
		}
	}
	Plugin::set_user_teams( $user_ids[ $login ], $ids );
	WP_CLI::log( "  {$login} -> [" . implode( ', ', $team_slugs ) . ']' );
}

WP_CLI::success( 'Seed complete.' );
