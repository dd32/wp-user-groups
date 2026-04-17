<?php
/**
 * Seed the local wp-env environment with sample access groups and users.
 *
 * Usage:
 *   npm run seed                                            (single site)
 *   wp-env run cli wp eval-file wp-content/plugins/wp-user-access-groups/bin/seed.php
 *
 * Running again is safe — existing groups and users are reused.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run via `wp eval-file`.\n" );
	exit( 1 );
}

if ( ! class_exists( 'Access_Groups' ) ) {
	WP_CLI::error( 'The Access Groups plugin is not loaded. Is wp-env running with this plugin active?' );
}

$default_password = 'password';

$group_defs = array(
	array(
		'name' => 'Meta Team',
		'slug' => 'meta-team',
		'role' => 'editor',
	),
	array(
		'name' => 'Content Editors',
		'slug' => 'content-editors',
		'role' => 'editor',
	),
	array(
		'name' => 'Support',
		'slug' => 'support',
		'role' => 'contributor',
	),
	array(
		'name' => 'Admin Team',
		'slug' => 'admin-team',
		'role' => 'administrator',
	),
	array(
		'name' => 'Watchers',
		'slug' => 'watchers',
		'role' => '',
	),
);

$group_ids = array();
foreach ( $group_defs as $def ) {
	$existing = Access_Groups::get_group_by_slug( $def['slug'] );
	if ( $existing ) {
		$group_ids[ $def['slug'] ] = (int) $existing['id'];
		WP_CLI::log( sprintf( '=  Group exists: %s (#%d)', $def['name'], $existing['id'] ) );
		continue;
	}

	$id = Access_Groups::create_group( $def['name'], $def['slug'], $def['role'] );
	if ( is_wp_error( $id ) ) {
		WP_CLI::warning( sprintf( 'Failed to create group "%s": %s', $def['name'], $id->get_error_message() ) );
		continue;
	}
	$group_ids[ $def['slug'] ] = (int) $id;
	WP_CLI::success( sprintf( 'Created group: %s (#%d, role=%s)', $def['name'], $id, $def['role'] ?: 'none' ) );
}

$user_defs = array(
	array( 'login' => 'alice',   'email' => 'alice@example.test',   'role' => 'subscriber',  'groups' => array( 'meta-team', 'admin-team' ) ),
	array( 'login' => 'bob',     'email' => 'bob@example.test',     'role' => 'subscriber',  'groups' => array( 'content-editors' ) ),
	array( 'login' => 'carol',   'email' => 'carol@example.test',   'role' => 'author',      'groups' => array( 'support', 'watchers' ) ),
	array( 'login' => 'dave',    'email' => 'dave@example.test',    'role' => 'subscriber',  'groups' => array( 'meta-team' ) ),
	array( 'login' => 'eve',     'email' => 'eve@example.test',     'role' => 'subscriber',  'groups' => array( 'content-editors', 'support' ) ),
	array( 'login' => 'frank',   'email' => 'frank@example.test',   'role' => 'subscriber',  'groups' => array( 'meta-team', 'admin-team' ) ),
	array( 'login' => 'grace',   'email' => 'grace@example.test',   'role' => 'contributor', 'groups' => array( 'watchers' ) ),
	array( 'login' => 'heidi',   'email' => 'heidi@example.test',   'role' => 'subscriber',  'groups' => array( 'content-editors', 'admin-team' ) ),
	array( 'login' => 'ivan',    'email' => 'ivan@example.test',    'role' => 'subscriber',  'groups' => array( 'support' ) ),
	array( 'login' => 'judy',    'email' => 'judy@example.test',    'role' => 'subscriber',  'groups' => array( 'meta-team', 'content-editors', 'support' ) ),
	array( 'login' => 'mallory', 'email' => 'mallory@example.test', 'role' => 'subscriber',  'groups' => array() ),
);

foreach ( $user_defs as $def ) {
	$user = get_user_by( 'login', $def['login'] );
	if ( $user ) {
		WP_CLI::log( sprintf( '=  User exists: %s (#%d)', $def['login'], $user->ID ) );
	} else {
		$user_id = wp_insert_user( array(
			'user_login'   => $def['login'],
			'user_email'   => $def['email'],
			'user_pass'    => $default_password,
			'role'         => $def['role'],
			'first_name'   => ucfirst( $def['login'] ),
			'display_name' => ucfirst( $def['login'] ),
		) );
		if ( is_wp_error( $user_id ) ) {
			WP_CLI::warning( sprintf( 'Failed to create user "%s": %s', $def['login'], $user_id->get_error_message() ) );
			continue;
		}
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $user_id, $def['role'] );
		}
		$user = get_user_by( 'id', $user_id );
		WP_CLI::success( sprintf( 'Created user: %s (#%d, role=%s)', $def['login'], $user_id, $def['role'] ) );
	}

	$ids = array();
	foreach ( $def['groups'] as $slug ) {
		if ( isset( $group_ids[ $slug ] ) ) {
			$ids[] = $group_ids[ $slug ];
		}
	}
	Access_Groups::set_user_groups( $user->ID, $ids );
	if ( $def['groups'] ) {
		WP_CLI::log( sprintf( '   -> groups: %s', implode( ', ', $def['groups'] ) ) );
	} else {
		WP_CLI::log( '   -> groups: (none)' );
	}
}

WP_CLI::log( '' );
WP_CLI::success( 'Seed complete.' );
WP_CLI::log( sprintf( 'All seeded users share the password: %s', $default_password ) );
WP_CLI::log( 'Visit Users -> Groups in the admin to review the data.' );
