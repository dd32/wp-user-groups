<?php
/**
 * Database schema creation and upgrade for User Groups.
 *
 * Three global tables (base_prefix, no blog-id component):
 *
 *   {base_prefix}user_groups        – group definitions
 *   {base_prefix}user_group_sites   – which sites a group grants access to
 *   {base_prefix}user_group_members – user ↔ group memberships
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Groups_DB {

	const VERSION_OPTION = 'wp_user_groups_db_version';

	public static function install() {
		self::create_tables();
		update_site_option( self::VERSION_OPTION, WP_USER_GROUPS_VERSION );
	}

	public static function check_version() {
		$installed = get_site_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, WP_USER_GROUPS_VERSION, '<' ) ) {
			self::create_tables();
			update_site_option( self::VERSION_OPTION, WP_USER_GROUPS_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$wpdb->user_groups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(200) NOT NULL DEFAULT '',
			name varchar(200) NOT NULL DEFAULT '',
			role varchar(200) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};

		CREATE TABLE {$wpdb->user_group_sites} (
			group_id bigint(20) unsigned NOT NULL,
			blog_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (group_id,blog_id),
			KEY blog_id (blog_id)
		) {$charset_collate};

		CREATE TABLE {$wpdb->user_group_members} (
			group_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (group_id,user_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
