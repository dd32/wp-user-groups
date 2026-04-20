<?php
/**
 * Capability fan-out. A team's role isn't written into the member's
 * capabilities meta — it's grafted on at runtime via `user_has_cap`,
 * so removing someone from a team drops their access immediately.
 */

namespace dd32\WordPress\UserTeams\Traits;

use WP_User;

trait Capabilities {

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
}
