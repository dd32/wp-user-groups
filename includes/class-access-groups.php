<?php
/**
 * Core Access Groups logic.
 *
 * A group is:
 *
 *   - a `default_role` (stored as `role` for brevity in the option) that
 *     applies to every site the group is scoped to, and
 *   - a `sites` map of `blog_id => role_override`. An empty map means
 *     "every site on the network". An entry with an empty-string override
 *     means "this site, use the default role". A non-empty override wins
 *     over the default on that specific site.
 *
 * Storage relies on WordPress primitives that are already network-wide and
 * object-cached:
 *
 * - Group definitions live in a single site option (`access_groups`).
 *   `get_site_option()` is per-network on multisite, per-install on single
 *   site, and is cached by the object cache after the first read.
 *
 * - User memberships live in user meta. Two keys work together:
 *
 *     `{base_prefix}access_groups`        -> array of group IDs the user
 *                                            belongs to (authoritative).
 *     `{base_prefix}access_group_{id}`    -> presence marker, one row per
 *                                            (user, group). Lets us answer
 *                                            "who is in group N?" with an
 *                                            indexed `meta_key` lookup,
 *                                            no LIKE or PHP-side filtering.
 *
 *   `wp_usermeta` is global on multisite, and `$wpdb->base_prefix` is
 *   constant across the network, so a user's memberships follow them
 *   across every site.
 *
 * Capabilities are granted at runtime through `user_has_cap`, so removing
 * a user from a group drops their access on the next request.
 */

defined( 'ABSPATH' ) || exit;

class Access_Groups {

	const OPTION_KEY = 'access_groups';

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
	 * Meta key helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Primary membership key: an array of group IDs stored on the user.
	 */
	public static function user_meta_key() {
		global $wpdb;
		return $wpdb->base_prefix . 'access_groups';
	}

	/**
	 * Per-group presence marker: one usermeta row per (user, group) for
	 * indexed `meta_key` lookups.
	 */
	public static function group_meta_key( $group_id ) {
		global $wpdb;
		return $wpdb->base_prefix . 'access_group_' . (int) $group_id;
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
			return new WP_Error( 'missing_name', __( 'A name is required.', 'access-groups' ) );
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
			return new WP_Error( 'not_found', __( 'Group not found.', 'access-groups' ) );
		}

		$group = $groups[ $group_id ];

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'access-groups' ) );
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
	 * Sites the group applies to, keyed by blog ID, value = per-site role
	 * override (empty string means "use the group's default role on this
	 * site"). An empty map means "every site on the network, including
	 * future ones, with the default role".
	 *
	 * @return array<int, string>
	 */
	public static function get_group_sites( $group_id ) {
		$group = self::get_group( $group_id );
		return $group ? $group['sites'] : array();
	}

	/**
	 * Replace the site scope for a group.
	 *
	 * $sites may be passed either as:
	 *   - int[]                       (blog IDs, all using the default role)
	 *   - array<int, string>          (blog_id => role override; empty
	 *                                  override means "use the default role")
	 *
	 * Pass an empty array to mean "all sites".
	 */
	public static function set_group_sites( $group_id, array $sites ) {
		$group_id = (int) $group_id;
		$groups   = self::get_all_groups();

		if ( ! isset( $groups[ $group_id ] ) ) {
			return false;
		}

		$groups[ $group_id ]['sites'] = self::normalise_sites( $sites );

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
		return array_key_exists( $blog_id, $group['sites'] );
	}

	/**
	 * Resolve the effective role a group grants on a given site. Returns
	 * an empty string when the group doesn't apply to the site or when
	 * neither the per-site override nor the default role is set.
	 *
	 * @param int|array $group   Group ID or group record.
	 * @param int|null  $blog_id Defaults to the current blog.
	 */
	public static function effective_role( $group, $blog_id = null ) {
		if ( is_numeric( $group ) ) {
			$group = self::get_group( $group );
		}
		if ( ! is_array( $group ) ) {
			return '';
		}

		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();

		if ( empty( $group['sites'] ) ) {
			return (string) $group['role'];
		}

		if ( ! array_key_exists( $blog_id, $group['sites'] ) ) {
			return '';
		}

		$override = (string) $group['sites'][ $blog_id ];
		return '' !== $override ? $override : (string) $group['role'];
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

		$raw = get_user_meta( $user_id, self::user_meta_key(), true );
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
		update_user_meta( $user_id, self::user_meta_key(), array_values( $ids ) );
		update_user_meta( $user_id, self::group_meta_key( $group_id ), 1 );
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
			delete_user_meta( $user_id, self::user_meta_key() );
		} else {
			update_user_meta( $user_id, self::user_meta_key(), $new );
		}
		delete_user_meta( $user_id, self::group_meta_key( $group_id ) );
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

		$previous = self::get_user_group_ids( $user_id );

		if ( empty( $clean ) ) {
			delete_user_meta( $user_id, self::user_meta_key() );
		} else {
			update_user_meta( $user_id, self::user_meta_key(), $clean );
		}

		foreach ( array_diff( $previous, $clean ) as $removed_id ) {
			delete_user_meta( $user_id, self::group_meta_key( (int) $removed_id ) );
		}
		foreach ( array_diff( $clean, $previous ) as $added_id ) {
			update_user_meta( $user_id, self::group_meta_key( (int) $added_id ), 1 );
		}

		self::invalidate_membership_caches();
		return true;
	}

	/**
	 * User IDs that belong to a group.
	 *
	 * One indexed `meta_key` lookup — the per-group presence marker is
	 * exactly what a standard usermeta index is built for.
	 *
	 * @return int[]
	 */
	public static function get_group_members( $group_id ) {
		global $wpdb;
		$group_id = (int) $group_id;
		if ( $group_id <= 0 ) {
			return array();
		}

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::group_meta_key( $group_id )
			)
		);

		return array_map( 'intval', $user_ids );
	}

	/**
	 * @return array<int, int>  group_id => member count
	 */
	public static function count_members_per_group() {
		if ( is_array( self::$count_cache ) ) {
			return self::$count_cache;
		}

		global $wpdb;
		$prefix = $wpdb->base_prefix . 'access_group_';
		$like   = $wpdb->esc_like( $prefix ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, COUNT(user_id) AS n FROM {$wpdb->usermeta} WHERE meta_key LIKE %s GROUP BY meta_key",
				$like
			),
			ARRAY_A
		);

		$counts = array();
		$offset = strlen( $prefix );
		foreach ( $rows as $row ) {
			$group_id = (int) substr( $row['meta_key'], $offset );
			if ( $group_id > 0 ) {
				$counts[ $group_id ] = (int) $row['n'];
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

			$role_slug = self::effective_role( $group, $blog_id );
			if ( '' === $role_slug ) {
				continue;
			}

			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $role->capabilities as $cap => $granted ) {
				if ( $granted ) {
					$caps[ $cap ] = true;
				}
			}
			$caps[ 'role-' . $role_slug ] = true;
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
			if ( empty( $group['sites'] ) ) {
				// All-sites scope — only useful if the default role is set.
				if ( ! empty( $group['role'] ) ) {
					$has_all_sites_group = true;
				}
				continue;
			}
			foreach ( $group['sites'] as $id => $override ) {
				$effective = '' !== (string) $override ? (string) $override : (string) $group['role'];
				if ( '' !== $effective ) {
					$site_ids[ (int) $id ] = true;
				}
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
			if ( ! self::group_applies_to_site( $group_id, $blog_id ) ) {
				continue;
			}
			if ( '' !== self::effective_role( $group, $blog_id ) ) {
				return true;
			}
		}
		return $is_member;
	}

	public function on_user_deleted( $user_id ) {
		$user_id = (int) $user_id;
		$ids     = get_user_meta( $user_id, self::user_meta_key(), true );
		if ( is_array( $ids ) ) {
			foreach ( $ids as $group_id ) {
				delete_user_meta( $user_id, self::group_meta_key( (int) $group_id ) );
			}
		}
		delete_user_meta( $user_id, self::user_meta_key() );
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
			if ( array_key_exists( $blog_id, $group['sites'] ) ) {
				unset( $groups[ $id ]['sites'][ $blog_id ] );
				$changed = true;
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
				? self::normalise_sites( $group['sites'] )
				: array(),
		);
	}

	/**
	 * Turn a caller-supplied sites array into the canonical
	 * `blog_id => role_override` map. Accepts either an int[] of blog IDs
	 * (all using the default role) or an associative map with role-slug
	 * values; mixed input is fine. Invalid roles are dropped.
	 *
	 * @return array<int, string>
	 */
	private static function normalise_sites( array $sites ) {
		$result = array();
		foreach ( $sites as $key => $value ) {
			if ( is_int( $key ) && ( is_int( $value ) || ctype_digit( (string) $value ) ) ) {
				// `[ 3, 7, 9 ]` — bare list of blog IDs, no role overrides.
				$blog_id = (int) $value;
				$role    = '';
			} else {
				$blog_id = (int) $key;
				$role    = is_string( $value ) ? sanitize_key( $value ) : '';
			}

			if ( $blog_id <= 0 ) {
				continue;
			}
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			$result[ $blog_id ] = $role;
		}
		return $result;
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
	 * Uses the per-group index to find everyone affected — one indexed
	 * lookup, no full-table scan.
	 */
	private static function remove_group_from_all_users( $group_id ) {
		global $wpdb;
		$group_id    = (int) $group_id;
		$primary_key = self::user_meta_key();
		$marker_key  = self::group_meta_key( $group_id );

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$marker_key
			)
		);

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$ids     = get_user_meta( $user_id, $primary_key, true );
			if ( is_array( $ids ) ) {
				$filtered = array_values( array_diff( array_map( 'intval', $ids ), array( $group_id ) ) );
				if ( empty( $filtered ) ) {
					delete_user_meta( $user_id, $primary_key );
				} elseif ( count( $filtered ) !== count( $ids ) ) {
					update_user_meta( $user_id, $primary_key, $filtered );
				}
			}
			delete_user_meta( $user_id, $marker_key );
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
