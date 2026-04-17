<?php
/**
 * Core User Groups logic.
 *
 * Storage uses three global (base_prefix) tables so that data is inherently
 * network-wide on multisite and needs no switch_to_blog() or serialised blobs.
 *
 * Capabilities are granted at runtime through the `user_has_cap` filter.
 * Removing a user from a group drops their group-derived access immediately.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Groups {

	private static $instance;

	private static $groups_cache        = null;
	private static $group_sites_cache   = null;
	private static $user_group_ids_cache = array();
	private static $group_caps_cache    = array();

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );

		if ( is_multisite() ) {
			add_filter( 'get_blogs_of_user', array( $this, 'filter_get_blogs_of_user' ), 10, 3 );
			add_filter( 'is_user_member_of_blog', array( $this, 'filter_is_user_member_of_blog' ), 10, 3 );
			add_action( 'wp_delete_site', array( $this, 'on_site_deleted' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Group CRUD
	 * ---------------------------------------------------------------- */

	/**
	 * Every group, keyed by group ID.
	 *
	 * @return array<int, array{id:int,name:string,slug:string,role:string}>
	 */
	public static function get_all_groups() {
		if ( null !== self::$groups_cache ) {
			return self::$groups_cache;
		}

		global $wpdb;
		$rows   = $wpdb->get_results( "SELECT * FROM {$wpdb->user_groups} ORDER BY name ASC" );
		$groups = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$groups[ (int) $row->id ] = self::format_group( $row );
			}
		}
		self::$groups_cache = $groups;
		return $groups;
	}

	public static function get_group( $group_id ) {
		$groups = self::get_all_groups();
		return isset( $groups[ (int) $group_id ] ) ? $groups[ (int) $group_id ] : null;
	}

	public static function get_group_by_slug( $slug ) {
		foreach ( self::get_all_groups() as $group ) {
			if ( $group['slug'] === sanitize_title( $slug ) ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * @return int|WP_Error  New group ID on success.
	 */
	public static function create_group( $name, $slug = '', $role = '' ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-groups' ) );
		}

		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'group';
		}
		$slug = self::unique_slug( $slug );

		$role = sanitize_key( $role );
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$inserted = $wpdb->insert(
			$wpdb->user_groups,
			array(
				'name' => $name,
				'slug' => $slug,
				'role' => $role,
			),
			array( '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not create the group.', 'wp-user-groups' ) );
		}

		self::$groups_cache = null;
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_group( $group_id, array $data ) {
		global $wpdb;
		$group_id = (int) $group_id;

		if ( ! self::get_group( $group_id ) ) {
			return new WP_Error( 'not_found', __( 'Group not found.', 'wp-user-groups' ) );
		}

		$update  = array();
		$formats = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-groups' ) );
			}
			$update['name'] = $name;
			$formats[]      = '%s';
		}

		if ( isset( $data['slug'] ) ) {
			$slug = sanitize_title( $data['slug'] );
			if ( '' === $slug ) {
				$slug = 'group';
			}
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->user_groups} WHERE slug = %s AND id != %d",
					$slug,
					$group_id
				)
			);
			if ( $existing ) {
				return new WP_Error( 'duplicate_slug', __( 'That slug is already in use.', 'wp-user-groups' ) );
			}
			$update['slug'] = $slug;
			$formats[]      = '%s';
		}

		if ( isset( $data['role'] ) ) {
			$role = sanitize_key( $data['role'] );
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			$update['role'] = $role;
			$formats[]      = '%s';
		}

		if ( empty( $update ) ) {
			return true;
		}

		$wpdb->update(
			$wpdb->user_groups,
			$update,
			array( 'id' => $group_id ),
			$formats,
			array( '%d' )
		);

		self::$groups_cache     = null;
		self::$group_caps_cache = array();
		return true;
	}

	public static function delete_group( $group_id ) {
		global $wpdb;
		$group_id = (int) $group_id;

		$wpdb->delete( $wpdb->user_group_members, array( 'group_id' => $group_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->user_group_sites, array( 'group_id' => $group_id ), array( '%d' ) );
		$deleted = $wpdb->delete( $wpdb->user_groups, array( 'id' => $group_id ), array( '%d' ) );

		self::flush_all_caches();
		return (bool) $deleted;
	}

	/* ------------------------------------------------------------------
	 * Group → Site scoping
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<int, int[]>  group_id => [blog_id, …]
	 */
	public static function get_all_group_sites() {
		if ( null !== self::$group_sites_cache ) {
			return self::$group_sites_cache;
		}

		global $wpdb;
		$rows  = $wpdb->get_results( "SELECT group_id, blog_id FROM {$wpdb->user_group_sites}" );
		$sites = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$gid = (int) $row->group_id;
				if ( ! isset( $sites[ $gid ] ) ) {
					$sites[ $gid ] = array();
				}
				$sites[ $gid ][] = (int) $row->blog_id;
			}
		}
		self::$group_sites_cache = $sites;
		return $sites;
	}

	/**
	 * Blog IDs that a group explicitly targets.
	 * Empty array = "every site on the network, including future ones".
	 *
	 * @return int[]
	 */
	public static function get_group_sites( $group_id ) {
		$all = self::get_all_group_sites();
		return isset( $all[ (int) $group_id ] ) ? $all[ (int) $group_id ] : array();
	}

	public static function set_group_sites( $group_id, array $blog_ids ) {
		global $wpdb;
		$group_id = (int) $group_id;
		$blog_ids = array_values( array_unique( array_filter( array_map( 'intval', $blog_ids ) ) ) );

		$wpdb->delete( $wpdb->user_group_sites, array( 'group_id' => $group_id ), array( '%d' ) );

		foreach ( $blog_ids as $blog_id ) {
			$wpdb->insert(
				$wpdb->user_group_sites,
				array(
					'group_id' => $group_id,
					'blog_id'  => $blog_id,
				),
				array( '%d', '%d' )
			);
		}

		self::$group_sites_cache = null;
		self::$group_caps_cache  = array();
	}

	/**
	 * Does a group grant access to a specific site?
	 */
	public static function group_applies_to_site( $group_id, $blog_id = null ) {
		if ( ! is_multisite() ) {
			return true;
		}
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		$sites   = self::get_group_sites( (int) $group_id );
		if ( empty( $sites ) ) {
			return true;
		}
		return in_array( $blog_id, $sites, true );
	}

	/* ------------------------------------------------------------------
	 * User ↔ Group membership
	 * ---------------------------------------------------------------- */

	/**
	 * Group IDs for a user.
	 *
	 * @return int[]
	 */
	public static function get_user_group_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		if ( isset( self::$user_group_ids_cache[ $user_id ] ) ) {
			return self::$user_group_ids_cache[ $user_id ];
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->user_group_members} WHERE user_id = %d",
				$user_id
			)
		);

		$ids = array_map( 'intval', $ids );
		self::$user_group_ids_cache[ $user_id ] = $ids;
		return $ids;
	}

	/**
	 * Full group records for a user, keyed by group ID.
	 *
	 * @return array<int, array>
	 */
	public static function get_user_groups( $user_id ) {
		$ids    = self::get_user_group_ids( $user_id );
		$groups = self::get_all_groups();
		$out    = array();
		foreach ( $ids as $id ) {
			if ( isset( $groups[ $id ] ) ) {
				$out[ $id ] = $groups[ $id ];
			}
		}
		return $out;
	}

	public static function add_user_to_group( $user_id, $group_id ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$group_id = (int) $group_id;

		if ( ! self::get_group( $group_id ) ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->user_group_members} (group_id, user_id) VALUES (%d, %d)",
				$group_id,
				$user_id
			)
		);

		self::invalidate_user_cache( $user_id );
		return true;
	}

	public static function remove_user_from_group( $user_id, $group_id ) {
		global $wpdb;

		$wpdb->delete(
			$wpdb->user_group_members,
			array(
				'group_id' => (int) $group_id,
				'user_id'  => (int) $user_id,
			),
			array( '%d', '%d' )
		);

		self::invalidate_user_cache( (int) $user_id );
		return true;
	}

	/**
	 * Replace all of a user's group memberships at once.
	 */
	public static function set_user_groups( $user_id, array $group_ids ) {
		global $wpdb;
		$user_id = (int) $user_id;

		$wpdb->delete( $wpdb->user_group_members, array( 'user_id' => $user_id ), array( '%d' ) );

		$groups = self::get_all_groups();
		foreach ( $group_ids as $group_id ) {
			$group_id = (int) $group_id;
			if ( ! isset( $groups[ $group_id ] ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->user_group_members,
				array(
					'group_id' => $group_id,
					'user_id'  => $user_id,
				),
				array( '%d', '%d' )
			);
		}

		self::invalidate_user_cache( $user_id );
		return true;
	}

	/**
	 * @return int[]  User IDs.
	 */
	public static function get_group_members( $group_id ) {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->user_group_members} WHERE group_id = %d",
					(int) $group_id
				)
			)
		);
	}

	/**
	 * @return array<int, int>  group_id => member count
	 */
	public static function count_members_per_group() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT group_id, COUNT(*) AS cnt FROM {$wpdb->user_group_members} GROUP BY group_id"
		);
		$counts = array();
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$counts[ (int) $row->group_id ] = (int) $row->cnt;
			}
		}
		return $counts;
	}

	/* ------------------------------------------------------------------
	 * Capabilities
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string, bool>
	 */
	public static function get_capabilities_from_groups( $user_id, $blog_id = null ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		$key     = $user_id . ':' . $blog_id;

		if ( isset( self::$group_caps_cache[ $key ] ) ) {
			return self::$group_caps_cache[ $key ];
		}

		$caps = array();
		foreach ( self::get_user_groups( $user_id ) as $group_id => $group ) {
			if ( ! self::group_applies_to_site( $group_id, $blog_id ) ) {
				continue;
			}
			if ( empty( $group['role'] ) ) {
				continue;
			}

			$role = get_role( $group['role'] );
			if ( ! $role ) {
				continue;
			}

			foreach ( $role->capabilities as $cap => $granted ) {
				if ( $granted ) {
					$caps[ $cap ] = true;
				}
			}
			$caps[ 'role-' . $group['role'] ] = true;
		}

		self::$group_caps_cache[ $key ] = $caps;
		return $caps;
	}

	/* ------------------------------------------------------------------
	 * WordPress filters
	 * ---------------------------------------------------------------- */

	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}
		$group_caps = self::get_capabilities_from_groups( $user->ID );
		if ( empty( $group_caps ) ) {
			return $allcaps;
		}
		return array_merge( $allcaps, $group_caps );
	}

	public function filter_get_blogs_of_user( $blogs, $user_id, $all ) {
		$user_groups = self::get_user_groups( $user_id );
		if ( empty( $user_groups ) ) {
			return $blogs;
		}

		$site_ids = array();
		foreach ( $user_groups as $group_id => $group ) {
			if ( empty( $group['role'] ) ) {
				continue;
			}
			$sites = self::get_group_sites( $group_id );
			if ( empty( $sites ) ) {
				foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $id ) {
					$site_ids[ (int) $id ] = true;
				}
				continue;
			}
			foreach ( $sites as $id ) {
				$site_ids[ (int) $id ] = true;
			}
		}

		foreach ( array_keys( $site_ids ) as $site_id ) {
			if ( isset( $blogs[ $site_id ] ) ) {
				continue;
			}
			$details = get_site( $site_id );
			if ( ! $details ) {
				continue;
			}
			if ( ! $all && ( $details->archived || $details->spam || $details->deleted ) ) {
				continue;
			}
			$blogs[ $site_id ] = (object) array(
				'userblog_id' => (int) $details->blog_id,
				'blogname'    => $details->blogname,
				'domain'      => $details->domain,
				'path'        => $details->path,
				'site_id'     => (int) $details->site_id,
				'siteurl'     => $details->siteurl,
				'archived'    => $details->archived,
				'mature'      => $details->mature,
				'spam'        => $details->spam,
				'deleted'     => $details->deleted,
			);
		}

		return $blogs;
	}

	public function filter_is_user_member_of_blog( $is_member, $user_id, $blog_id ) {
		if ( $is_member ) {
			return $is_member;
		}
		foreach ( self::get_user_groups( $user_id ) as $group_id => $group ) {
			if ( empty( $group['role'] ) ) {
				continue;
			}
			if ( self::group_applies_to_site( $group_id, $blog_id ) ) {
				return true;
			}
		}
		return $is_member;
	}

	public function on_user_deleted( $user_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->user_group_members, array( 'user_id' => (int) $user_id ), array( '%d' ) );
		self::invalidate_user_cache( (int) $user_id );
	}

	public function on_site_deleted( $site ) {
		global $wpdb;
		$wpdb->delete( $wpdb->user_group_sites, array( 'blog_id' => (int) $site->blog_id ), array( '%d' ) );
		self::$group_sites_cache = null;
		self::$group_caps_cache  = array();
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	private static function format_group( $row ) {
		return array(
			'id'   => (int) $row->id,
			'name' => $row->name,
			'slug' => $row->slug,
			'role' => $row->role,
		);
	}

	private static function unique_slug( $slug, $exclude_id = 0 ) {
		global $wpdb;
		$base = $slug;
		$i    = 2;
		while ( true ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->user_groups} WHERE slug = %s AND id != %d",
					$slug,
					(int) $exclude_id
				)
			);
			if ( ! $existing ) {
				break;
			}
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}

	private static function invalidate_user_cache( $user_id ) {
		unset( self::$user_group_ids_cache[ $user_id ] );
		$prefix = $user_id . ':';
		foreach ( array_keys( self::$group_caps_cache ) as $key ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				unset( self::$group_caps_cache[ $key ] );
			}
		}
	}

	public static function flush_all_caches() {
		self::$groups_cache        = null;
		self::$group_sites_cache   = null;
		self::$user_group_ids_cache = array();
		self::$group_caps_cache    = array();
	}
}
