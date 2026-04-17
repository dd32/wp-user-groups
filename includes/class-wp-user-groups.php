<?php
/**
 * Core User Groups logic.
 *
 * Storage relies on WordPress primitives that are already network-wide and
 * object-cached:
 *
 * - Group definitions live in a single site option (`wp_user_groups`).
 *   `get_site_option()` is per-network on multisite, per-install on single
 *   site, and is cached by the object cache after the first read.
 *
 * - User memberships live in user meta (`wp_user_groups` -> array of group
 *   IDs). `wp_usermeta` is a global table on multisite, and WordPress'
 *   metadata cache makes every repeat read in a request free.
 *
 * Capabilities are granted at runtime through `user_has_cap`, so removing
 * a user from a group drops their access on the next request.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Groups {

	const OPTION_KEY    = 'wp_user_groups';
	const USER_META_KEY = 'wp_user_groups';

	private static $instance;

	/**
	 * Per-request caches. Invalidated whenever a mutation happens so callers
	 * never see stale data within a single request.
	 */
	private static $all_groups_cache = null;
	private static $cap_cache        = array();
	private static $count_cache      = null;

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
	 * @return array<int, array{id:int,name:string,slug:string,role:string,sites:int[]}>
	 */
	public static function get_all_groups() {
		if ( is_array( self::$all_groups_cache ) ) {
			return self::$all_groups_cache;
		}

		$raw = get_site_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			self::$all_groups_cache = array();
			return self::$all_groups_cache;
		}

		$groups = array();
		foreach ( $raw as $id => $group ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $group ) ) {
				continue;
			}
			$groups[ $id ] = self::normalise_group( $id, $group );
		}

		self::$all_groups_cache = $groups;
		return $groups;
	}

	public static function get_group( $group_id ) {
		$groups = self::get_all_groups();
		return isset( $groups[ (int) $group_id ] ) ? $groups[ (int) $group_id ] : null;
	}

	public static function get_group_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		foreach ( self::get_all_groups() as $group ) {
			if ( $group['slug'] === $slug ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * @return int|WP_Error  New group ID on success.
	 */
	public static function create_group( $name, $slug = '', $role = '' ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-groups' ) );
		}

		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'group';
		}

		$role = sanitize_key( $role );
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$groups  = self::get_all_groups();
		$slug    = self::unique_slug( $slug, $groups );
		$next_id = $groups ? ( max( array_keys( $groups ) ) + 1 ) : 1;

		$groups[ $next_id ] = self::normalise_group(
			$next_id,
			array(
				'name'  => $name,
				'slug'  => $slug,
				'role'  => $role,
				'sites' => array(),
			)
		);

		self::save_groups( $groups );
		return $next_id;
	}

	/**
	 * Update fields on an existing group. Duplicate slugs are resolved by
	 * appending a numeric suffix, matching the behaviour of `create_group`.
	 *
	 * @return true|WP_Error
	 */
	public static function update_group( $group_id, array $data ) {
		$group_id = (int) $group_id;
		$groups   = self::get_all_groups();

		if ( ! isset( $groups[ $group_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Group not found.', 'wp-user-groups' ) );
		}

		$group = $groups[ $group_id ];

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-groups' ) );
			}
			$group['name'] = $name;
		}

		if ( isset( $data['slug'] ) ) {
			$slug = sanitize_title( $data['slug'] );
			if ( '' === $slug ) {
				$slug = 'group';
			}
			$group['slug'] = self::unique_slug( $slug, $groups, $group_id );
		}

		if ( isset( $data['role'] ) ) {
			$role = sanitize_key( $data['role'] );
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			$group['role'] = $role;
		}

		$groups[ $group_id ] = self::normalise_group( $group_id, $group );
		self::save_groups( $groups );
		return true;
	}

	public static function delete_group( $group_id ) {
		$group_id = (int) $group_id;
		$groups   = self::get_all_groups();

		if ( ! isset( $groups[ $group_id ] ) ) {
			return false;
		}

		unset( $groups[ $group_id ] );
		self::save_groups( $groups );

		self::remove_group_from_all_users( $group_id );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Group → Site scoping
	 * ---------------------------------------------------------------- */

	/**
	 * Blog IDs that a group explicitly targets.
	 * Empty array = "every site on the network, including future ones".
	 *
	 * @return int[]
	 */
	public static function get_group_sites( $group_id ) {
		$group = self::get_group( $group_id );
		return $group ? $group['sites'] : array();
	}

	public static function set_group_sites( $group_id, array $blog_ids ) {
		$group_id = (int) $group_id;
		$groups   = self::get_all_groups();

		if ( ! isset( $groups[ $group_id ] ) ) {
			return false;
		}

		$groups[ $group_id ]['sites'] = array_values(
			array_unique( array_filter( array_map( 'intval', $blog_ids ) ) )
		);

		self::save_groups( $groups );
		return true;
	}

	public static function group_applies_to_site( $group_id, $blog_id = null ) {
		if ( ! is_multisite() ) {
			return true;
		}
		$group = self::get_group( $group_id );
		if ( ! $group ) {
			return false;
		}
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		if ( empty( $group['sites'] ) ) {
			return true;
		}
		return in_array( $blog_id, $group['sites'], true );
	}

	/* ------------------------------------------------------------------
	 * User ↔ Group membership
	 * ---------------------------------------------------------------- */

	/**
	 * @return int[]
	 */
	public static function get_user_group_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups = self::get_all_groups();
		$ids    = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $groups[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Full group records for the user, keyed by group ID.
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
		$user_id  = (int) $user_id;
		$group_id = (int) $group_id;

		if ( ! self::get_group( $group_id ) ) {
			return false;
		}

		$ids = self::get_user_group_ids( $user_id );
		if ( in_array( $group_id, $ids, true ) ) {
			return true;
		}

		$ids[] = $group_id;
		update_user_meta( $user_id, self::USER_META_KEY, array_values( $ids ) );
		self::invalidate_membership_caches();
		return true;
	}

	public static function remove_user_from_group( $user_id, $group_id ) {
		$user_id  = (int) $user_id;
		$group_id = (int) $group_id;

		$ids = self::get_user_group_ids( $user_id );
		$new = array_values( array_diff( $ids, array( $group_id ) ) );

		if ( count( $new ) === count( $ids ) ) {
			return true;
		}

		if ( empty( $new ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
		} else {
			update_user_meta( $user_id, self::USER_META_KEY, $new );
		}
		self::invalidate_membership_caches();
		return true;
	}

	public static function set_user_groups( $user_id, array $group_ids ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}

		$groups = self::get_all_groups();
		$clean  = array();
		foreach ( $group_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $groups[ $id ] ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
		} else {
			update_user_meta( $user_id, self::USER_META_KEY, $clean );
		}
		self::invalidate_membership_caches();
		return true;
	}

	/**
	 * User IDs that belong to a group.
	 *
	 * Prefilters the usermeta scan with a LIKE on the serialized `i:<id>;`
	 * token so we only load metadata for users who plausibly belong. LIKE
	 * may collide with serialized array keys, so we still verify in PHP.
	 *
	 * @return int[]
	 */
	public static function get_group_members( $group_id ) {
		global $wpdb;
		$group_id = (int) $group_id;
		if ( $group_id <= 0 ) {
			return array();
		}

		$needle = 'i:' . $group_id . ';';

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::USER_META_KEY,
				'%' . $wpdb->esc_like( $needle ) . '%'
			)
		);

		$members = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$ids     = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( is_array( $ids ) && in_array( $group_id, array_map( 'intval', $ids ), true ) ) {
				$members[] = $user_id;
			}
		}
		return $members;
	}

	/**
	 * @return array<int, int>  group_id => member count
	 */
	public static function count_members_per_group() {
		if ( is_array( self::$count_cache ) ) {
			return self::$count_cache;
		}

		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USER_META_KEY
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$ids = maybe_unserialize( $row );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id <= 0 ) {
					continue;
				}
				$counts[ $id ] = isset( $counts[ $id ] ) ? $counts[ $id ] + 1 : 1;
			}
		}

		self::$count_cache = $counts;
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

		return $caps;
	}

	/* ------------------------------------------------------------------
	 * WordPress filters / actions
	 * ---------------------------------------------------------------- */

	/**
	 * `user_has_cap` fires on every `current_user_can()` call, so the merged
	 * group cap set is memoized per (user, blog) for the duration of the
	 * request. Mutations call `invalidate_membership_caches()` to bust it.
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}

		$blog_id = (int) get_current_blog_id();
		$key     = $user->ID . ':' . $blog_id;

		if ( ! isset( self::$cap_cache[ $key ] ) ) {
			self::$cap_cache[ $key ] = self::get_capabilities_from_groups( $user->ID, $blog_id );
		}

		$group_caps = self::$cap_cache[ $key ];
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

		$has_all_sites_group = false;
		$site_ids            = array();
		foreach ( $user_groups as $group ) {
			if ( empty( $group['role'] ) ) {
				continue;
			}
			if ( empty( $group['sites'] ) ) {
				$has_all_sites_group = true;
				continue;
			}
			foreach ( $group['sites'] as $id ) {
				$site_ids[ (int) $id ] = true;
			}
		}

		if ( ! $has_all_sites_group && empty( $site_ids ) ) {
			return $blogs;
		}

		if ( $has_all_sites_group ) {
			foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $id ) {
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
		delete_user_meta( (int) $user_id, self::USER_META_KEY );
		self::invalidate_membership_caches();
	}

	public function on_site_deleted( $site ) {
		$blog_id = (int) $site->blog_id;
		$groups  = self::get_all_groups();
		$changed = false;

		foreach ( $groups as $id => $group ) {
			if ( empty( $group['sites'] ) ) {
				continue;
			}
			$filtered = array_values( array_diff( $group['sites'], array( $blog_id ) ) );
			if ( count( $filtered ) !== count( $group['sites'] ) ) {
				$groups[ $id ]['sites'] = $filtered;
				$changed                = true;
			}
		}

		if ( $changed ) {
			self::save_groups( $groups );
		}
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	private static function normalise_group( $id, array $group ) {
		return array(
			'id'    => (int) $id,
			'name'  => isset( $group['name'] ) ? (string) $group['name'] : '',
			'slug'  => isset( $group['slug'] ) ? sanitize_title( $group['slug'] ) : '',
			'role'  => isset( $group['role'] ) ? sanitize_key( $group['role'] ) : '',
			'sites' => isset( $group['sites'] ) && is_array( $group['sites'] )
				? array_values( array_unique( array_filter( array_map( 'intval', $group['sites'] ) ) ) )
				: array(),
		);
	}

	private static function unique_slug( $slug, array $groups, $exclude_id = 0 ) {
		$base = $slug;
		$i    = 2;
		while ( true ) {
			$conflict = false;
			foreach ( $groups as $other_id => $other ) {
				if ( (int) $other_id === (int) $exclude_id ) {
					continue;
				}
				if ( $other['slug'] === $slug ) {
					$conflict = true;
					break;
				}
			}
			if ( ! $conflict ) {
				return $slug;
			}
			$slug = $base . '-' . $i;
			++$i;
		}
	}

	/**
	 * Persist the groups option and invalidate anything derived from it.
	 */
	private static function save_groups( array $groups ) {
		update_site_option( self::OPTION_KEY, $groups );
		self::$all_groups_cache = null;
		self::$cap_cache        = array();
	}

	private static function invalidate_membership_caches() {
		self::$cap_cache   = array();
		self::$count_cache = null;
	}

	/**
	 * Strip one group ID from every user's membership list.
	 *
	 * Relies on the meta_key index (ref lookup); per-user reads after the
	 * first hit the object cache.
	 */
	private static function remove_group_from_all_users( $group_id ) {
		global $wpdb;

		$needle = 'i:' . (int) $group_id . ';';

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::USER_META_KEY,
				'%' . $wpdb->esc_like( $needle ) . '%'
			)
		);

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$ids     = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$filtered = array_values( array_diff( array_map( 'intval', $ids ), array( $group_id ) ) );
			if ( count( $filtered ) === count( $ids ) ) {
				continue;
			}
			if ( empty( $filtered ) ) {
				delete_user_meta( $user_id, self::USER_META_KEY );
			} else {
				update_user_meta( $user_id, self::USER_META_KEY, $filtered );
			}
		}

		self::invalidate_membership_caches();
	}

	/**
	 * Test helper. Flushes the option cache and our per-request caches so a
	 * test can force a fresh read mid-run. No-op outside the PHPUnit harness.
	 */
	public static function flush_all_caches() {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}
		wp_cache_delete( self::OPTION_KEY, is_multisite() ? 'site-options' : 'options' );
		self::$all_groups_cache = null;
		self::$cap_cache        = array();
		self::$count_cache      = null;
	}
}
