<?php
use dd32\WordPress\UserTeams\Plugin;
use dd32\WordPress\UserTeams\Admin;
/**
 * Tests for multisite-specific behaviour.
 *
 * Run with:  npm run test:multisite
 */
class Test_Multisite extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Plugin::flush_all_caches();
	}

	/* ----- Site scoping ----- */

	public function test_team_with_no_sites_applies_everywhere() {
		// No per-site grants + a Global Role = the team covers every site.
		$tid = Plugin::create_team( 'Global', 'global', 'editor' );

		$blog2 = self::factory()->blog->create();

		$this->assertTrue( Plugin::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( Plugin::team_applies_to_site( $tid, $blog2 ) );
	}

	public function test_team_with_selected_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → team applies only where per-site role grants exist.
		$tid = Plugin::create_team( 'Selective', 'selective', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$this->assertFalse( Plugin::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( Plugin::team_applies_to_site( $tid, $blog2 ) );
		$this->assertFalse( Plugin::team_applies_to_site( $tid, $blog3 ) );
	}

	public function test_global_role_applies_everywhere_even_with_per_site_overrides() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// Global Role = editor → applies to every site including unlisted ones.
		$tid = Plugin::create_team( 'Wide', 'wide', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2, 'author' );

		$this->assertTrue( Plugin::team_applies_to_site( $tid, get_main_site_id() ) );
		$this->assertTrue( Plugin::team_applies_to_site( $tid, $blog2 ) );
		$this->assertTrue( Plugin::team_applies_to_site( $tid, $blog3 ) );
	}

	public function test_per_site_role_override_wins_over_global() {
		$blog2 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Override', 'override', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2, 'author' );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$caps_main  = Plugin::get_capabilities_from_teams( $uid, get_main_site_id() );
		$caps_blog2 = Plugin::get_capabilities_from_teams( $uid, $blog2 );

		// Main: Global editor caps.
		$this->assertArrayHasKey( 'edit_others_posts', $caps_main );
		// blog2: author caps but NOT the editor-only edit_others_posts.
		$this->assertArrayHasKey( 'publish_posts', $caps_blog2 );
		$this->assertArrayNotHasKey( 'edit_others_posts', $caps_blog2 );
	}

	public function test_per_site_inherit_uses_global_role() {
		$blog2 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Inherit', 'inherit', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2, '' ); // explicit grant, empty override

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$caps_blog2 = Plugin::get_capabilities_from_teams( $uid, $blog2 );
		$this->assertArrayHasKey( 'edit_others_posts', $caps_blog2 );
	}

	public function test_remove_team_from_site_drops_grant() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Swap', 'swap', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2 );
		Plugin::add_team_to_site( $tid, $blog3 );
		Plugin::remove_team_from_site( $tid, $blog2 );

		$sites = Plugin::get_team_sites( $tid );
		$this->assertContains( $blog3, $sites );
		$this->assertNotContains( $blog2, $sites );
	}

	public function test_add_team_to_site_rejects_invalid_blog_id() {
		$blog2 = self::factory()->blog->create();
		$tid   = Plugin::create_team( 'Clean', 'clean', 'editor' );

		$this->assertFalse( Plugin::add_team_to_site( $tid, 0 ) );
		$this->assertFalse( Plugin::add_team_to_site( $tid, '' ) );
		$this->assertFalse( Plugin::add_team_to_site( $tid, null ) );
		$this->assertFalse( Plugin::add_team_to_site( $tid, '0' ) );
		$this->assertTrue(  Plugin::add_team_to_site( $tid, $blog2 ) );
		$this->assertSame( array( $blog2 ), Plugin::get_team_sites( $tid ) );
	}

	/* ----- Capabilities scoped by site ----- */

	public function test_caps_granted_on_applicable_site_only() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → only sites listed in per-site grants get caps.
		$tid = Plugin::create_team( 'Team', 'team', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$caps_main  = Plugin::get_capabilities_from_teams( $uid, get_main_site_id() );
		$caps_blog2 = Plugin::get_capabilities_from_teams( $uid, $blog2 );
		$caps_blog3 = Plugin::get_capabilities_from_teams( $uid, $blog3 );

		$this->assertEmpty( $caps_main );
		$this->assertArrayHasKey( 'edit_others_posts', $caps_blog2 );
		$this->assertEmpty( $caps_blog3 );
	}

	/* ----- is_user_member_of_blog (faked via get_user_metadata) ----- */

	public function test_is_member_of_blog_via_team() {
		$blog2 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Team', 'team-blog', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$this->assertTrue( is_user_member_of_blog( $uid, $blog2 ) );
	}

	public function test_not_member_of_non_scoped_site() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		// No Global Role → non-granted sites are not members.
		$tid = Plugin::create_team( 'Team', 'team-scope', '' );
		Plugin::add_team_to_site( $tid, $blog2, 'editor' );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$this->assertFalse( is_user_member_of_blog( $uid, $blog3 ) );
	}

	public function test_member_via_team_does_not_receive_extra_caps_from_empty_meta() {
		$blog2 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Team', 'team-caps', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2 );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

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

		$tid = Plugin::create_team( 'NoRole', 'norole', '' );
		Plugin::add_team_to_site( $tid, $blog2 );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::add_user_to_team( $uid, $tid );

		$this->assertFalse( is_user_member_of_blog( $uid, $blog2 ) );
	}

	/* ----- get_blogs_of_user filter ----- */

	public function test_blogs_of_user_includes_team_sites() {
		$blog2 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Team', 'team-blogs', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2 );

		$uid = self::factory()->user->create();
		Plugin::add_user_to_team( $uid, $tid );

		$blogs = get_blogs_of_user( $uid );
		$this->assertArrayHasKey( $blog2, $blogs );
	}

	/* ----- Site deletion ----- */

	public function test_deleting_site_removes_from_team_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$tid = Plugin::create_team( 'Multi', 'multi', 'editor' );
		Plugin::add_team_to_site( $tid, $blog2 );
		Plugin::add_team_to_site( $tid, $blog3 );

		wp_delete_site( $blog2 );
		Plugin::flush_all_caches();

		$sites = Plugin::get_team_sites( $tid );
		$this->assertNotContains( $blog2, $sites );
		$this->assertContains( $blog3, $sites );
	}
}
