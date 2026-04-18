<?php
/**
 * Tests for multisite-specific behaviour.
 *
 * Run with:  npm run test:multisite
 */
class Test_Multisite extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		WP_User_Teams::flush_all_caches();
	}

	/* ----- Site scoping ----- */

	public function test_team_with_no_sites_applies_everywhere() {
		$tid = WP_User_Teams::create_team( 'Global', 'global', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array() ); // empty = all sites

		$blog2 = self::factory()->blog->create();

		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, $blog2 ) );
	}

	public function test_team_with_selected_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → team applies only where per-site role grants exist.
		$tid = WP_User_Teams::create_team( 'Selective', 'selective', '' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'editor' ) );

		$this->assertFalse( WP_User_Teams::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, $blog2 ) );
		$this->assertFalse( WP_User_Teams::team_applies_to_site( $tid, $blog3 ) );
	}

	public function test_global_role_applies_everywhere_even_with_per_site_overrides() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// Global Role = editor → applies to every site including unlisted ones.
		$tid = WP_User_Teams::create_team( 'Wide', 'wide', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'author' ) );

		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, $blog2 ) );
		$this->assertTrue( WP_User_Teams::team_applies_to_site( $tid, $blog3 ) );
	}

	public function test_per_site_role_override_wins_over_global() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Override', 'override', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'author' ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$caps_main  = WP_User_Teams::get_capabilities_from_teams( $uid, get_main_site_id() );
		$caps_blog2 = WP_User_Teams::get_capabilities_from_teams( $uid, $blog2 );

		// Main: Global editor caps.
		$this->assertArrayHasKey( 'edit_others_posts', $caps_main );
		// blog2: author caps but NOT the editor-only edit_others_posts.
		$this->assertArrayHasKey( 'publish_posts', $caps_blog2 );
		$this->assertArrayNotHasKey( 'edit_others_posts', $caps_blog2 );
	}

	public function test_per_site_inherit_uses_global_role() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Inherit', 'inherit', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => '' ) ); // explicit grant, empty override

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$caps_blog2 = WP_User_Teams::get_capabilities_from_teams( $uid, $blog2 );
		$this->assertArrayHasKey( 'edit_others_posts', $caps_blog2 );
	}

	public function test_set_team_sites_replaces_previous() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Swap', 'swap', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 ) );
		WP_User_Teams::set_team_sites( $tid, array( $blog3 ) );

		$sites = WP_User_Teams::get_team_sites( $tid );
		$this->assertContains( $blog3, $sites );
		$this->assertNotContains( $blog2, $sites );
	}

	public function test_set_team_sites_filters_empty_values() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Clean', 'clean', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( 0, '', $blog2, null, '0' ) );

		$sites = WP_User_Teams::get_team_sites( $tid );
		$this->assertSame( array( $blog2 ), $sites );
	}

	/* ----- Capabilities scoped by site ----- */

	public function test_caps_granted_on_applicable_site_only() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → only sites listed in per-site grants get caps.
		$tid = WP_User_Teams::create_team( 'Team', 'team', '' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'editor' ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$caps_main  = WP_User_Teams::get_capabilities_from_teams( $uid, get_main_site_id() );
		$caps_blog2 = WP_User_Teams::get_capabilities_from_teams( $uid, $blog2 );
		$caps_blog3 = WP_User_Teams::get_capabilities_from_teams( $uid, $blog3 );

		$this->assertEmpty( $caps_main );
		$this->assertArrayHasKey( 'edit_others_posts', $caps_blog2 );
		$this->assertEmpty( $caps_blog3 );
	}

	/* ----- is_user_member_of_blog (faked via get_user_metadata) ----- */

	public function test_is_member_of_blog_via_team() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Team', 'team-blog', '' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'editor' ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$this->assertTrue( is_user_member_of_blog( $uid, $blog2 ) );
	}

	public function test_not_member_of_non_scoped_site() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → non-granted sites are not members.
		$tid = WP_User_Teams::create_team( 'Team', 'team-scope', '' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 => 'editor' ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$this->assertFalse( is_user_member_of_blog( $uid, $blog3 ) );
	}

	public function test_member_via_team_does_not_receive_extra_caps_from_empty_meta() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Team', 'team-caps', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		// The faked capabilities meta must unwrap to an empty array — it
		// satisfies is_user_member_of_blog() but must not grant anything.
		$caps_meta = get_user_meta( $uid, $GLOBALS['wpdb']->base_prefix . $blog2 . '_capabilities', true );
		$this->assertSame( array(), $caps_meta );
	}

	public function test_real_capabilities_meta_is_not_overridden() {
		$blog2 = self::factory()->blog->create();

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $blog2, $uid, 'editor' );

		$caps_meta = get_user_meta( $uid, $GLOBALS['wpdb']->base_prefix . $blog2 . '_capabilities', true );
		$this->assertArrayHasKey( 'editor', $caps_meta );
	}

	public function test_team_without_role_does_not_fake_membership() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'NoRole', 'norole', '' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$this->assertFalse( is_user_member_of_blog( $uid, $blog2 ) );
	}

	/* ----- get_blogs_of_user filter ----- */

	public function test_blogs_of_user_includes_team_sites() {
		$blog2 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Team', 'team-blogs', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2 ) );

		$uid = self::factory()->user->create();
		WP_User_Teams::add_user_to_team( $uid, $tid );

		$blogs = get_blogs_of_user( $uid );
		$this->assertArrayHasKey( $blog2, $blogs );
	}

	/* ----- Site deletion ----- */

	public function test_deleting_site_removes_from_team_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$tid = WP_User_Teams::create_team( 'Multi', 'multi', 'editor' );
		WP_User_Teams::set_team_sites( $tid, array( $blog2, $blog3 ) );

		wp_delete_site( $blog2 );
		WP_User_Teams::flush_all_caches();

		$sites = WP_User_Teams::get_team_sites( $tid );
		$this->assertNotContains( $blog2, $sites );
		$this->assertContains( $blog3, $sites );
	}
}
