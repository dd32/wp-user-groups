<?php
/**
 * Capability fan-out. A team's role isn't written into the member's
 * capabilities meta — it's grafted on at runtime via `user_has_cap`,
 * so removing someone from a team drops their access immediately.
 */

namespace dd32\WordPress\UserTeams\Traits;

use WP_User;

trait Capabilities {

	/**
	 * Computes the map of capabilities a user receives from their teams
	 * on a given blog. Iterates every team the user belongs to, resolves
	 * the role that applies on `$blog_id`, and unions the role's
	 * capabilities into the result.
	 *
	 * Filterable via `wput_team_caps_for_user` — callers can add, strip,
	 * or gate capabilities without replacing the whole fan-out.
	 *
	 * @param int      $user_id  User whose team-derived caps to compute.
	 * @param int|null $blog_id  Blog to resolve against; defaults to current.
	 * @return array<string,bool> Capability → granted map.
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

		/**
		 * Filters the team-derived capability map for a user on a blog.
		 *
		 * @param array<string,bool> $caps     Cap → granted map assembled from the user's teams.
		 * @param int                $user_id  User ID the caps apply to.
		 * @param int                $blog_id  Blog ID the caps were resolved for.
		 */
		return apply_filters( 'wput_team_caps_for_user', $caps, $user_id, $blog_id );
	}

	/**
	 * `user_has_cap` filter — grafts team-derived capabilities onto the
	 * user's own cap map so role checks pass for team members on sites
	 * the team covers.
	 *
	 * @param array<string,bool> $allcaps  User's own caps, already filtered.
	 * @param string[]           $caps     Required primitive caps for the check.
	 * @param array              $args     `$args[0]` is the requested cap.
	 * @param WP_User            $user     The user being checked.
	 * @return array<string,bool>
	 */
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
}
