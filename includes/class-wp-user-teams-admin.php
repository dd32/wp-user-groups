<?php
/**
 * Admin UI for User Teams.
 *
 * - Teams management page (Users → Teams on single site; Network Admin → Users → Teams on multisite).
 * - User-edit profile section with team membership checkboxes.
 * - "Teams" column on the Users list table.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Teams_Admin {

	const PAGE_SLUG    = 'user-teams';
	const NONCE_ACTION = 'wp_user_teams';
	const USER_NONCE   = 'wp_user_teams_user';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Teams are a network-wide concept — management lives in
		// Network Admin → Users → Teams.
		add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );

		add_action( 'admin_post_wp_user_teams_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wp_user_teams_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_wp_user_teams_add_site', array( $this, 'handle_add_site' ) );
		add_action( 'admin_post_wp_user_teams_remove_site', array( $this, 'handle_remove_site' ) );
		add_action( 'admin_post_wp_user_teams_attach_site', array( $this, 'handle_attach_team_to_site' ) );

		add_action( 'show_user_profile', array( $this, 'render_user_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_field' ) );

		// Per-site user-new.php: "Add a Team to This Site" is its own
		// form — nesting it inside user-new.php's forms would hijack
		// the outer submit — so it's rendered via `admin_notices`
		// which fires before the user forms open.
		add_action( 'admin_notices', array( $this, 'render_user_new_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_add_team_to_site_section' ) );
		add_action( 'admin_notices', array( $this, 'render_users_screen_notice' ) );

		// "Teams" column appears on Network Admin → Users. Per-site
		// users.php keeps only the "Role (via Team)" disclosure via
		// `get_role_list` — no redundant column there.
		add_filter( 'manage_users-network_columns', array( $this, 'add_users_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_users_column' ), 10, 3 );

		// Disclose team-derived roles alongside the user's own role(s)
		// in wp-admin/users.php. Filter exists since WP 6.2.
		add_filter( 'get_role_list', array( $this, 'disclose_team_roles_in_users_list' ), 10, 2 );

		// Team filter on the Users list tables.
		add_filter( 'views_users', array( $this, 'filter_user_views' ) );
		add_filter( 'views_users-network', array( $this, 'filter_user_views' ) );
		add_action( 'pre_get_users', array( $this, 'apply_team_filter_to_query' ) );

		// Per-site Users screen: include team members in the list even
		// when they lack native caps meta for the current blog.
		add_action( 'pre_user_query', array( $this, 'include_team_members_in_user_query' ) );

		// On Users list screens, opt team accounts back into the user
		// query (they're globally excluded by WP_User_Teams). Team
		// accounts have native capabilities meta for the sites they
		// cover, so WP_Users_List_Table renders them as real rows.
		add_action( 'pre_get_users', array( $this, 'include_team_users_on_users_screens' ), 5 );

		// Replace the default row actions on team-account rows with
		// team-specific ones (Edit team / Remove from site).
		add_filter( 'user_row_actions', array( $this, 'filter_user_row_actions' ), 10, 2 );

		// Style team account rows distinctly (background, "Team" badge
		// before the login) on both the per-site and network users lists.
		add_action( 'admin_head-users.php', array( $this, 'print_team_row_styles' ) );
		add_action( 'admin_head-users-network.php', array( $this, 'print_team_row_styles' ) );
	}

	public function include_team_users_on_users_screens( $query ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( 'users' !== $screen->base && 'users-network' !== $screen->base ) {
			return;
		}
		$query->set( WP_User_Teams::QUERY_INCLUDE_FLAG, true );
	}

	public function filter_user_row_actions( $actions, $user ) {
		if ( ! $user instanceof WP_User || ! WP_User_Teams::is_team_user( $user->ID ) ) {
			return $actions;
		}
		// Teams are a network-wide concept managed from Network Admin →
		// Users → Teams. Only super admins see actions on team rows;
		// regular site admins get an empty action list (they still see
		// the row, just not the links).
		if ( ! current_user_can( self::required_cap() ) ) {
			return array();
		}
		$edit_url   = $this->page_url( array( 'action' => 'edit', 'team_id' => (int) $user->ID ) );
		$remove_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wp_user_teams_remove_site',
					'team_id' => (int) $user->ID,
					'blog_id' => (int) get_current_blog_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
		return array(
			'wput-edit-team'        => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit team', 'wp-user-teams' ) . '</a>',
			'wput-remove-from-site' => '<a href="' . esc_url( $remove_url ) . '" class="submitdelete" onclick="return confirm(\'' . esc_js( __( 'Remove this team from the site? Members lose the team-granted role here.', 'wp-user-teams' ) ) . '\');">' . esc_html__( 'Remove from site', 'wp-user-teams' ) . '</a>',
		);
	}

	public function print_team_row_styles() {
		$team_names = array();
		foreach ( WP_User_Teams::get_all_teams() as $team_id => $team ) {
			$team_names[ (int) $team_id ] = $team['name'];
		}
		if ( empty( $team_names ) ) {
			return;
		}
		$ids_int   = array_keys( $team_names );
		$selectors = array_map( fn( $id ) => '#user-' . $id, $ids_int );
		$sel       = implode( ",\n", $selectors );
		echo <<<CSS
		<style>
		{$sel} {
			background: #f6f7f7;
		}
		{$sel} td {
			border-top: 3px solid #e5e5e5;
		}
		{$sel} .column-username strong::before {
			content: 'Team';
			display: inline-block;
			font-size: 10px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			font-weight: 600;
			color: #2271b1;
			background: #e7f1fb;
			border-radius: 3px;
			padding: 2px 6px;
			margin-right: 6px;
			vertical-align: middle;
		}
		/* Hide the auto-generated `_team_*` login line in the username cell. */
		{$sel} .column-username .row-actions + br + span,
		{$sel} .column-username > br,
		{$sel} .column-username > span:not(.wput-role) {
			display: none;
		}
		/* Drop the placeholder `*@teams.internal` mailto — show a dash instead. */
		{$sel} .column-email a {
			display: none;
		}
		{$sel} .column-email::before {
			content: '—';
			color: #646970;
		}
		</style>
		CSS;
		echo "\n";

		$team_names_json = wp_json_encode( $team_names );
		$member_teams    = wp_json_encode( $this->collect_member_team_names_for_screen() );
		?>
		<script>
		( function () {
			var teamNames  = <?php echo $team_names_json; ?>;
			var memberTeams = <?php echo $member_teams; ?>;
			document.addEventListener( 'DOMContentLoaded', function () {
				Object.keys( teamNames ).forEach( function ( id ) {
					var row = document.getElementById( 'user-' + id );
					if ( ! row ) { return; }
					var displayName = teamNames[ id ];

					// Walk text nodes in the username cell. The `_team_*`
					// login appears either inside the `<a>` (network Users
					// list) or as a sibling text node after `<br />`
					// (per-site Users list). Replace the in-link text with
					// the team's display name; drop the sibling copy.
					var username = row.querySelector( '.column-username' );
					if ( ! username ) { return; }
					var walker = document.createTreeWalker( username, NodeFilter.SHOW_TEXT );
					var inLink = [];
					var outside = [];
					while ( walker.nextNode() ) {
						var node = walker.currentNode;
						if ( ! /^\s*_team_/.test( node.nodeValue ) ) { continue; }
						if ( node.parentNode && node.parentNode.tagName === 'A' ) {
							inLink.push( node );
						} else {
							outside.push( node );
						}
					}
					inLink.forEach( function ( n ) { n.nodeValue = displayName; } );
					outside.forEach( function ( n ) { n.parentNode.removeChild( n ); } );
				} );

				// Append " — Team A, Team B" to member usernames, matching
				// WP's own "— Super Admin" marker. Skip team-user rows.
				Object.keys( memberTeams ).forEach( function ( uid ) {
					var row = document.getElementById( 'user-' + uid );
					if ( ! row ) { return; }
					if ( teamNames[ uid ] ) { return; }
					var strong = row.querySelector( '.column-username strong' );
					if ( ! strong ) { return; }
					strong.appendChild( document.createTextNode( ' \u2014 ' + memberTeams[ uid ].join( ', ' ) ) );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Map of `user_id => [team_name, ...]` for users whose teams are in
	 * scope for the current Users screen. Per-site screens limit to
	 * teams that apply to the current blog; the network screen includes
	 * every team membership.
	 */
	private function collect_member_team_names_for_screen() {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$network = $screen && 'users-network' === $screen->base;
		$blog_id = (int) get_current_blog_id();

		$out = array();
		foreach ( WP_User_Teams::get_all_teams() as $team_id => $team ) {
			if ( ! $network && ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			foreach ( WP_User_Teams::get_team_members( $team_id ) as $uid ) {
				$out[ (int) $uid ][] = $team['name'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Capability gate
	 * ---------------------------------------------------------------- */

	public static function required_cap() {
		return 'manage_network_users';
	}

	private function current_user_can_manage() {
		return current_user_can( self::required_cap() );
	}

	/* ------------------------------------------------------------------
	 * Menu registration
	 * ---------------------------------------------------------------- */

	public function register_network_menu() {
		add_submenu_page(
			'users.php',
			__( 'User Teams', 'wp-user-teams' ),
			__( 'Teams', 'wp-user-teams' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------
	 * Page router
	 * ---------------------------------------------------------------- */

	public function render_page() {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-user-teams' ) );
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? 'list' ) );

		switch ( $action ) {
			case 'edit':
			case 'new':
				$this->render_edit_form( $action );
				break;
			default:
				$this->render_list();
				break;
		}
	}

	/* ------------------------------------------------------------------
	 * List view
	 * ---------------------------------------------------------------- */

	private function render_list() {
		$teams   = WP_User_Teams::get_all_teams();
		$counts  = WP_User_Teams::count_members_per_team();
		$new_url = $this->page_url( array( 'action' => 'new' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'User Teams', 'wp-user-teams' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'wp-user-teams' ); ?></a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<p class="description">
				<?php esc_html_e( "Create teams that grant a WordPress role to every member. Users gain the team's role in addition to any role they already hold. Remove a user from a team and the access disappears immediately.", 'wp-user-teams' ); ?>
			</p>

			<?php if ( empty( $teams ) ) : ?>
				<p><em><?php esc_html_e( 'No teams yet. Create one to start organising user access.', 'wp-user-teams' ); ?></em></p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'wp-user-teams' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Slug', 'wp-user-teams' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'wp-user-teams' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sites', 'wp-user-teams' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Members', 'wp-user-teams' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $teams as $team ) :
					$role_names = wp_roles()->get_names();
					$edit_url   = $this->page_url( array( 'action' => 'edit', 'team_id' => $team['id'] ) );
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'       => 'wp_user_teams_delete',
								'team_id'      => $team['id'],
								'network_wide' => is_network_admin() ? '1' : '0',
							),
							admin_url( 'admin-post.php' )
						),
						self::NONCE_ACTION
					);
					$sites        = WP_User_Teams::get_team_sites( $team['id'] );
					$member_count = $counts[ $team['id'] ] ?? 0;
				?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $team['name'] ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wp-user-teams' ); ?></a> | </span>
								<span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this team? Members will lose the role it grants.', 'wp-user-teams' ) ); ?>');" class="submitdelete"><?php esc_html_e( 'Delete', 'wp-user-teams' ); ?></a></span>
							</div>
						</td>
						<td><code><?php echo esc_html( $team['slug'] ); ?></code></td>
						<td>
							<?php
							if ( $team['role'] && isset( $role_names[ $team['role'] ] ) ) {
								echo esc_html( translate_user_role( $role_names[ $team['role'] ] ) );
							} elseif ( $team['role'] ) {
								/* translators: %s: role slug */
								printf( '<em>%s</em>', esc_html( sprintf( __( 'Unknown role: %s', 'wp-user-teams' ), $team['role'] ) ) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<?php
							$has_global = ! empty( $team['role'] );
							$explicit   = count( $sites );
							if ( $has_global && 0 === $explicit ) {
								esc_html_e( 'All sites (via Global Role)', 'wp-user-teams' );
							} elseif ( $has_global && $explicit > 0 ) {
								echo esc_html( sprintf(
									/* translators: %s: number of per-site overrides */
									_n( 'All sites — %s with specific role', 'All sites — %s with specific roles', $explicit, 'wp-user-teams' ),
									number_format_i18n( $explicit )
								) );
							} elseif ( ! $has_global && $explicit > 0 ) {
								echo esc_html( sprintf(
									/* translators: %s: number of sites */
									_n( '%s site', '%s sites', $explicit, 'wp-user-teams' ),
									number_format_i18n( $explicit )
								) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $member_count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Add / Edit form
	 * ---------------------------------------------------------------- */

	private function render_edit_form( $action ) {
		$team_id = (int) ( $_GET['team_id'] ?? 0 );
		$team    = null;

		if ( 'edit' === $action ) {
			$team = WP_User_Teams::get_team( $team_id );
			if ( ! $team ) {
				wp_die( esc_html__( 'Team not found.', 'wp-user-teams' ) );
			}
		}

		$current_role = $team ? $team['role'] : '';
		$back_url     = $this->page_url();
		?>
		<div class="wrap">
			<h1>
				<?php
				echo esc_html(
					$team
						/* translators: %s: team name */
						? sprintf( __( 'Edit Team: %s', 'wp-user-teams' ), $team['name'] )
						: __( 'Add New Team', 'wp-user-teams' )
				);
				?>
			</h1>

			<?php $this->render_admin_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_user_teams_save" />
				<input type="hidden" name="network_wide" value="<?php echo is_network_admin() ? '1' : '0'; ?>" />
				<?php if ( $team ) : ?>
					<input type="hidden" name="team_id" value="<?php echo (int) $team['id']; ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wput-name"><?php esc_html_e( 'Name', 'wp-user-teams' ); ?></label></th>
						<td>
							<input name="name" type="text" id="wput-name" value="<?php echo esc_attr( $team ? $team['name'] : '' ); ?>" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Display name, e.g. "WordPress Meta Team".', 'wp-user-teams' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wput-slug"><?php esc_html_e( 'Slug', 'wp-user-teams' ); ?></label></th>
						<td>
							<input name="slug" type="text" id="wput-slug" value="<?php echo esc_attr( $team ? $team['slug'] : '' ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional. Auto-generated from the name if left blank.', 'wp-user-teams' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wput-role"><?php esc_html_e( 'Global Role', 'wp-user-teams' ); ?></label></th>
						<td>
							<select name="role" id="wput-role">
								<option value="" <?php selected( '', $current_role ); ?>><?php esc_html_e( '— Not set —', 'wp-user-teams' ); ?></option>
								<?php
								foreach ( wp_roles()->roles as $slug => $data ) {
									printf(
										'<option value="%s"%s>%s</option>',
										esc_attr( $slug ),
										selected( $slug, $current_role, false ),
										esc_html( translate_user_role( $data['name'] ) )
									);
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'When set, this role is granted for this team on all sites.', 'wp-user-teams' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( $team ? __( 'Update Team', 'wp-user-teams' ) : __( 'Create Team', 'wp-user-teams' ) ); ?>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Back to teams', 'wp-user-teams' ); ?></a>
			</form>

			<?php if ( $team ) : ?>
				<?php $this->render_team_sites_section( $team ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Sites-with-role-grants section on the team edit page.
	 *
	 * Lists only the sites the team is actually granted on, each with a
	 * Remove link. A separate form below the list adds a new site + role.
	 * Kept outside the main edit form because HTML forms cannot nest.
	 */
	private function render_team_sites_section( array $team ) {
		$site_roles = WP_User_Teams::get_team_site_roles( $team['id'] );
		$role_names = wp_roles()->get_names();
		$all_sites  = get_sites( array( 'number' => 0 ) );

		// Index sites by blog_id for quick lookup.
		$sites_by_id = array();
		foreach ( $all_sites as $site ) {
			$sites_by_id[ (int) $site->blog_id ] = $site;
		}

		// Sites eligible to be added: those not already in the team's grants.
		$addable = array();
		foreach ( $all_sites as $site ) {
			if ( ! array_key_exists( (int) $site->blog_id, $site_roles ) ) {
				$addable[] = $site;
			}
		}

		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Sites with role grants', 'wp-user-teams' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Specific sites this team is granted on, with the role each member receives there.', 'wp-user-teams' ); ?>
		</p>

		<?php if ( empty( $site_roles ) ) : ?>
			<p><em><?php esc_html_e( 'No per-site grants. Use the form below to add one, or set a Global Role above to cover every site.', 'wp-user-teams' ); ?></em></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:720px;">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'wp-user-teams' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'wp-user-teams' ); ?></th>
						<th scope="col" style="width:80px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $site_roles as $blog_id => $role_slug ) :
						$site = $sites_by_id[ $blog_id ] ?? null;
						if ( ! $site ) {
							continue;
						}
						$role_label = $role_slug === ''
							? __( 'inherits Global Role', 'wp-user-teams' )
							: ( $role_names[ $role_slug ] ?? $role_slug );
						$remove_url = wp_nonce_url(
							add_query_arg(
								array(
									'action'   => 'wp_user_teams_remove_site',
									'team_id'  => (int) $team['id'],
									'blog_id'  => (int) $blog_id,
								),
								admin_url( 'admin-post.php' )
							),
							self::NONCE_ACTION
						);
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $site->blogname ); ?></strong><br />
								<span style="color:#646970;"><?php echo esc_html( untrailingslashit( $site->domain . $site->path ) ); ?></span>
							</td>
							<td>
								<?php echo esc_html( translate_user_role( $role_label ) ); ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $remove_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this site from the team?', 'wp-user-teams' ) ); ?>');" class="submitdelete">
									<?php esc_html_e( 'Remove', 'wp-user-teams' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $addable ) ) : ?>
			<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Add a site', 'wp-user-teams' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;">
				<input type="hidden" name="action" value="wp_user_teams_add_site" />
				<input type="hidden" name="team_id" value="<?php echo (int) $team['id']; ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wput-add-site-blog"><?php esc_html_e( 'Site', 'wp-user-teams' ); ?></label></th>
						<td>
							<select name="blog_id" id="wput-add-site-blog" required>
								<option value=""><?php esc_html_e( '— Select a site —', 'wp-user-teams' ); ?></option>
								<?php foreach ( $addable as $site ) : ?>
									<option value="<?php echo (int) $site->blog_id; ?>">
										<?php echo esc_html( $site->blogname ); ?>
										<span style="color:#646970;"> (<?php echo esc_html( untrailingslashit( $site->domain . $site->path ) ); ?>)</span>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wput-add-site-role"><?php esc_html_e( 'Role', 'wp-user-teams' ); ?></label></th>
						<td>
							<select name="role" id="wput-add-site-role">
								<option value=""><?php esc_html_e( 'Inherit Global Role', 'wp-user-teams' ); ?></option>
								<?php foreach ( $role_names as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php echo esc_html( translate_user_role( $name ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Site', 'wp-user-teams' ), 'secondary', 'wput_add_site_submit' ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	public function handle_save() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage teams.', 'wp-user-teams' ) );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug    = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$role    = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );
		$team_id = (int) ( $_POST['team_id'] ?? 0 );

		if ( $team_id ) {
			$result = WP_User_Teams::update_team(
				$team_id,
				array(
					'name' => $name,
					'slug' => $slug,
					'role' => $role,
				)
			);
		} else {
			$result  = WP_User_Teams::create_team( $name, $slug, $role );
			$team_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'save-failed', $result->get_error_message() );
			return;
		}


		$this->redirect_with_notice( 'saved' );
	}

	public function handle_delete() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage teams.', 'wp-user-teams' ) );
		}

		$team_id = (int) ( $_GET['team_id'] ?? 0 );
		if ( ! $team_id || ! WP_User_Teams::delete_team( $team_id ) ) {
			$this->redirect_with_notice( 'delete-failed' );
			return;
		}

		$this->redirect_with_notice( 'deleted' );
	}

	/* ------------------------------------------------------------------
	 * User profile section
	 * ---------------------------------------------------------------- */

	public function render_user_field( $user ) {
		if ( ! $this->current_user_can_manage() ) {
			return;
		}

		$teams         = WP_User_Teams::get_all_teams();
		$user_team_ids = WP_User_Teams::get_user_team_ids( $user->ID );
		$role_names    = wp_roles()->get_names();
		?>
		<h2><?php esc_html_e( 'User Teams', 'wp-user-teams' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Teams', 'wp-user-teams' ); ?></th>
				<td>
					<?php wp_nonce_field( self::USER_NONCE, 'wput_user_nonce' ); ?>
					<?php if ( empty( $teams ) ) : ?>
						<p><em><?php esc_html_e( 'No teams have been defined yet.', 'wp-user-teams' ); ?></em></p>
					<?php else : ?>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'User Teams', 'wp-user-teams' ); ?></legend>
							<?php foreach ( $teams as $team ) : ?>
								<label style="display:block;margin-bottom:0.25em;">
									<input type="checkbox" name="wput_teams[]" value="<?php echo (int) $team['id']; ?>" <?php checked( in_array( $team['id'], $user_team_ids, true ) ); ?> />
									<strong><?php echo esc_html( $team['name'] ); ?></strong>
									<?php if ( $team['role'] && isset( $role_names[ $team['role'] ] ) ) : ?>
										<span style="color:#646970;">— <?php echo esc_html( translate_user_role( $role_names[ $team['role'] ] ) ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( "Membership grants the team's role in addition to the user's existing role.", 'wp-user-teams' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Hook for `user_new_form`. Delegates to the single-site vs. multisite
	 * renderer depending on context.
	 *
	 * @param string $context Supplied by WP: 'add-new-user', 'add-existing-user', ...
	 */
	/**
	 * Gatekeeper for the single-site team-picker hook — wraps the render
	 * in the single-site permission check.
	 */
	/**
	 * Displays the success/error notice set by
	 * `handle_attach_team_to_site` when it redirects back to user-new.php.
	 */
	/**
	 * Per-site users.php notices for the "Remove from site" row action —
	 * the admin-post handler redirects here instead of the Teams page
	 * because site admins don't have access to it.
	 */
	public function render_users_screen_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users' !== $screen->base ) {
			return;
		}
		$notice = isset( $_GET['wput_notice'] ) ? sanitize_key( wp_unslash( $_GET['wput_notice'] ) ) : '';
		$map    = array(
			'site-removed'       => array( 'success', __( 'Team removed from this site.', 'wp-user-teams' ) ),
			'site-remove-failed' => array( 'error',   __( 'The team could not be removed from this site.', 'wp-user-teams' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	public function render_user_new_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'user' !== $screen->base ) {
			return;
		}
		if ( empty( $_GET['wput_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['wput_notice'] ) );
		$detail = sanitize_text_field( wp_unslash( $_GET['wput_detail'] ?? '' ) );

		$map = array(
			'attach-saved'  => array(
				'success',
				/* translators: %s: team name */
				$detail
					? sprintf( __( 'Team &#8220;%s&#8221; was added to this site.', 'wp-user-teams' ), $detail )
					: __( 'Team was added to this site.', 'wp-user-teams' ),
			),
			'attach-failed' => array(
				'error',
				__( 'The team could not be added to this site.', 'wp-user-teams' ),
			),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	public function maybe_render_add_team_to_site_section() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'user' !== $screen->base ) {
			return; // Per-site user-new.php only.
		}
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}
		$this->render_add_team_to_site_section();
	}

	/**
	 * "Add a whole team to this site" form — shown below the Add New User
	 * form on multisite. Picks a team and extends that team's site scope to
	 * include the current blog, granting all team members the team's role
	 * here in one click instead of inviting users individually.
	 */
	private function render_add_team_to_site_section() {
		$teams   = WP_User_Teams::get_all_teams();
		$blog_id = (int) get_current_blog_id();

		$available = array();
		foreach ( $teams as $team ) {
			if ( isset( $team['sites'][ $blog_id ] ) ) {
				continue; // Already has an explicit per-site grant here.
			}
			$available[] = $team; // A team with a Global Role still shows up — adding a per-site entry overrides the Global Role for this site.
		}

		if ( empty( $available ) ) {
			return;
		}

		$role_names = wp_roles()->get_names();
		?>
		<div class="wput-add-team-to-site" data-wput-relocate-below="form#createuser,form#adduser" style="border-top:1px solid #dcdcde;margin-top:2em;padding-top:1em;">
			<h2><?php esc_html_e( 'Add a Team to This Site', 'wp-user-teams' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Grant every member of an existing team access to this site, instead of inviting one user at a time.', 'wp-user-teams' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_user_teams_attach_site" />
				<input type="hidden" name="blog_id" value="<?php echo (int) $blog_id; ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wput-attach-team"><?php esc_html_e( 'Team', 'wp-user-teams' ); ?></label></th>
						<td>
							<select name="team_id" id="wput-attach-team" required>
								<option value=""><?php esc_html_e( '— Select a team —', 'wp-user-teams' ); ?></option>
								<?php foreach ( $available as $team ) : ?>
									<option value="<?php echo (int) $team['id']; ?>"><?php echo esc_html( $team['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wput-attach-role"><?php esc_html_e( 'Role on this site', 'wp-user-teams' ); ?></label></th>
						<td>
							<select name="role" id="wput-attach-role">
								<?php foreach ( array_keys( $role_names ) as $slug ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"<?php echo 'subscriber' === $slug ? ' selected' : ''; ?>>
										<?php echo esc_html( translate_user_role( $role_names[ $slug ] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Team', 'wp-user-teams' ), 'primary', 'wput_attach_submit' ); ?>
			</form>
		</div>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var el = document.querySelector( '.wput-add-team-to-site' );
			if ( ! el ) { return; }
			var selectors = ( el.getAttribute( 'data-wput-relocate-below' ) || '' ).split( ',' );
			var anchor = null;
			for ( var i = 0; i < selectors.length; i++ ) {
				var candidates = document.querySelectorAll( selectors[ i ].trim() );
				if ( candidates.length ) {
					anchor = candidates[ candidates.length - 1 ];
					break;
				}
			}
			if ( anchor && anchor.parentNode ) {
				anchor.parentNode.insertBefore( el, anchor.nextSibling );
			}
		} );
		</script>
		<?php
	}

	/**
	 * `admin-post.php` handler for "Add Team to This Site".
	 *
	 * Extends the target team's `sites` list to include the current blog.
	 * A team with an empty `sites` list already applies everywhere, so it
	 * is treated as "already attached" and ignored.
	 */
	/**
	 * Add a site + role grant to a team (from the team edit page).
	 */
	public function handle_add_site() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage teams.', 'wp-user-teams' ) );
		}

		$team_id = (int) ( $_POST['team_id'] ?? 0 );
		$blog_id = (int) ( $_POST['blog_id'] ?? 0 );
		$role    = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );

		$team = WP_User_Teams::get_team( $team_id );
		if ( ! $team || $blog_id <= 0 ) {
			$this->redirect_with_notice( 'save-failed', __( 'Invalid site or team.', 'wp-user-teams' ) );
			return;
		}
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$site_roles             = $team['sites'];
		$site_roles[ $blog_id ] = $role;
		WP_User_Teams::set_team_sites( $team_id, $site_roles );

		wp_safe_redirect( $this->page_url( array( 'action' => 'edit', 'team_id' => $team_id, 'notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Remove a site's role grant from a team.
	 *
	 * Super-admin only — teams are a network-wide concept. The per-site
	 * row action is also only shown to super admins.
	 */
	public function handle_remove_site() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage teams.', 'wp-user-teams' ) );
		}

		$team_id  = (int) ( $_GET['team_id'] ?? 0 );
		$blog_id  = (int) ( $_GET['blog_id'] ?? 0 );
		$from_net = is_network_admin();

		$team   = WP_User_Teams::get_team( $team_id );
		$notice = 'save-failed';
		if ( $team && $blog_id > 0 ) {
			$site_roles = $team['sites'];
			unset( $site_roles[ $blog_id ] );
			WP_User_Teams::set_team_sites( $team_id, $site_roles );
			$notice = 'saved';
		}

		if ( $from_net ) {
			wp_safe_redirect( $this->page_url( array( 'action' => 'edit', 'team_id' => $team_id, 'notice' => $notice ) ) );
		} else {
			wp_safe_redirect( add_query_arg(
				'wput_notice',
				'saved' === $notice ? 'site-removed' : 'site-remove-failed',
				admin_url( 'users.php' )
			) );
		}
		exit;
	}

	public function handle_attach_team_to_site() {
		check_admin_referer( self::NONCE_ACTION );

		$team_id = (int) ( $_POST['team_id'] ?? 0 );
		$blog_id = (int) ( $_POST['blog_id'] ?? get_current_blog_id() );
		$role    = sanitize_key( wp_unslash( $_POST['role'] ?? '' ) );

		// Anyone who can manage users on the target site may attach a
		// team to it. Super admins naturally satisfy this everywhere.
		if ( ! current_user_can_for_blog( $blog_id, 'promote_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage users on this site.', 'wp-user-teams' ) );
		}

		$team = WP_User_Teams::get_team( $team_id );
		if ( ! $team || ! $blog_id ) {
			$this->redirect_to_user_new( 'attach-failed' );
			return;
		}

		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$site_roles             = $team['sites'];
		$site_roles[ $blog_id ] = $role; // Empty role = inherit Global Role here.
		WP_User_Teams::set_team_sites( $team_id, $site_roles );

		$this->redirect_to_user_new( 'attach-saved', $team['name'] );
	}

	private function redirect_to_user_new( $notice, $detail = '' ) {
		$args = array(
			'wput_notice' => $notice,
		);
		if ( $detail ) {
			$args['wput_detail'] = rawurlencode( $detail );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'user-new.php' ) ) );
		exit;
	}

	public function save_user_field( $user_id ) {
		if ( ! $this->current_user_can_manage() ) {
			return;
		}
		if ( empty( $_POST['wput_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wput_user_nonce'] ) ), self::USER_NONCE ) ) {
			return;
		}

		$submitted = ( isset( $_POST['wput_teams'] ) && is_array( $_POST['wput_teams'] ) )
			? array_map( 'intval', wp_unslash( $_POST['wput_teams'] ) )
			: array();

		WP_User_Teams::set_user_teams( $user_id, $submitted );
	}

	/* ------------------------------------------------------------------
	 * Users list table column
	 * ---------------------------------------------------------------- */

	public function add_users_column( $columns ) {
		$columns['user_teams'] = __( 'Teams', 'wp-user-teams' );
		return $columns;
	}

	public function render_users_column( $output, $column, $user_id ) {
		if ( 'user_teams' !== $column ) {
			return $output;
		}
		$teams = WP_User_Teams::get_user_teams( $user_id );
		if ( empty( $teams ) ) {
			return '&mdash;';
		}
		return esc_html( implode( ', ', wp_list_pluck( $teams, 'name' ) ) );
	}

	/**
	 * Adds team-derived roles to the Users list "Role" column, denoting
	 * when a user has a role through team membership in addition to
	 * (or instead of) their own role on this site.
	 *
	 * Only team roles that the user does NOT already hold natively on
	 * this site are surfaced, to avoid duplicate labels.
	 */
	public function disclose_team_roles_in_users_list( $role_list, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $role_list;
		}

		$blog_id    = (int) get_current_blog_id();
		$role_names = wp_roles()->get_names();

		// For team-user rows on the Users list, show the role the team
		// grants on this site. A per-site role is already reflected in
		// the team's capabilities meta (native `$user->roles`); a team
		// with only a Global Role would otherwise appear roleless, so
		// resolve it from `$team['role']` here. If there's no role to
		// show, swap WP's "None" for an em-dash so the column reads as
		// "no grant here" instead of "no role at all".
		if ( WP_User_Teams::is_team_user( $user->ID ) ) {
			if ( ! empty( $user->roles ) ) {
				return $role_list;
			}
			$team  = WP_User_Teams::get_team( $user->ID );
			$label = ( $team && ! empty( $team['role'] ) && isset( $role_names[ $team['role'] ] ) )
				? translate_user_role( $role_names[ $team['role'] ] )
				: '';

			if ( '' === $label ) {
				return is_array( $role_list ) ? array( '—' ) : '—';
			}
			if ( is_array( $role_list ) ) {
				return array( $label );
			}
			return $label;
		}

		$native     = array_map( 'strval', (array) $user->roles );

		$team_entries = array();
		foreach ( WP_User_Teams::get_user_teams( $user->ID ) as $team_id => $team ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			$role_slug = WP_User_Teams::resolve_role_for_site( $team, $blog_id );
			if ( '' === $role_slug || in_array( $role_slug, $native, true ) ) {
				continue;
			}
			$label = $role_names[ $role_slug ] ?? $role_slug;
			$team_entries[ $role_slug ] = sprintf(
				/* translators: 1: role label, 2: team name */
				__( '%1$s (via %2$s)', 'wp-user-teams' ),
				translate_user_role( $label ),
				$team['name']
			);
		}

		if ( empty( $team_entries ) ) {
			return $role_list;
		}

		// Drop WP's default "None" entry when we're adding team-derived
		// roles — the user *does* have a role here, just via their team.
		if ( is_array( $role_list ) ) {
			unset( $role_list['none'] );
			return array_merge( $role_list, array_values( $team_entries ) );
		}
		$none = _x( 'None', 'no user roles' );
		if ( $role_list === $none ) {
			return implode( ', ', $team_entries );
		}
		return $role_list . ' + ' . implode( ', ', $team_entries );
	}

	/* ------------------------------------------------------------------
	 * Team filter on Users list tables
	 * ---------------------------------------------------------------- */

	/**
	 * Appends a "Team: …" view link per team to the Users table filter bar.
	 * Works on both single-site users.php and multisite network/users.php.
	 */
	public function filter_user_views( $views ) {
		$teams = WP_User_Teams::get_all_teams();
		if ( empty( $teams ) ) {
			return $views;
		}

		$screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_site_list = $screen && 'users' === $screen->base; // false for 'users-network'.
		$blog_id      = (int) get_current_blog_id();

		// Ensure roles that only exist on this site via teams (no native
		// user has that role here) still appear in the role filter bar.
		if ( $is_site_list ) {
			$views = $this->inject_team_derived_role_views( $views, $blog_id );
		}

		$counts   = WP_User_Teams::count_members_per_team();
		$current  = (int) ( $_GET['team'] ?? 0 );
		// Clear other view-scope args when switching to a team view so the
		// URL stays canonical (e.g. ?role=administrator → ?team=4).
		$base_url = remove_query_arg( array( 'team', 'role', 'paged', 's' ) );

		foreach ( $teams as $team ) {
			$count = (int) ( $counts[ $team['id'] ] ?? 0 );
			if ( 0 === $count ) {
				continue; // Empty team — nothing to filter to.
			}
			if ( $is_site_list && ! WP_User_Teams::team_applies_to_site( $team['id'], $blog_id ) ) {
				continue; // Team doesn't cover this site.
			}

			$url   = add_query_arg( 'team', (int) $team['id'], $base_url );
			$class = ( $current === (int) $team['id'] ) ? ' class="current" aria-current="page"' : '';
			$views[ 'wput-team-' . $team['id'] ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$class,
				/* translators: %s: team name */
				esc_html( sprintf( __( 'via %s', 'wp-user-teams' ), $team['name'] ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Includes team-derived members in the main Users list query so they
	 * appear on wp-admin/users.php even without a native
	 * `wp_{blog_id}_capabilities` meta row for the current blog.
	 *
	 * WP_Meta_Query EXISTS joins wp_usermeta with INNER JOIN, which filters
	 * out everyone without that meta. We weaken the join to LEFT JOIN and
	 * OR-append our team member IDs to the WHERE predicate.
	 *
	 * @param WP_User_Query $query
	 */
	public function include_team_members_in_user_query( $query ) {
		// Reentrancy guard: this callback runs `get_all_teams()` internally,
		// which issues its own `WP_User_Query`. Without the guard we'd
		// recurse back into ourselves.
		static $running = false;
		if ( $running ) {
			return;
		}
		$blog_id = (int) $query->get( 'blog_id' );
		if ( $blog_id <= 0 ) {
			return;
		}
		$running = true;
		try {
			$this->apply_team_member_injection( $query, $blog_id );
		} finally {
			$running = false;
		}
	}

	private function apply_team_member_injection( $query, $blog_id ) {
		$team_filter = (int) ( $_GET['team'] ?? 0 );
		$role_filter = (string) $query->get( 'role' );
		$search      = (string) $query->get( 'search' );
		$has_role_in = ! empty( $query->get( 'role__in' ) ) || ! empty( $query->get( 'role__not_in' ) );

		if ( $has_role_in ) {
			return; // role__in / role__not_in queries are programmatic; leave alone.
		}

		if ( $team_filter > 0 ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_filter, $blog_id ) ) {
				return;
			}
			$extra_ids = WP_User_Teams::get_team_members( $team_filter );
		} elseif ( '' !== $role_filter ) {
			// `?role=X`: include team members whose team-derived role on
			// this site equals X, so they surface under the matching
			// role view alongside natively-roled users.
			$extra_ids = $this->collect_team_member_ids_with_role( $blog_id, $role_filter );
		} else {
			$extra_ids = $this->collect_applicable_team_member_ids( $blog_id );
		}

		// If the user is searching, narrow the team-member IDs to those
		// actually matching the search term — otherwise our OR clause
		// would drag in every team member regardless of the query.
		if ( '' !== $search && ! empty( $extra_ids ) ) {
			$extra_ids = $this->filter_user_ids_by_search( $extra_ids, $search );
		}

		if ( empty( $extra_ids ) ) {
			return;
		}

		global $wpdb;
		$ids_sql = implode( ',', array_map( 'intval', $extra_ids ) );

		// Downgrade the capabilities-meta INNER JOIN to LEFT JOIN so team
		// members without a `wp_{blog_id}_capabilities` row survive.
		$query->query_from = preg_replace(
			'/\bINNER\s+JOIN\s+' . preg_quote( $wpdb->usermeta, '/' ) . '\b/i',
			"LEFT JOIN {$wpdb->usermeta}",
			$query->query_from
		);

		// OR the team member IDs into the WHERE predicate. The LEFT JOIN
		// above can now duplicate rows per team member (one per usermeta
		// entry), so ensure SELECT is DISTINCT and total counts collapse.
		$query->query_where .= sprintf(
			' OR %s.ID IN (%s)',
			$wpdb->users,
			$ids_sql
		);

		if ( stripos( $query->query_fields, 'DISTINCT' ) === false ) {
			$query->query_fields = preg_replace(
				'/^(\s*SQL_CALC_FOUND_ROWS\s+)?/i',
				'$1DISTINCT ',
				$query->query_fields,
				1
			);
		}
	}

	private function collect_applicable_team_member_ids( $blog_id ) {
		$ids = array();
		foreach ( WP_User_Teams::get_all_teams() as $team_id => $team ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			if ( '' === WP_User_Teams::resolve_role_for_site( $team, $blog_id ) ) {
				continue;
			}
			foreach ( WP_User_Teams::get_team_members( $team_id ) as $uid ) {
				$ids[ (int) $uid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Adds a role filter link (e.g. "Editor (3)") for any role that has
	 * team members on this site but no native users — WP's own views
	 * code only emits links for roles with a native count > 0.
	 *
	 * @param array $views The views filter array keyed by role slug.
	 * @param int   $blog_id
	 * @return array
	 */
	private function inject_team_derived_role_views( $views, $blog_id ) {
		$counts_by_role = array();
		foreach ( WP_User_Teams::get_all_teams() as $team_id => $team ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			$role_slug = WP_User_Teams::resolve_role_for_site( $team, $blog_id );
			if ( '' === $role_slug ) {
				continue;
			}
			foreach ( WP_User_Teams::get_team_members( $team_id ) as $uid ) {
				$counts_by_role[ $role_slug ][ (int) $uid ] = true;
			}
		}
		if ( empty( $counts_by_role ) ) {
			return $views;
		}

		$roles      = wp_roles();
		$current    = (string) ( $_GET['role'] ?? '' );
		$base_url   = remove_query_arg( array( 'role', 'team', 'paged', 's' ) );
		$role_names = $roles->get_names();

		foreach ( $counts_by_role as $role_slug => $user_ids ) {
			if ( isset( $views[ $role_slug ] ) ) {
				continue; // Already rendered natively — don't double-up.
			}
			if ( ! isset( $role_names[ $role_slug ] ) ) {
				continue;
			}
			$url   = add_query_arg( 'role', $role_slug, $base_url );
			$class = ( $current === $role_slug ) ? ' class="current" aria-current="page"' : '';
			$views[ $role_slug ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( translate_user_role( $role_names[ $role_slug ] ) ),
				esc_html( number_format_i18n( count( $user_ids ) ) )
			);
		}

		return $views;
	}

	/**
	 * Mirrors WP_User_Query's search semantics in PHP for the team member
	 * injection path: keep only IDs whose user fields match the search
	 * term. Leading/trailing `*` are treated as wildcards the same way
	 * WP treats them (any position match).
	 */
	private function filter_user_ids_by_search( $ids, $search ) {
		$term = trim( $search, "* \t\n\r\0\x0B" );
		if ( '' === $term ) {
			return $ids;
		}
		$needle = strtolower( $term );

		$matching = array();
		foreach ( $ids as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( ! $u ) {
				continue;
			}
			foreach ( array( $u->user_login, $u->user_email, $u->display_name, $u->user_nicename, $u->user_url ) as $field ) {
				if ( '' !== (string) $field && false !== stripos( (string) $field, $needle ) ) {
					$matching[] = (int) $uid;
					continue 2;
				}
			}
		}
		return $matching;
	}

	/**
	 * User IDs whose team-derived role on the given blog equals the
	 * requested role slug.
	 */
	private function collect_team_member_ids_with_role( $blog_id, $role_slug ) {
		$ids = array();
		foreach ( WP_User_Teams::get_all_teams() as $team_id => $team ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			if ( WP_User_Teams::resolve_role_for_site( $team, $blog_id ) !== $role_slug ) {
				continue;
			}
			foreach ( WP_User_Teams::get_team_members( $team_id ) as $uid ) {
				$ids[ (int) $uid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Injects team rows at the top of the multisite per-site Users list.
	 *
	 * Emits a `<template>` containing a styled `<tr>` per team that covers
	 * this site, plus a small script that relocates them into the head of
	 * `#the-list` after the list table renders. Each row includes avatars
	 * for members, the role granted here, and inline Remove / Change-role
	 * actions scoped to this blog.
	 */
	public function render_site_team_coverage_table() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users' !== $screen->base ) {
			return;
		}

		$blog_id = (int) get_current_blog_id();
		$teams   = WP_User_Teams::get_all_teams();
		if ( empty( $teams ) ) {
			return;
		}

		$role_names = wp_roles()->get_names();
		$covering   = array();
		foreach ( $teams as $team_id => $team ) {
			if ( ! WP_User_Teams::team_applies_to_site( $team_id, $blog_id ) ) {
				continue;
			}
			$role_slug = WP_User_Teams::resolve_role_for_site( $team, $blog_id );
			if ( '' === $role_slug ) {
				continue;
			}
			$covering[] = array(
				'team'    => $team,
				'role'    => $role_slug,
				'members' => WP_User_Teams::get_team_members( $team_id ),
			);
		}

		if ( empty( $covering ) ) {
			return;
		}

		$can_manage     = is_super_admin();
		$teams_page_url = $can_manage
			? add_query_arg( 'page', WP_User_Teams_Admin::PAGE_SLUG, network_admin_url( 'users.php' ) )
			: '';
		$avatar_limit   = 6;
		?>
		<style>
			tr.wput-team-row { background:#f6f7f7 !important; }
			tr.wput-team-row td { border-top:3px solid #e5e5e5; }
			tr.wput-team-row .wput-team-badge {
				display:inline-block; font-size:10px; text-transform:uppercase;
				letter-spacing:0.04em; font-weight:600; color:#2271b1;
				background:#e7f1fb; border-radius:3px; padding:2px 6px; margin-right:6px;
				vertical-align:middle;
			}
			tr.wput-team-row .wput-avatars { display:flex; flex-wrap:wrap; align-items:center; margin-top:4px; }
			tr.wput-team-row .wput-avatars .wput-avatar {
				display:inline-block; line-height:0; margin-left:-6px;
			}
			tr.wput-team-row .wput-avatars .wput-avatar:first-child { margin-left:0; }
			tr.wput-team-row .wput-avatars .wput-avatar img {
				width:26px; height:26px; border-radius:50%; display:block;
				border:2px solid #fff; box-shadow:0 0 0 1px #dcdcde;
			}
			tr.wput-team-row .wput-avatars .wput-more { font-size:12px; color:#646970; margin-left:0.5em; }
			tr.wput-team-row .row-actions { color:#8c8f94; }
		</style>
		<template id="wput-team-rows">
			<?php foreach ( $covering as $row ) :
				$team         = $row['team'];
				$team_name    = esc_html( $team['name'] );
				$team_id      = (int) $team['id'];
				if ( $teams_page_url ) {
					$edit_url  = add_query_arg(
						array( 'action' => 'edit', 'team_id' => $team_id ),
						$teams_page_url
					);
					$team_name = '<a href="' . esc_url( $edit_url ) . '">' . $team_name . '</a>';
				}
				$role_label   = translate_user_role( $role_names[ $row['role'] ] ?? $row['role'] );
				$members      = $row['members'];
				$member_count = count( $members );
				$shown        = array_slice( $members, 0, $avatar_limit );
				$overflow     = $member_count - count( $shown );
				$remove_url   = $can_manage ? wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'wp_user_teams_remove_site',
							'team_id' => $team_id,
							'blog_id' => $blog_id,
						),
						admin_url( 'admin-post.php' )
					),
					self::NONCE_ACTION
				) : '';
			?>
				<tr class="wput-team-row">
					<td class="check-column"></td>
					<td class="column-username column-primary">
						<span class="wput-team-badge"><?php esc_html_e( 'Team', 'wp-user-teams' ); ?></span>
						<strong><?php echo $team_name; // safe: built from esc_html/esc_url. ?></strong>
						<?php if ( $can_manage ) : ?>
							<div class="row-actions">
								<?php if ( $teams_page_url ) : ?>
									<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit team', 'wp-user-teams' ); ?></a></span>
								<?php endif; ?>
								<?php if ( $remove_url ) : ?>
									<?php if ( $teams_page_url ) echo ' | '; ?>
									<span class="delete"><a href="<?php echo esc_url( $remove_url ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Remove this team from the site? Members lose the team-granted role here.', 'wp-user-teams' ) ); ?>');"><?php esc_html_e( 'Remove from site', 'wp-user-teams' ); ?></a></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</td>
					<td class="column-name">
						<?php if ( $member_count > 0 ) : ?>
							<div class="wput-avatars">
								<?php foreach ( $shown as $uid ) :
									$user     = get_userdata( (int) $uid );
									$alt_name = $user ? $user->display_name : '';
								?>
									<span class="wput-avatar" title="<?php echo esc_attr( $alt_name ); ?>" aria-label="<?php echo esc_attr( $alt_name ); ?>">
										<?php echo get_avatar( (int) $uid, 26, '', $alt_name ); ?>
									</span>
								<?php endforeach; ?>
								<?php if ( $overflow > 0 ) : ?>
									<span class="wput-more">+<?php echo esc_html( number_format_i18n( $overflow ) ); ?></span>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<span style="color:#646970;"><?php esc_html_e( 'No members', 'wp-user-teams' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="column-email">—</td>
					<td class="column-role">
						<?php echo esc_html( $role_label ); ?>
						<?php if ( $can_manage && $teams_page_url ) : ?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Change role', 'wp-user-teams' ); ?></a></span>
							</div>
						<?php endif; ?>
					</td>
					<td class="column-posts">—</td>
				</tr>
			<?php endforeach; ?>
		</template>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var tpl  = document.getElementById( 'wput-team-rows' );
			var list = document.getElementById( 'the-list' );
			if ( ! tpl || ! list ) { return; }
			var frag = tpl.content.cloneNode( true );
			list.insertBefore( frag, list.firstChild );
		} );
		</script>
		<?php
	}

	/**
	 * Filters the Users list query by the team selected via `?team=N`.
	 *
	 * Memberships are stored as a serialized PHP array in user_meta; an
	 * `i:N;` substring match is the cheapest reliable way to find users
	 * that contain that integer in their membership list.
	 *
	 * @param WP_User_Query $query
	 */
	public function apply_team_filter_to_query( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		$team_id = (int) ( $_GET['team'] ?? 0 );
		if ( $team_id <= 0 ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'users' !== $screen->base && 'users-network' !== $screen->base ) {
			return;
		}

		$existing = (array) $query->get( 'meta_query' );
		$existing[] = array(
			'key'     => WP_User_Teams::USER_META_KEY,
			'value'   => sprintf( 'i:%d;', $team_id ),
			'compare' => 'LIKE',
		);
		$query->set( 'meta_query', $existing );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	private function page_url( $args = array() ) {
		$base = is_network_admin()
			? network_admin_url( 'users.php' )
			: admin_url( 'users.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), $base );
	}

	private function redirect_with_notice( $notice, $detail = '' ) {
		$network = ! empty( $_REQUEST['network_wide'] );
		$base    = $network
			? network_admin_url( 'users.php' )
			: admin_url( 'users.php' );

		$args = array(
			'page'   => self::PAGE_SLUG,
			'notice' => $notice,
		);
		if ( $detail ) {
			$args['detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	private function render_admin_notices() {
		if ( empty( $_GET['notice'] ) ) {
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['notice'] ) );
		$detail = sanitize_text_field( wp_unslash( $_GET['detail'] ?? '' ) );

		$map = array(
			'saved'         => array( 'success', __( 'Team saved.', 'wp-user-teams' ) ),
			'deleted'       => array( 'success', __( 'Team deleted.', 'wp-user-teams' ) ),
			'missing-name'  => array( 'error', __( 'A name is required.', 'wp-user-teams' ) ),
			'save-failed'   => array( 'error', __( 'The team could not be saved.', 'wp-user-teams' ) ),
			'delete-failed' => array( 'error', __( 'The team could not be deleted.', 'wp-user-teams' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] ),
			$detail ? ' <em>' . esc_html( $detail ) . '</em>' : ''
		);
	}
}
