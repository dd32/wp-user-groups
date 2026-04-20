<?php
/**
 * Multisite-specific filters. `get_blogs_of_user` is extended so
 * members see the sites their teams cover; user/site deletion hooks
 * keep team membership lists and per-blog grants consistent.
 */

namespace dd32\WordPress\UserTeams\Traits;

trait BlogsFilter {

	public function filter_get_blogs_of_user( $blogs, $user_id, $all ) {
		// Skip for team accounts — they don't belong to other teams, and
		// `get_team_site_roles()` calls `get_blogs_of_user()` on the team
		// account itself, which would recurse back into this filter.
		if ( self::is_team_user( $user_id ) ) {
			return $blogs;
		}
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
}
