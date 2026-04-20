<?php
/**
 * Team → Site scoping. Per-site grants are stored natively on the team
 * account's `wp_{blog_id}_capabilities` usermeta, so `is_user_member_of_blog()`
 * and `WP_Users_List_Table` work out of the box for team accounts.
 */

namespace dd32\WordPress\UserTeams\Traits;

use WP_User;

trait SiteScope {

	/** @return int[] */
	public static function get_team_sites( $team_id ) {
		return array_keys( self::get_team_site_roles( $team_id ) );
	}

	/** @return array<int,string> blog_id => role slug ('' = member without a specific role) */
	public static function get_team_site_roles( $team_id ) {
		$team_id = (int) $team_id;
		// Use the marker-meta check directly — calling `get_team()` here
		// would recurse back through `user_to_team()` → `get_team_site_roles()`.
		if ( $team_id <= 0 || ! self::is_team_user( $team_id ) ) {
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
			$active_roles    = array_keys( array_filter( $caps ) );
			$out[ $blog_id ] = $active_roles ? $active_roles[0] : '';
		}
		return $out;
	}

	public static function set_team_sites( $team_id, array $sites ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return false;
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

	/**
	 * A team applies to a site when it has a Global Role (network-wide
	 * coverage) or an explicit per-site grant for that blog.
	 *
	 * Filterable via `wput_team_applies_to_site` for callers that want to
	 * gate coverage (e.g. pause a team during a freeze, or restrict to
	 * sites matching a pattern).
	 */
	public static function team_applies_to_site( $team_id, $blog_id = null ) {
		$team = self::get_team( $team_id );
		if ( ! $team ) {
			return false;
		}
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		$applies = ! empty( $team['role'] ) || array_key_exists( $blog_id, $team['sites'] );

		/**
		 * Filters whether a team applies (i.e. can grant anything) to a
		 * given blog.
		 *
		 * @param bool  $applies  Whether the team covers the blog.
		 * @param int   $team_id  Team (user) ID.
		 * @param int   $blog_id  Blog ID being checked.
		 * @param array $team     Full team record, including `role` and `sites`.
		 */
		return (bool) apply_filters( 'wput_team_applies_to_site', $applies, (int) $team_id, $blog_id, $team );
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
}
