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

	/**
	 * Grants (or re-grants) a team a role on a single blog. An empty role
	 * marks the blog as covered by the team's Global Role — the team is
	 * a member but has no specific cap set here.
	 */
	public static function add_team_to_site( $team_id, $blog_id, $role_slug = '' ) {
		$team_id = (int) $team_id;
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 || ! self::get_team( $team_id ) ) {
			return false;
		}

		$role_slug = is_string( $role_slug ) ? sanitize_key( $role_slug ) : '';
		if ( '' !== $role_slug && ! wp_roles()->is_role( $role_slug ) ) {
			$role_slug = '';
		}

		// add_user_to_blog / set_role require a real role — the empty-role
		// placeholder is rewritten to an empty caps array immediately after.
		$effective_role = $role_slug ?: 'subscriber';
		$already_member = array_key_exists( $blog_id, self::get_team_site_roles( $team_id ) );

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
			global $wpdb;
			$cap_key = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';
			update_user_meta( $team_id, $cap_key, array() );
		}

		return true;
	}

	/**
	 * Revokes a team's grant on a single blog.
	 */
	public static function remove_team_from_site( $team_id, $blog_id ) {
		$team_id = (int) $team_id;
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 || ! self::get_team( $team_id ) ) {
			return false;
		}

		remove_user_from_blog( $team_id, $blog_id );
		return true;
	}

	/**
	 * A team applies to a site when it has a Global Role (network-wide
	 * coverage) or an explicit per-site grant for that blog.
	 *
	 * Filterable via `user_teams_team_applies_to_site` for callers that want to
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
		return (bool) apply_filters( 'user_teams_team_applies_to_site', $applies, (int) $team_id, $blog_id, $team );
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

}
