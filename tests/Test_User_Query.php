<?php
use dd32\WordPress\UserTeams\Plugin;
use dd32\WordPress\UserTeams\Admin;
/**
 * Coverage for anything that alters the Users list query or its views:
 *
 * - pre_user_query injection that adds team members to the per-site
 *   wp-admin/users.php list even without native capabilities meta.
 * - pre_get_users filter on `?team=N` that narrows the list to a team.
 * - views_users filter that renders the team filter links.
 * - get_role_list filter that appends team-derived roles.
 */
class Test_User_Query extends WP_UnitTestCase {

	/** @var Admin */
	private $admin;

	public function set_up() {
		parent::set_up();
		Plugin::flush_all_caches();
		$this->admin = Admin::instance();
		$_GET        = array();
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * pre_user_query: team members injected into per-site Users list
	 * ---------------------------------------------------------------- */

	public function test_query_includes_team_members_without_native_caps_meta() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'Q', 'q', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		// User has NO capabilities on $blog2; only membership is via the team.
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array( 'blog_id' => $blog2, 'fields' => 'ID' ) );
		$ids   = array_map( 'intval', $query->get_results() );
		$this->assertContains( $uid, $ids, 'team member must appear in site users list' );
	}

	public function test_query_does_not_duplicate_team_members() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'Dedupe', 'dedupe', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// Sprinkle extra usermeta so the LEFT JOIN would expand without DISTINCT.
		update_user_meta( $uid, 'noise_a', 1 );
		update_user_meta( $uid, 'noise_b', 2 );
		update_user_meta( $uid, 'noise_c', 3 );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array( 'blog_id' => $blog2, 'fields' => 'ID', 'count_total' => true ) );
		$ids   = array_map( 'intval', $query->get_results() );

		$this->assertSame( 1, count( array_keys( $ids, $uid, true ) ), 'team member should appear exactly once' );
		// Total users reported should equal the number of distinct users.
		$this->assertSame( count( array_unique( $ids ) ), (int) $query->get_total() );
	}

	public function test_query_without_team_members_is_unchanged() {

		$blog2 = self::factory()->blog->create();

		// Create a team that doesn't apply to blog2.
		$other_blog = self::factory()->blog->create();
		$tid        = Plugin::create_team( 'Other', 'other', '' );
		Plugin::add_team_to_site( $tid, $other_blog, 'editor' );
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array( 'blog_id' => $blog2, 'fields' => 'ID' ) );
		$ids   = array_map( 'intval', $query->get_results() );

		$this->assertNotContains( $uid, $ids );
	}

	public function test_query_unaffected_when_blog_id_missing() {
		// Without blog_id, the capabilities INNER JOIN isn't added — we
		// must not add our OR clause either (it would be a no-op but we
		// should still bail out cleanly).
		$tid = Plugin::create_team( 'NoBlog', 'noblog', 'editor' );
		$uid = self::factory()->user->create();
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array( 'fields' => 'ID' ) );
		$ids   = array_map( 'intval', $query->get_results() );

		$this->assertContains( $uid, $ids );
	}

	/* ------------------------------------------------------------------
	 * pre_get_users: `?team=N` filter narrows to team members
	 * ---------------------------------------------------------------- */

	public function test_team_filter_narrows_to_members() {
		$tid    = Plugin::create_team( 'FilterMe', 'filterme', 'editor' );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $member, $tid );

		$_GET['team'] = $tid;
		set_current_screen( 'users' );

		$query = new WP_User_Query( array( 'fields' => 'ID' ) );
		$ids   = array_map( 'intval', $query->get_results() );

		$this->assertContains( $member, $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function test_team_filter_ignored_outside_users_screen() {
		$tid    = Plugin::create_team( 'QuietFilter', 'quiet-filter', 'editor' );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $member, $tid );

		$_GET['team'] = $tid;
		set_current_screen( 'dashboard' ); // not 'users'

		$query = new WP_User_Query( array( 'fields' => 'ID' ) );
		$ids   = array_map( 'intval', $query->get_results() );

		$this->assertContains( $member, $ids );
		$this->assertContains( $other, $ids, 'filter should only fire on users-list screens' );
	}

	/* ------------------------------------------------------------------
	 * views_users filter: "via TEAM" links
	 * ---------------------------------------------------------------- */

	public function test_views_filter_adds_entry_per_team_with_members() {
		$t_populated = Plugin::create_team( 'Populated', 'populated', 'editor' );
		$t_empty     = Plugin::create_team( 'Empty', 'empty', 'editor' );
		Plugin::add_user_to_team(
			self::factory()->user->create(),
			$t_populated
		);

		set_current_screen( 'users' );

		$views = $this->admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertArrayHasKey( 'user-team-team-' . $t_populated, $views, 'populated team should appear' );
		$this->assertArrayNotHasKey( 'user-team-team-' . $t_empty, $views, 'empty team should be hidden' );
		$this->assertStringContainsString( 'via Populated', $views[ 'user-team-team-' . $t_populated ] );
	}

	public function test_views_filter_hides_teams_that_dont_cover_current_site() {

		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$covers_only_blog3 = Plugin::create_team( 'Blog3Only', 'blog3-only', '' );
		Plugin::add_team_to_site( $covers_only_blog3, $blog3, 'editor' );
		Plugin::add_user_to_team( self::factory()->user->create(), $covers_only_blog3 );

		switch_to_blog( $blog2 );
		set_current_screen( 'users' );
		$views = $this->admin->filter_user_views( array( 'all' => '<a>All</a>' ) );
		restore_current_blog();

		$this->assertArrayNotHasKey( 'user-team-team-' . $covers_only_blog3, $views, 'team scoped to blog3 must not appear on blog2 users list' );
	}

	public function test_views_filter_base_url_strips_other_view_args() {
		$tid = Plugin::create_team( 'UrlTeam', 'url-team', 'editor' );
		Plugin::add_user_to_team( self::factory()->user->create(), $tid );

		set_current_screen( 'users' );
		$_SERVER['REQUEST_URI'] = '/wp-admin/users.php?role=administrator&paged=3&s=foo';

		$views = $this->admin->filter_user_views( array( 'all' => '<a>All</a>' ) );
		$html  = $views[ 'user-team-team-' . $tid ] ?? '';

		$this->assertStringContainsString( 'team=' . $tid, $html );
		$this->assertStringNotContainsString( 'role=administrator', $html, 'role filter must not carry over' );
		$this->assertStringNotContainsString( 'paged=3', $html, 'paging must not carry over' );
		$this->assertStringNotContainsString( 's=foo', $html, 'search must not carry over' );
	}

	/* ------------------------------------------------------------------
	 * Search interactions
	 * ---------------------------------------------------------------- */

	public function test_search_finds_team_member_without_native_caps() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'Searchable', 'searchable', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$alice = self::factory()->user->create( array( 'user_login' => 'alice_wut', 'display_name' => 'Alice W' ) );
		$bob   = self::factory()->user->create( array( 'user_login' => 'bobby', 'display_name' => 'Bob B' ) );
		Plugin::add_user_to_team( $alice, $tid );
		Plugin::add_user_to_team( $bob, $tid );

		$query = new WP_User_Query( array(
			'blog_id' => $blog2,
			'search'  => '*alice*',
			'fields'  => 'ID',
		) );
		$ids = array_map( 'intval', $query->get_results() );

		$this->assertContains( $alice, $ids, 'search for "alice" must surface team-member alice' );
		$this->assertNotContains( $bob, $ids, 'search for "alice" must NOT drag in unrelated team members' );
	}

	public function test_search_no_matches_returns_empty() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'None', 'nomatch', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );
		$uid = self::factory()->user->create( array( 'user_login' => 'bob_smith' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array(
			'blog_id' => $blog2,
			'search'  => '*nomatchplease*',
			'fields'  => 'ID',
		) );
		$ids = array_map( 'intval', $query->get_results() );

		$this->assertNotContains( $uid, $ids );
	}

	/* ------------------------------------------------------------------
	 * Role filter interactions
	 * ---------------------------------------------------------------- */

	public function test_role_filter_includes_team_members_with_matching_team_role() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'RoleMatch', 'role-match', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array(
			'blog_id' => $blog2,
			'role'    => 'editor',
			'fields'  => 'ID',
		) );
		$ids = array_map( 'intval', $query->get_results() );

		$this->assertContains( $uid, $ids, 'team member whose team-derived role is Editor should appear under ?role=editor' );
	}

	public function test_role_filter_excludes_team_members_with_different_role() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'WrongRole', 'wrong-role', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'author' );
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$query = new WP_User_Query( array(
			'blog_id' => $blog2,
			'role'    => 'administrator',
			'fields'  => 'ID',
		) );
		$ids = array_map( 'intval', $query->get_results() );

		$this->assertNotContains( $uid, $ids, '?role=administrator must not leak in team members whose team role is Author' );
	}

	public function test_role_views_expose_role_that_exists_only_via_teams() {

		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'EditorsViaTeam', 'editors-via-team', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );
		Plugin::add_user_to_team( self::factory()->user->create(), $tid );
		Plugin::add_user_to_team( self::factory()->user->create(), $tid );

		switch_to_blog( $blog2 );
		set_current_screen( 'users' );
		$views = $this->admin->filter_user_views( array( 'all' => '<a>All</a>' ) );
		restore_current_blog();

		$this->assertArrayHasKey( 'editor', $views, 'Editor filter should appear even with no native editors' );
		$this->assertStringContainsString( '(2)', $views['editor'], 'count should reflect team-derived editors' );
	}

	/* ------------------------------------------------------------------
	 * get_role_list: team-derived roles surfaced on users.php
	 * ---------------------------------------------------------------- */

	public function test_role_list_appends_team_role_when_user_lacks_it_natively() {
		$tid = Plugin::create_team( 'Bonus', 'bonus', 'editor' );
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$user = new WP_User( $uid );

		// `get_role_list` has passed an array since WP 6.2; plugin requires 6.9+.
		$result = $this->admin->disclose_team_roles_in_users_list( array( 'Subscriber' ), $user );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'Editor', $result[1] );
		$this->assertStringContainsString( 'via Bonus', $result[1] );
	}

	public function test_role_list_unchanged_when_user_already_has_team_role_natively() {
		$tid = Plugin::create_team( 'Same', 'same', 'editor' );
		$uid = self::factory()->user->create( array( 'role' => 'editor' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$user   = new WP_User( $uid );
		$result = $this->admin->disclose_team_roles_in_users_list( array( 'Editor' ), $user );

		$this->assertSame( array( 'Editor' ), $result, 'no redundant "via team" label when user is natively the role' );
	}
}
