<?php
use dd32\WordPress\UserTeams\Plugin;
use dd32\WordPress\UserTeams\Admin;
/**
 * Tests for team CRUD operations.
 */
class Test_Teams extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Plugin::flush_all_caches();
	}

	public function test_create_team() {
		$id = Plugin::create_team( 'Meta Team', 'meta-team', 'editor' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$team = Plugin::get_team( $id );
		$this->assertNotNull( $team );
		$this->assertSame( 'Meta Team', $team['name'] );
		$this->assertSame( 'meta-team', $team['slug'] );
		$this->assertSame( 'editor', $team['role'] );
	}

	public function test_create_team_auto_slug() {
		$id   = Plugin::create_team( 'My Cool Team' );
		$team = Plugin::get_team( $id );

		$this->assertSame( 'my-cool-team', $team['slug'] );
	}

	public function test_create_team_unique_slug() {
		$id1 = Plugin::create_team( 'Team', 'team' );
		$id2 = Plugin::create_team( 'Team', 'team' );

		$t1 = Plugin::get_team( $id1 );
		$t2 = Plugin::get_team( $id2 );

		$this->assertSame( 'team', $t1['slug'] );
		$this->assertSame( 'team-2', $t2['slug'] );
		$this->assertNotEquals( $id1, $id2 );
	}

	public function test_create_team_missing_name() {
		$result = Plugin::create_team( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	public function test_create_team_invalid_role_is_cleared() {
		$id   = Plugin::create_team( 'Test', 'test', 'not_a_real_role' );
		$team = Plugin::get_team( $id );

		$this->assertSame( '', $team['role'] );
	}

	public function test_update_team() {
		$id = Plugin::create_team( 'Old Name', 'old', 'subscriber' );

		$result = Plugin::update_team( $id, array(
			'name' => 'New Name',
			'slug' => 'new-slug',
			'role' => 'editor',
		) );

		$this->assertTrue( $result );

		$team = Plugin::get_team( $id );
		$this->assertSame( 'New Name', $team['name'] );
		$this->assertSame( 'new-slug', $team['slug'] );
		$this->assertSame( 'editor', $team['role'] );
	}

	public function test_update_team_duplicate_slug() {
		Plugin::create_team( 'A', 'taken' );
		$id2 = Plugin::create_team( 'B', 'other' );

		$result = Plugin::update_team( $id2, array( 'slug' => 'taken' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_slug', $result->get_error_code() );
	}

	public function test_update_nonexistent_team() {
		$result = Plugin::update_team( 99999, array( 'name' => 'X' ) );
		$this->assertWPError( $result );
	}

	public function test_delete_team() {
		$id = Plugin::create_team( 'Delete Me' );
		$this->assertNotNull( Plugin::get_team( $id ) );

		$deleted = Plugin::delete_team( $id );
		$this->assertTrue( $deleted );
		$this->assertNull( Plugin::get_team( $id ) );
	}

	public function test_delete_nonexistent_team() {
		$this->assertFalse( Plugin::delete_team( 99999 ) );
	}

	public function test_get_all_teams() {
		Plugin::create_team( 'Alpha' );
		Plugin::create_team( 'Beta' );

		$teams = Plugin::get_all_teams();

		$this->assertCount( 2, $teams );
		$names = wp_list_pluck( $teams, 'name' );
		$this->assertContains( 'Alpha', $names );
		$this->assertContains( 'Beta', $names );
	}

	public function test_get_team_by_slug() {
		$id = Plugin::create_team( 'Find Me', 'find-me' );

		$team = Plugin::get_team_by_slug( 'find-me' );
		$this->assertNotNull( $team );
		$this->assertSame( $id, $team['id'] );

		$this->assertNull( Plugin::get_team_by_slug( 'nope' ) );
	}
}
