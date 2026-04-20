<?php
/**
 * Hide team accounts from user-facing systems — generic user queries,
 * REST, login, password reset — while still making them appear as
 * blog members for `is_user_member_of_blog()` via faked caps meta.
 */

namespace dd32\WordPress\UserTeams\Traits;

use WP_Error;
use WP_User;

trait Hiding {

	public function exclude_team_users_by_default( $query ) {
		// Skip while WordPress is setting itself up — the test framework's
		// install.php and the first-run installer both run user queries
		// before `wp_usermeta` is populated, and our NOT IN subquery makes
		// multisite's site-lookup fail mid-install.
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return;
		}
		if ( $query->get( self::QUERY_INCLUDE_FLAG ) ) {
			return;
		}
		global $wpdb;
		$not_in = "(SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . esc_sql( self::IS_TEAM_META_KEY ) . "' AND meta_value = '1')";
		$query->query_where .= " AND {$wpdb->users}.ID NOT IN {$not_in}";
	}

	public function exclude_team_users_from_rest( $args ) {
		$args['meta_query']   = $args['meta_query'] ?? array();
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
				__( 'Team accounts cannot log in.', 'user-teams' )
			);
		}
		return $user;
	}

	/**
	 * Blocks password-reset attempts against team accounts — they have
	 * no real user behind them, so emailing a reset link would either
	 * bounce (`@teams.internal`) or, worse, hand over access via whatever
	 * mail route is configured.
	 */
	public function block_team_user_password_reset( $allow, $user_id ) {
		if ( self::is_team_user( $user_id ) ) {
			return false;
		}
		return $allow;
	}

	public static function is_team_user( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::IS_TEAM_META_KEY, true );
	}

	/**
	 * Makes `get_user_meta( $uid, 'wp_{blog}_capabilities', true )` return
	 * an empty array for users whose team covers that blog, so
	 * `is_user_member_of_blog()` recognises them. Real caps meta, if any,
	 * is left untouched.
	 *
	 * @param mixed  $value    Value being filtered.
	 * @param int    $user_id  Target user ID.
	 * @param string $meta_key Meta key being read.
	 * @param bool   $single   Whether a single value was requested.
	 */
	public function fake_member_blog_capabilities( $value, $user_id, $meta_key, $single ) {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return $value;
		}
		if ( null !== $value ) {
			return $value; // Another filter already provided one.
		}
		if ( ! is_string( $meta_key ) || 0 === preg_match( '/^[a-z0-9_]*_(\d+)_capabilities$/', $meta_key, $m ) ) {
			return $value;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || self::is_team_user( $user_id ) ) {
			return $value;
		}
		$blog_id = (int) $m[1];
		$covered = false;
		foreach ( self::get_user_teams( $user_id ) as $team_id => $team ) {
			if ( ! self::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			// Only fake membership when the team actually grants a role
			// here. Membership-only teams (no role, no effect) must stay
			// non-members so `is_user_member_of_blog()` returns false.
			if ( '' === self::resolve_role_for_site( $team, $blog_id ) ) {
				continue;
			}
			$covered = true;
			break;
		}
		if ( ! $covered ) {
			return $value;
		}
		// Return the raw meta shape WP expects: a single-entry array
		// whose element is the caps array. For `get_user_meta(..., true)`,
		// core unwraps to element 0.
		return array( array() );
	}
}
