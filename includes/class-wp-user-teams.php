<?php
/**
 * Core User Teams logic.
 *
 * Storage relies on WordPress primitives that are already network-wide and
 * object-cached:
 *
 * - Team definitions live in a single site option (`wp_user_teams`).
 *   `get_site_option()` is per-network on multisite, per-install on single
 *   site, and is cached by the object cache after the first read.
 *
 * - User memberships live in user meta (`wp_user_teams` -> array of team
 *   IDs). `wp_usermeta` is a global table on multisite, and WordPress'
 *   metadata cache makes every repeat read in a request free.
 *
 * Capabilities are granted at runtime through `user_has_cap`, so removing
 * a user from a team drops their access on the next request.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Teams {

	const OPTION_KEY    = 'wp_user_teams';
	const USER_META_KEY = 'wp_user_teams';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );
		add_filter( 'get_blogs_of_user', array( $this, 'filter_get_blogs_of_user' ), 10, 3 );
		add_filter( 'get_user_metadata', array( $this, 'filter_get_user_metadata' ), 10, 3 );
		add_action( 'wp_delete_site', array( $this, 'on_site_deleted' ) );
	}

	/* ------------------------------------------------------------------
	 * Team CRUD
	 * ---------------------------------------------------------------- */

	/**
	 * Every team, keyed by team ID.
	 *
	 * @return array<int, array{id:int,name:string,slug:string,role:string,sites:int[]}>
	 */
	public static function get_all_teams() {
		$raw = get_site_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$teams = array();
		foreach ( $raw as $id => $team ) {
			$id = (int) $id;
			if ( $id <= 0 || ! is_array( $team ) ) {
				continue;
			}
			$teams[ $id ] = self::normalise_team( $id, $team );
		}
		return $teams;
	}

	public static function get_team( $team_id ) {
		$teams = self::get_all_teams();
		return $teams[ (int) $team_id ] ?? null;
	}

	public static function get_team_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		foreach ( self::get_all_teams() as $team ) {
			if ( $team['slug'] === $slug ) {
				return $team;
			}
		}
		return null;
	}

	/**
	 * @return int|WP_Error  New team ID on success.
	 */
	public static function create_team( $name, $slug = '', $role = '' ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
		}

		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'team';
		}

		$role = sanitize_key( $role );
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$teams   = self::get_all_teams();
		$slug    = self::unique_slug( $slug, $teams );
		$next_id = $teams ? ( max( array_keys( $teams ) ) + 1 ) : 1;

		$teams[ $next_id ] = self::normalise_team(
			$next_id,
			array(
				'name'  => $name,
				'slug'  => $slug,
				'role'  => $role,
				'sites' => array(),
			)
		);

		update_site_option( self::OPTION_KEY, $teams );
		return $next_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update_team( $team_id, array $data ) {
		$team_id = (int) $team_id;
		$teams   = self::get_all_teams();

		if ( ! isset( $teams[ $team_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Team not found.', 'wp-user-teams' ) );
		}

		$team = $teams[ $team_id ];

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
			}
			$team['name'] = $name;
		}

		if ( isset( $data['slug'] ) ) {
			$slug = sanitize_title( $data['slug'] );
			if ( '' === $slug ) {
				$slug = 'team';
			}
			foreach ( $teams as $other_id => $other ) {
				if ( $other_id !== $team_id && $other['slug'] === $slug ) {
					return new WP_Error( 'duplicate_slug', __( 'That slug is already in use.', 'wp-user-teams' ) );
				}
			}
			$team['slug'] = $slug;
		}

		if ( isset( $data['role'] ) ) {
			$role = sanitize_key( $data['role'] );
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			$team['role'] = $role;
		}

		$teams[ $team_id ] = self::normalise_team( $team_id, $team );
		update_site_option( self::OPTION_KEY, $teams );
		return true;
	}

	public static function delete_team( $team_id ) {
		$team_id = (int) $team_id;
		$teams   = self::get_all_teams();

		if ( ! isset( $teams[ $team_id ] ) ) {
			return false;
		}

		unset( $teams[ $team_id ] );
		update_site_option( self::OPTION_KEY, $teams );

		self::remove_team_from_all_users( $team_id );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Team → Site scoping
	 * ---------------------------------------------------------------- */

	/**
	 * Blog IDs that a team explicitly targets. Empty array = network-wide.
	 *
	 * Returns just the IDs; use `get_team_site_roles()` for the
	 * associative `blog_id => role_override` form.
	 *
	 * @return int[]
	 */
	public static function get_team_sites( $team_id ) {
		$team = self::get_team( $team_id );
		return $team ? array_keys( $team['sites'] ) : array();
	}

	/**
	 * Per-site role overrides for a team.
	 * Map of `blog_id => role_slug`. An empty role override means "fall
	 * back to the team's default role" (the top-level `role` field).
	 *
	 * @return array<int, string>
	 */
	public static function get_team_site_roles( $team_id ) {
		$team = self::get_team( $team_id );
		return $team ? $team['sites'] : array();
	}

	/**
	 * Set the sites a team applies to, optionally with per-site role overrides.
	 *
	 * Accepts either a flat list of blog IDs (each site inherits the team's
	 * default role) or an associative array `blog_id => role_slug` (empty
	 * role slug = fall back to default). An empty array means the team
	 * applies network-wide.
	 */
	public static function set_team_sites( $team_id, array $sites ) {
		$team_id = (int) $team_id;
		$teams   = self::get_all_teams();

		if ( ! isset( $teams[ $team_id ] ) ) {
			return false;
		}

		$teams[ $team_id ]['sites'] = self::normalise_site_roles( $sites );

		update_site_option( self::OPTION_KEY, $teams );
		return true;
	}

	/**
	 * Resolve the effective role for a team on a given site.
	 * Per-site override wins; empty override falls back to team default;
	 * no default means no caps granted (membership only).
	 */
	public static function resolve_role_for_site( array $team, $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( ! empty( $team['sites'] ) && isset( $team['sites'][ $blog_id ] ) && '' !== $team['sites'][ $blog_id ] ) {
			return $team['sites'][ $blog_id ];
		}
		return isset( $team['role'] ) ? (string) $team['role'] : '';
	}

	/**
	 * A team applies to a site when:
	 * - It has a Global Role set (network-wide), OR
	 * - The site has an explicit per-site role grant.
	 */
	public static function team_applies_to_site( $team_id, $blog_id = null ) {
		$team = self::get_team( $team_id );
		if ( ! $team ) {
			return false;
		}
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		if ( ! empty( $team['role'] ) ) {
			return true;
		}
		return array_key_exists( $blog_id, $team['sites'] );
	}

	/* ------------------------------------------------------------------
	 * User ↔ Team membership
	 * ---------------------------------------------------------------- */

	/**
	 * @return int[]
	 */
	public static function get_user_team_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$teams = self::get_all_teams();
		$ids   = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $teams[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Full team records for the user, keyed by team ID.
	 *
	 * @return array<int, array>
	 */
	public static function get_user_teams( $user_id ) {
		$ids   = self::get_user_team_ids( $user_id );
		$teams = self::get_all_teams();
		$out   = array();
		foreach ( $ids as $id ) {
			if ( isset( $teams[ $id ] ) ) {
				$out[ $id ] = $teams[ $id ];
			}
		}
		return $out;
	}

	public static function add_user_to_team( $user_id, $team_id ) {
		$user_id = (int) $user_id;
		$team_id = (int) $team_id;

		if ( ! self::get_team( $team_id ) ) {
			return false;
		}

		$ids = self::get_user_team_ids( $user_id );
		if ( in_array( $team_id, $ids, true ) ) {
			return true;
		}

		$ids[] = $team_id;
		update_user_meta( $user_id, self::USER_META_KEY, array_values( $ids ) );
		return true;
	}

	public static function remove_user_from_team( $user_id, $team_id ) {
		$user_id = (int) $user_id;
		$team_id = (int) $team_id;

		$ids = self::get_user_team_ids( $user_id );
		$new = array_values( array_diff( $ids, array( $team_id ) ) );

		if ( count( $new ) === count( $ids ) ) {
			return true;
		}

		if ( empty( $new ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
		} else {
			update_user_meta( $user_id, self::USER_META_KEY, $new );
		}
		return true;
	}

	public static function set_user_teams( $user_id, array $team_ids ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}

		$teams = self::get_all_teams();
		$clean = array();
		foreach ( $team_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && isset( $teams[ $id ] ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
		} else {
			update_user_meta( $user_id, self::USER_META_KEY, $clean );
		}
		return true;
	}

	/**
	 * User IDs that belong to a team.
	 *
	 * Uses the indexed meta_key lookup to find every user with *any* team
	 * memberships, then filters in PHP. Each per-user get_user_meta is
	 * served by the object cache on the hot path.
	 *
	 * @return int[]
	 */
	public static function get_team_members( $team_id ) {
		global $wpdb;
		$team_id = (int) $team_id;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USER_META_KEY
			)
		);

		$members = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$ids     = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( is_array( $ids ) && in_array( $team_id, array_map( 'intval', $ids ), true ) ) {
				$members[] = $user_id;
			}
		}
		return $members;
	}

	/**
	 * @return array<int, int>  team_id => member count
	 */
	public static function count_members_per_team() {
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
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
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
	public static function get_capabilities_from_teams( $user_id, $blog_id = null ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();

		$caps = array();
		foreach ( self::get_user_teams( $user_id ) as $team_id => $team ) {
			if ( ! self::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}

			$role_slug = self::resolve_role_for_site( $team, $blog_id );
			if ( empty( $role_slug ) ) {
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

	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}
		$team_caps = self::get_capabilities_from_teams( $user->ID );
		if ( empty( $team_caps ) ) {
			return $allcaps;
		}
		return array_merge( $allcaps, $team_caps );
	}

	public function filter_get_blogs_of_user( $blogs, $user_id, $all ) {
		$user_teams = self::get_user_teams( $user_id );
		if ( empty( $user_teams ) ) {
			return $blogs;
		}

		$site_ids = array();
		foreach ( $user_teams as $team_id => $team ) {
			if ( empty( $team['sites'] ) ) {
				// Network-wide team: include every site, but only when the
				// default role is set (a network-wide role-less team is just
				// a membership tag and shouldn't inflate "My Sites").
				if ( empty( $team['role'] ) ) {
					continue;
				}
				foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $id ) {
					$site_ids[ (int) $id ] = true;
				}
				continue;
			}
			foreach ( $team['sites'] as $site_id => $site_role_override ) {
				$effective = $site_role_override !== '' ? $site_role_override : ( $team['role'] ?? '' );
				if ( empty( $effective ) ) {
					continue;
				}
				$site_ids[ (int) $site_id ] = true;
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

	/**
	 * Fake membership for `is_user_member_of_blog()`.
	 *
	 * WP's `is_user_member_of_blog()` reads `{prefix}{blog_id}_capabilities`
	 * directly and has no filter hook (see https://core.trac.wordpress.org/ticket/65096).
	 * To avoid code paths that early-return on "not a member" for users whose
	 * access actually comes from a team, we short-circuit the capabilities
	 * usermeta read with an empty array — satisfying the `is_array()` check
	 * without granting any capabilities of its own. Real caps still flow
	 * through `user_has_cap`.
	 *
	 * @param mixed  $value   Filter pass-through; null means "continue lookup".
	 * @param int    $user_id User being queried.
	 * @param string $key     Meta key being read.
	 * @return mixed
	 */
	public function filter_get_user_metadata( $value, $user_id, $key ) {
		if ( null !== $value ) {
			return $value;
		}

		$blog_id = self::blog_id_from_capabilities_key( $key );
		if ( null === $blog_id ) {
			return $value;
		}

		// Recursion guard: the real lookup below re-enters this same filter.
		remove_filter( 'get_user_metadata', array( $this, 'filter_get_user_metadata' ), 10 );
		$existing = get_user_meta( $user_id, $key, true );
		add_filter( 'get_user_metadata', array( $this, 'filter_get_user_metadata' ), 10, 3 );

		if ( $existing ) {
			return $value;
		}

		foreach ( self::get_user_teams( $user_id ) as $team_id => $team ) {
			if ( ! self::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			if ( '' === self::resolve_role_for_site( $team, $blog_id ) ) {
				continue;
			}
			// `[ [] ]` unwraps to `[]` for `$single=true` — an array, but no caps.
			return array( array() );
		}

		return $value;
	}

	public function on_user_deleted( $user_id ) {
		delete_user_meta( (int) $user_id, self::USER_META_KEY );
	}

	public function on_site_deleted( $site ) {
		$blog_id = (int) $site->blog_id;
		$teams   = self::get_all_teams();
		$changed = false;

		foreach ( $teams as $id => $team ) {
			if ( empty( $team['sites'] ) ) {
				continue;
			}
			if ( isset( $team['sites'][ $blog_id ] ) ) {
				unset( $teams[ $id ]['sites'][ $blog_id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_site_option( self::OPTION_KEY, $teams );
		}
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Return the blog ID encoded in a `{prefix}capabilities` or
	 * `{prefix}{blog_id}_capabilities` user_meta key, or null if the key
	 * is not a capabilities key.
	 */
	private static function blog_id_from_capabilities_key( $key ) {
		global $wpdb;
		if ( $key === $wpdb->base_prefix . 'capabilities' ) {
			return 1;
		}
		if ( 1 !== preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+)_capabilities$/', $key, $m ) ) {
			return null;
		}
		return (int) $m[1];
	}

	private static function normalise_team( $id, array $team ) {
		return array(
			'id'    => (int) $id,
			'name'  => isset( $team['name'] ) ? (string) $team['name'] : '',
			'slug'  => isset( $team['slug'] ) ? sanitize_title( $team['slug'] ) : '',
			'role'  => isset( $team['role'] ) ? sanitize_key( $team['role'] ) : '',
			'sites' => isset( $team['sites'] ) && is_array( $team['sites'] )
				? self::normalise_site_roles( $team['sites'] )
				: array(),
		);
	}

	/**
	 * Normalise the team's site scope into the canonical
	 * `blog_id => role_slug` form.
	 *
	 * Accepts legacy shapes too:
	 * - sequential numeric list of blog IDs -> each maps to empty role override
	 * - associative blog_id => role_slug      -> preserved, role validated
	 *
	 * Invalid blog IDs (<= 0) and unknown role slugs are dropped.
	 *
	 * @return array<int, string>
	 */
	private static function normalise_site_roles( array $sites ) {
		if ( empty( $sites ) ) {
			return array();
		}

		$is_list = ( array_keys( $sites ) === range( 0, count( $sites ) - 1 ) );
		$roles   = wp_roles();
		$out     = array();

		foreach ( $sites as $key => $value ) {
			if ( $is_list ) {
				$blog_id = (int) $value;
				$role    = '';
			} else {
				$blog_id = (int) $key;
				$role    = is_string( $value ) ? sanitize_key( $value ) : '';
			}
			if ( $blog_id <= 0 ) {
				continue;
			}
			if ( '' !== $role && ! $roles->is_role( $role ) ) {
				$role = '';
			}
			$out[ $blog_id ] = $role;
		}

		return $out;
	}

	private static function unique_slug( $slug, array $teams, $exclude_id = 0 ) {
		$base = $slug;
		$i    = 2;
		while ( true ) {
			$conflict = false;
			foreach ( $teams as $other_id => $other ) {
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
	 * Strip one team ID from every user's membership list.
	 *
	 * Relies on the meta_key index (ref lookup); per-user reads after the
	 * first hit the object cache.
	 */
	private static function remove_team_from_all_users( $team_id ) {
		global $wpdb;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USER_META_KEY
			)
		);

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$ids     = get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$filtered = array_values( array_diff( array_map( 'intval', $ids ), array( $team_id ) ) );
			if ( count( $filtered ) === count( $ids ) ) {
				continue;
			}
			if ( empty( $filtered ) ) {
				delete_user_meta( $user_id, self::USER_META_KEY );
			} else {
				update_user_meta( $user_id, self::USER_META_KEY, $filtered );
			}
		}
	}

	/**
	 * Test helper. Storage is read through WordPress' own caches, which
	 * WP_UnitTestCase flushes between tests, but we expose a hook for
	 * tests that want to force a fresh read mid-test.
	 */
	public static function flush_all_caches() {
		wp_cache_delete( self::OPTION_KEY, 'site-options' );
	}
}
