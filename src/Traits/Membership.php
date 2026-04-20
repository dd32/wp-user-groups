<?php
/**
 * User ↔ Team membership. Memberships are stored on the member user's
 * own meta (`wp_user_teams` → array of team user IDs); team roles
 * fan out at runtime via `user_has_cap` rather than being written into
 * the member's native capabilities.
 */

namespace dd32\WordPress\UserTeams\Traits;

trait Membership {

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
}
