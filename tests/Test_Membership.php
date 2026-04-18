<?php
/**
 * Tests for user ↔ team membership.
 */
class Test_Membership extends WP_UnitTestCase {

	private $team_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		WP_User_Teams::flush_all_caches();

		$this->team_id = WP_User_Teams::create_team( 'Test Team', 'test', 'editor' );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_add_user_to_team() {
		$result = WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		$this->assertTrue( $result );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertContains( $this->team_id, $ids );
	}

	public function test_add_user_to_nonexistent_team() {
		$result = WP_User_Teams::add_user_to_team( $this->user_id, 99999 );
		$this->assertFalse( $result );
	}

	public function test_add_user_twice_is_idempotent() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertCount( 1, $ids );
	}

	public function test_remove_user_from_team() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::remove_user_from_team( $this->user_id, $this->team_id );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertNotContains( $this->team_id, $ids );
	}

	public function test_set_user_teams() {
		$t2 = WP_User_Teams::create_team( 'Second', 'second', 'author' );
		$t3 = WP_User_Teams::create_team( 'Third', 'third', 'contributor' );

		WP_User_Teams::set_user_teams( $this->user_id, array( $this->team_id, $t2, $t3 ) );
		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );

		$this->assertCount( 3, $ids );
		$this->assertContains( $this->team_id, $ids );
		$this->assertContains( $t2, $ids );
		$this->assertContains( $t3, $ids );
	}

	public function test_set_user_teams_replaces_previous() {
		$t2 = WP_User_Teams::create_team( 'Other', 'other' );

		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::set_user_teams( $this->user_id, array( $t2 ) );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertNotContains( $this->team_id, $ids );
		$this->assertContains( $t2, $ids );
	}

	public function test_set_user_teams_ignores_invalid_ids() {
		WP_User_Teams::set_user_teams( $this->user_id, array( 99999, $this->team_id ) );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertCount( 1, $ids );
		$this->assertContains( $this->team_id, $ids );
	}

	public function test_get_user_teams_returns_full_records() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );

		$teams = WP_User_Teams::get_user_teams( $this->user_id );
		$this->assertArrayHasKey( $this->team_id, $teams );
		$this->assertSame( 'Test Team', $teams[ $this->team_id ]['name'] );
	}

	public function test_get_team_members() {
		$u2 = self::factory()->user->create();
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::add_user_to_team( $u2, $this->team_id );

		$members = WP_User_Teams::get_team_members( $this->team_id );
		$this->assertCount( 2, $members );
		$this->assertContains( $this->user_id, $members );
		$this->assertContains( $u2, $members );
	}

	public function test_count_members_per_team() {
		$t2 = WP_User_Teams::create_team( 'Other', 'other', 'author' );
		$u2 = self::factory()->user->create();

		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::add_user_to_team( $u2, $this->team_id );
		WP_User_Teams::add_user_to_team( $this->user_id, $t2 );

		$counts = WP_User_Teams::count_members_per_team();
		$this->assertSame( 2, $counts[ $this->team_id ] );
		$this->assertSame( 1, $counts[ $t2 ] );
	}

	public function test_deleting_team_removes_memberships() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::delete_team( $this->team_id );

		$ids = WP_User_Teams::get_user_team_ids( $this->user_id );
		$this->assertNotContains( $this->team_id, $ids );
	}

	public function test_deleting_user_clears_memberships() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );

		wp_delete_user( $this->user_id );
		WP_User_Teams::flush_all_caches();

		$members = WP_User_Teams::get_team_members( $this->team_id );
		$this->assertNotContains( $this->user_id, $members );
	}
}
