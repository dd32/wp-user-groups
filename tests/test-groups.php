<?php
/**
 * Tests for group CRUD operations.
 */
class Test_Groups extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		WP_User_Groups::flush_all_caches();
	}

	public function test_create_group() {
		$id = WP_User_Groups::create_group( 'Meta Team', 'meta-team', 'editor' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$group = WP_User_Groups::get_group( $id );
		$this->assertNotNull( $group );
		$this->assertSame( 'Meta Team', $group['name'] );
		$this->assertSame( 'meta-team', $group['slug'] );
		$this->assertSame( 'editor', $group['role'] );
	}

	public function test_create_group_auto_slug() {
		$id    = WP_User_Groups::create_group( 'My Cool Group' );
		$group = WP_User_Groups::get_group( $id );

		$this->assertSame( 'my-cool-group', $group['slug'] );
	}

	public function test_create_group_unique_slug() {
		$id1 = WP_User_Groups::create_group( 'Team', 'team' );
		$id2 = WP_User_Groups::create_group( 'Team', 'team' );

		$g1 = WP_User_Groups::get_group( $id1 );
		$g2 = WP_User_Groups::get_group( $id2 );

		$this->assertSame( 'team', $g1['slug'] );
		$this->assertSame( 'team-2', $g2['slug'] );
		$this->assertNotEquals( $id1, $id2 );
	}

	public function test_create_group_missing_name() {
		$result = WP_User_Groups::create_group( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	public function test_create_group_invalid_role_is_cleared() {
		$id    = WP_User_Groups::create_group( 'Test', 'test', 'not_a_real_role' );
		$group = WP_User_Groups::get_group( $id );

		$this->assertSame( '', $group['role'] );
	}

	public function test_update_group() {
		$id = WP_User_Groups::create_group( 'Old Name', 'old', 'subscriber' );

		$result = WP_User_Groups::update_group( $id, array(
			'name' => 'New Name',
			'slug' => 'new-slug',
			'role' => 'editor',
		) );

		$this->assertTrue( $result );

		$group = WP_User_Groups::get_group( $id );
		$this->assertSame( 'New Name', $group['name'] );
		$this->assertSame( 'new-slug', $group['slug'] );
		$this->assertSame( 'editor', $group['role'] );
	}

	public function test_update_group_duplicate_slug_is_auto_incremented() {
		WP_User_Groups::create_group( 'A', 'taken' );
		$id2 = WP_User_Groups::create_group( 'B', 'other' );

		$result = WP_User_Groups::update_group( $id2, array( 'slug' => 'taken' ) );

		$this->assertTrue( $result );
		$group = WP_User_Groups::get_group( $id2 );
		$this->assertSame( 'taken-2', $group['slug'] );
	}

	public function test_update_group_keeps_own_slug() {
		$id = WP_User_Groups::create_group( 'Keeper', 'keeper' );

		// Updating without changing the slug should not append a suffix to itself.
		$result = WP_User_Groups::update_group( $id, array( 'slug' => 'keeper' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'keeper', WP_User_Groups::get_group( $id )['slug'] );
	}

	public function test_update_nonexistent_group() {
		$result = WP_User_Groups::update_group( 99999, array( 'name' => 'X' ) );
		$this->assertWPError( $result );
	}

	public function test_delete_group() {
		$id = WP_User_Groups::create_group( 'Delete Me' );
		$this->assertNotNull( WP_User_Groups::get_group( $id ) );

		$deleted = WP_User_Groups::delete_group( $id );
		$this->assertTrue( $deleted );
		$this->assertNull( WP_User_Groups::get_group( $id ) );
	}

	public function test_delete_nonexistent_group() {
		$this->assertFalse( WP_User_Groups::delete_group( 99999 ) );
	}

	public function test_get_all_groups() {
		WP_User_Groups::create_group( 'Alpha' );
		WP_User_Groups::create_group( 'Beta' );

		$groups = WP_User_Groups::get_all_groups();

		$this->assertCount( 2, $groups );
		$names = wp_list_pluck( $groups, 'name' );
		$this->assertContains( 'Alpha', $names );
		$this->assertContains( 'Beta', $names );
	}

	public function test_get_group_by_slug() {
		$id = WP_User_Groups::create_group( 'Find Me', 'find-me' );

		$group = WP_User_Groups::get_group_by_slug( 'find-me' );
		$this->assertNotNull( $group );
		$this->assertSame( $id, $group['id'] );

		$this->assertNull( WP_User_Groups::get_group_by_slug( 'nope' ) );
	}
}
