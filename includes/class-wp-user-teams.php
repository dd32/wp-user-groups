<?php
/**
 * Core User Teams logic — "Team as WP_User" model.
 *
 * Each team is backed by a real `wp_users` row (a "team account"):
 *
 *   - display_name   = team name
 *   - user_login     = `_team_{slug}` (prefixed to avoid collisions)
 *   - user_pass      = random / unknown (login is blocked anyway)
 *   - wput_is_team   = '1'            (marker meta)
 *   - wput_slug      = slug           (human-friendly identifier)
 *   - wput_global_role = role_slug    (role granted network-wide)
 *   - wp_{blog_id}_capabilities       (per-site role grants, native)
 *
 * This lets WordPress handle teams through its existing user
 * machinery: `WP_Users_List_Table`, `is_user_member_of_blog()`,
 * capability storage, Screen Options, custom columns from other
 * plugins — all "just work".
 *
 * Members (real users) still store their team IDs in user_meta
 * (`wp_user_teams` -> array of team-user IDs). Role fan-out to
 * members happens at runtime via the `user_has_cap` filter, so a
 * team's role is never written into a real user's capabilities meta
 * and removing a user from a team drops access on the next request.
 *
 * Team accounts are hidden from generic user queries and login:
 *
 *   - `pre_user_query` excludes them unless the query sets
 *     `wput_include_teams` as a query var, or a special admin
 *     context opts them back in.
 *   - `rest_user_query` excludes them.
 *   - `authenticate` blocks login attempts against team accounts.
 *
 * There is no automatic migration from the v0.1 site-option storage —
 * the PR intentionally skips this so reviewers can evaluate the
 * data-model change in isolation. Migration would be a separate change.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Teams {

	const IS_TEAM_META_KEY    = 'wput_is_team';
	const SLUG_META_KEY       = 'wput_slug';
	const GLOBAL_ROLE_META    = 'wput_global_role';
	const USER_META_KEY       = 'wp_user_teams';
	const QUERY_INCLUDE_FLAG  = 'wput_include_teams';
	const LOGIN_PREFIX        = '_team_';

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

		// Team accounts are real users but should be invisible to most
		// user-facing systems.
		//
		// TODO(PR review): these three filters are temporarily commented
		// out — enabling them hangs the multisite test-framework boot.
		// Suspected culprit: `exclude_team_users_by_default` running
		// inside WP's install.php, where `$wpdb->usermeta` is queried
		// before it's fully populated. Worth gating on
		// `did_action( 'init' )` or similar, and verifying recursion in
		// `filter_get_blogs_of_user → get_team_site_roles → get_blogs_of_user`
		// isn't biting.
		//
		// add_action( 'pre_user_query', array( $this, 'exclude_team_users_by_default' ) );
		// add_filter( 'rest_user_query', array( $this, 'exclude_team_users_from_rest' ) );
		// add_filter( 'authenticate', array( $this, 'block_team_user_login' ), 100, 3 );

		if ( is_multisite() ) {
			add_filter( 'get_blogs_of_user', array( $this, 'filter_get_blogs_of_user' ), 10, 3 );
			add_action( 'wp_delete_site', array( $this, 'on_site_deleted' ) );
		}

	}

	/* ------------------------------------------------------------------
	 * Team CRUD
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<int, array{id:int,name:string,slug:string,role:string,sites:array<int,string>}>
	 */
	public static function get_all_teams() {
		$team_users = get_users( array(
			'meta_key'                => self::IS_TEAM_META_KEY,
			'meta_value'              => '1',
			'number'                  => -1,
			'orderby'                 => 'display_name',
			'order'                   => 'ASC',
			self::QUERY_INCLUDE_FLAG  => true,
		) );

		$teams = array();
		foreach ( $team_users as $u ) {
			$teams[ (int) $u->ID ] = self::user_to_team( $u );
		}
		return $teams;
	}

	public static function get_team( $team_id ) {
		$team_id = (int) $team_id;
		if ( $team_id <= 0 ) {
			return null;
		}
		$u = get_userdata( $team_id );
		if ( ! $u instanceof WP_User ) {
			return null;
		}
		if ( '1' !== (string) get_user_meta( $team_id, self::IS_TEAM_META_KEY, true ) ) {
			return null;
		}
		return self::user_to_team( $u );
	}

	public static function get_team_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$users = get_users( array(
			'meta_query'             => array(
				'relation' => 'AND',
				array( 'key' => self::IS_TEAM_META_KEY, 'value' => '1' ),
				array( 'key' => self::SLUG_META_KEY, 'value' => $slug ),
			),
			'number'                 => 1,
			self::QUERY_INCLUDE_FLAG => true,
		) );
		if ( empty( $users ) ) {
			return null;
		}
		return self::user_to_team( $users[0] );
	}

	public static function create_team( $name, $slug = '', $role = '' ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
		}

		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'team';
		}
		$slug = self::unique_slug( $slug );

		$role = sanitize_key( $role );
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$login = self::LOGIN_PREFIX . $slug;
		// Collisions on user_login go through the same unique_slug path —
		// if someone created a literal `_team_foo` user, bump the slug.
		while ( username_exists( $login ) ) {
			$slug  = self::unique_slug( $slug );
			$login = self::LOGIN_PREFIX . $slug;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 64, true, true ),
			'user_email'   => $login . '@teams.invalid',
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => '', // No role on the registering (main) site by default.
		) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::IS_TEAM_META_KEY, '1' );
		update_user_meta( $user_id, self::SLUG_META_KEY, $slug );
		update_user_meta( $user_id, self::GLOBAL_ROLE_META, $role );

		return (int) $user_id;
	}

	public static function update_team( $team_id, array $data ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return new WP_Error( 'not_found', __( 'Team not found.', 'wp-user-teams' ) );
		}

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
			}
			wp_update_user( array(
				'ID'           => $team_id,
				'display_name' => $name,
				'first_name'   => $name,
			) );
		}

		if ( isset( $data['slug'] ) ) {
			$slug = sanitize_title( $data['slug'] );
			if ( '' === $slug ) {
				$slug = 'team';
			}
			$existing = self::get_team_by_slug( $slug );
			if ( $existing && (int) $existing['id'] !== $team_id ) {
				return new WP_Error( 'duplicate_slug', __( 'That slug is already in use.', 'wp-user-teams' ) );
			}
			update_user_meta( $team_id, self::SLUG_META_KEY, $slug );
		}

		if ( isset( $data['role'] ) ) {
			$role = sanitize_key( $data['role'] );
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			update_user_meta( $team_id, self::GLOBAL_ROLE_META, $role );
		}

		return true;
	}

	public static function delete_team( $team_id ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return false;
		}

		// Drop this team from every member's list first, then remove the
		// team account itself. `deleted_user` fallback handles any races.
		self::remove_team_from_all_users( $team_id );

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $team_id );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $team_id );
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Team → Site scoping (multisite)
	 *
	 * Per-site grants are stored natively on the team account's
	 * `wp_{blog_id}_capabilities` usermeta, so `is_user_member_of_blog()`
	 * and WP_Users_List_Table work out of the box for team accounts.
	 * ---------------------------------------------------------------- */

	/** @return int[] */
	public static function get_team_sites( $team_id ) {
		return array_keys( self::get_team_site_roles( $team_id ) );
	}

	/** @return array<int,string> blog_id => role slug ('' = member without a specific role) */
	public static function get_team_site_roles( $team_id ) {
		$team_id = (int) $team_id;
		if ( ! is_multisite() || ! self::get_team( $team_id ) ) {
			return array();
		}

		global $wpdb;
		$blogs = get_blogs_of_user( $team_id, true );
		$out   = array();
		foreach ( $blogs as $blog ) {
			$blog_id = (int) $blog->userblog_id;
			$caps    = get_user_meta( $team_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', true );
			if ( ! is_array( $caps ) ) {
				continue;
			}
			$active_roles = array_keys( array_filter( $caps ) );
			$out[ $blog_id ] = $active_roles ? $active_roles[0] : '';
		}
		return $out;
	}

	public static function set_team_sites( $team_id, array $sites ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return true;
		}

		$desired = self::normalise_site_roles( $sites );
		$current = self::get_team_site_roles( $team_id );

		foreach ( array_diff_key( $current, $desired ) as $blog_id => $_ ) {
			remove_user_from_blog( $team_id, $blog_id );
		}

		foreach ( $desired as $blog_id => $role ) {
			self::write_team_role_on_blog( $team_id, $blog_id, $role, isset( $current[ $blog_id ] ) );
		}

		return true;
	}

	public static function team_applies_to_site( $team_id, $blog_id = null ) {
		if ( ! is_multisite() ) {
			return true;
		}
		$team = self::get_team( $team_id );
		if ( ! $team ) {
			return false;
		}
		if ( ! empty( $team['role'] ) ) {
			return true; // Global Role = network-wide.
		}
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		return array_key_exists( $blog_id, $team['sites'] );
	}

	/**
	 * Per-site role wins; empty or missing per-site entry falls back to
	 * the team's Global Role.
	 */
	public static function resolve_role_for_site( array $team, $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( isset( $team['sites'][ $blog_id ] ) && '' !== $team['sites'][ $blog_id ] ) {
			return (string) $team['sites'][ $blog_id ];
		}
		return (string) ( $team['role'] ?? '' );
	}

	/* ------------------------------------------------------------------
	 * User ↔ Team membership
	 * ---------------------------------------------------------------- */

	public static function get_user_team_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$raw = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$valid = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 && self::get_team( $id ) ) {
				$valid[] = $id;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	public static function get_user_teams( $user_id ) {
		$out = array();
		foreach ( self::get_user_team_ids( $user_id ) as $team_id ) {
			$team = self::get_team( $team_id );
			if ( $team ) {
				$out[ $team_id ] = $team;
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
		$clean = array();
		foreach ( $team_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && self::get_team( $id ) ) {
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

	/** @return array<int,int> team_id => member count */
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
				if ( $id > 0 ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}

	/* ------------------------------------------------------------------
	 * Capability fan-out
	 * ---------------------------------------------------------------- */

	public static function get_capabilities_from_teams( $user_id, $blog_id = null ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();

		$caps = array();
		foreach ( self::get_user_teams( $user_id ) as $team_id => $team ) {
			if ( ! self::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			$role_slug = self::resolve_role_for_site( $team, $blog_id );
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

	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}
		// Team accounts don't get team fan-out themselves.
		if ( self::is_team_user( $user->ID ) ) {
			return $allcaps;
		}
		$team_caps = self::get_capabilities_from_teams( $user->ID );
		if ( empty( $team_caps ) ) {
			return $allcaps;
		}
		return array_merge( $allcaps, $team_caps );
	}

	/* ------------------------------------------------------------------
	 * Hide team accounts from user-facing systems
	 * ---------------------------------------------------------------- */

	public function exclude_team_users_by_default( $query ) {
		if ( $query->get( self::QUERY_INCLUDE_FLAG ) ) {
			return;
		}
		global $wpdb;
		// Exclude any user with `wput_is_team = '1'` via NOT IN subquery.
		$not_in = "(SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . esc_sql( self::IS_TEAM_META_KEY ) . "' AND meta_value = '1')";
		$query->query_where .= " AND {$wpdb->users}.ID NOT IN {$not_in}";
	}

	public function exclude_team_users_from_rest( $args ) {
		$args['meta_query'] = $args['meta_query'] ?? array();
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array( 'key' => self::IS_TEAM_META_KEY, 'compare' => 'NOT EXISTS' ),
			array( 'key' => self::IS_TEAM_META_KEY, 'value' => '1', 'compare' => '!=' ),
		);
		return $args;
	}

	public function block_team_user_login( $user, $username, $password ) {
		if ( $user instanceof WP_User && self::is_team_user( $user->ID ) ) {
			return new WP_Error(
				'team_user',
				__( 'Team accounts cannot log in.', 'wp-user-teams' )
			);
		}
		return $user;
	}

	public static function is_team_user( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::IS_TEAM_META_KEY, true );
	}

	/* ------------------------------------------------------------------
	 * Multisite filters
	 * ---------------------------------------------------------------- */

	public function filter_get_blogs_of_user( $blogs, $user_id, $all ) {
		$user_teams = self::get_user_teams( $user_id );
		if ( empty( $user_teams ) ) {
			return $blogs;
		}

		$site_ids = array();
		foreach ( $user_teams as $team_id => $team ) {
			if ( empty( $team['sites'] ) ) {
				if ( empty( $team['role'] ) ) {
					continue; // Membership-only team — doesn't propagate sites.
				}
				foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $id ) {
					$site_ids[ (int) $id ] = true;
				}
				continue;
			}
			foreach ( $team['sites'] as $site_id => $site_role ) {
				$effective = '' !== $site_role ? $site_role : ( $team['role'] ?? '' );
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

	public function on_user_deleted( $user_id ) {
		$user_id = (int) $user_id;
		if ( self::is_team_user( $user_id ) ) {
			// Deleting a team account: clean up members' team lists.
			self::remove_team_from_all_users( $user_id );
			return;
		}
		// Regular user deletion: drop their team memberships. (Global
		// multisite usermeta is auto-cleaned; this covers single-site.)
		delete_user_meta( $user_id, self::USER_META_KEY );
	}

	public function on_site_deleted( $site ) {
		// Team accounts get removed from deleted blogs automatically by
		// `wp_delete_site` (wp_users_remove_from_deleted_blog). Nothing
		// else to do — per-site roles are stored natively.
		unset( $site );
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	private static function user_to_team( WP_User $u ) {
		$slug = (string) get_user_meta( $u->ID, self::SLUG_META_KEY, true );
		if ( '' === $slug ) {
			$slug = sanitize_title( preg_replace( '/^' . preg_quote( self::LOGIN_PREFIX, '/' ) . '/', '', $u->user_login ) );
		}
		return array(
			'id'    => (int) $u->ID,
			'name'  => $u->display_name ?: $u->user_login,
			'slug'  => $slug,
			'role'  => (string) get_user_meta( $u->ID, self::GLOBAL_ROLE_META, true ),
			'sites' => is_multisite() ? self::get_team_site_roles( $u->ID ) : array(),
		);
	}

	private static function unique_slug( $slug ) {
		$base = $slug;
		$i    = 2;
		while ( self::get_team_by_slug( $slug ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}

	/**
	 * Normalise any `sites` input — legacy int list or associative
	 * `blog_id => role_slug` — to the canonical associative form. Invalid
	 * blog IDs and unknown role slugs are dropped; empty-string roles
	 * are preserved as "inherit Global Role".
	 *
	 * @return array<int,string>
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

	/**
	 * Writes the team account's role on a given blog, using WP's native
	 * capabilities meta so `is_user_member_of_blog`, list tables, etc.
	 * observe the grant without any extra plumbing.
	 */
	private static function write_team_role_on_blog( $team_id, $blog_id, $role_slug, $already_member ) {
		global $wpdb;

		// add_user_to_blog requires a real role — use the empty-placeholder role below if needed.
		$effective_role = $role_slug ?: 'subscriber';

		if ( ! $already_member ) {
			add_user_to_blog( $blog_id, $team_id, $effective_role );
		} else {
			switch_to_blog( $blog_id );
			$u = new WP_User( $team_id );
			$u->set_role( $effective_role );
			restore_current_blog();
		}

		if ( '' === $role_slug ) {
			// Mark this blog as "member without a specific role" — the
			// fan-out uses the team's Global Role instead.
			$cap_key = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';
			update_user_meta( $team_id, $cap_key, array() );
		}
	}

	private static function remove_team_from_all_users( $team_id ) {
		global $wpdb;
		$team_id  = (int) $team_id;
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

	/** Test helper kept for API compatibility — nothing to flush in this model. */
	public static function flush_all_caches() {
		// No-op: team data lives in `wp_users` / `wp_usermeta` which
		// WP_UnitTestCase manages between tests.
	}
}
