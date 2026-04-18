<?php
/**
 * Tests for team CRUD operations.
 */
class Test_Teams extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		WP_User_Teams::flush_all_caches();
	}

	public function test_create_team() {
		$id = WP_User_Teams::create_team( 'Meta Team', 'meta-team', 'editor' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$team = WP_User_Teams::get_team( $id );
		$this->assertNotNull( $team );
		$this->assertSame( 'Meta Team', $team['name'] );
		$this->assertSame( 'meta-team', $team['slug'] );
		$this->assertSame( 'editor', $team['role'] );
	}

	public function test_create_team_auto_slug() {
		$id   = WP_User_Teams::create_team( 'My Cool Team' );
		$team = WP_User_Teams::get_team( $id );

		$this->assertSame( 'my-cool-team', $team['slug'] );
	}

	public function test_create_team_unique_slug() {
		$id1 = WP_User_Teams::create_team( 'Team', 'team' );
		$id2 = WP_User_Teams::create_team( 'Team', 'team' );

		$t1 = WP_User_Teams::get_team( $id1 );
		$t2 = WP_User_Teams::get_team( $id2 );

		$this->assertSame( 'team', $t1['slug'] );
		$this->assertSame( 'team-2', $t2['slug'] );
		$this->assertNotEquals( $id1, $id2 );
	}

	public function test_create_team_missing_name() {
		$result = WP_User_Teams::create_team( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	public function test_create_team_invalid_role_is_cleared() {
		$id   = WP_User_Teams::create_team( 'Test', 'test', 'not_a_real_role' );
		$team = WP_User_Teams::get_team( $id );

		$this->assertSame( '', $team['role'] );
	}

	public function test_update_team() {
		$id = WP_User_Teams::create_team( 'Old Name', 'old', 'subscriber' );

		$result = WP_User_Teams::update_team( $id, array(
			'name' => 'New Name',
			'slug' => 'new-slug',
			'role' => 'editor',
		) );

		$this->assertTrue( $result );

		$team = WP_User_Teams::get_team( $id );
		$this->assertSame( 'New Name', $team['name'] );
		$this->assertSame( 'new-slug', $team['slug'] );
		$this->assertSame( 'editor', $team['role'] );
	}

	public function test_update_team_duplicate_slug() {
		WP_User_Teams::create_team( 'A', 'taken' );
		$id2 = WP_User_Teams::create_team( 'B', 'other' );

		$result = WP_User_Teams::update_team( $id2, array( 'slug' => 'taken' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_slug', $result->get_error_code() );
	}

	public function test_update_nonexistent_team() {
		$result = WP_User_Teams::update_team( 99999, array( 'name' => 'X' ) );
		$this->assertWPError( $result );
	}

	public function test_delete_team() {
		$id = WP_User_Teams::create_team( 'Delete Me' );
		$this->assertNotNull( WP_User_Teams::get_team( $id ) );

		$deleted = WP_User_Teams::delete_team( $id );
		$this->assertTrue( $deleted );
		$this->assertNull( WP_User_Teams::get_team( $id ) );
	}

	public function test_delete_nonexistent_team() {
		$this->assertFalse( WP_User_Teams::delete_team( 99999 ) );
	}

	public function test_get_all_teams() {
		WP_User_Teams::create_team( 'Alpha' );
		WP_User_Teams::create_team( 'Beta' );

		$teams = WP_User_Teams::get_all_teams();

		$this->assertCount( 2, $teams );
		$names = wp_list_pluck( $teams, 'name' );
		$this->assertContains( 'Alpha', $names );
		$this->assertContains( 'Beta', $names );
	}

	public function test_get_team_by_slug() {
		$id = WP_User_Teams::create_team( 'Find Me', 'find-me' );

		$team = WP_User_Teams::get_team_by_slug( 'find-me' );
		$this->assertNotNull( $team );
		$this->assertSame( $id, $team['id'] );

		$this->assertNull( WP_User_Teams::get_team_by_slug( 'nope' ) );
	}
}
