<?php
/**
 * Admin UI for User Groups.
 *
 * - Groups management page (Users → Groups on single site; Network Admin → Users → Groups on multisite).
 * - User-edit profile section with group membership checkboxes.
 * - "Groups" column on the Users list table.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Groups_Admin {

	const PAGE_SLUG    = 'user-groups';
	const NONCE_ACTION = 'wp_user_groups';
	const USER_NONCE   = 'wp_user_groups_user';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
		}

		add_action( 'admin_post_wp_user_groups_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wp_user_groups_delete', array( $this, 'handle_delete' ) );

		add_action( 'show_user_profile', array( $this, 'render_user_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_field' ) );

		add_filter( 'manage_users_columns', array( $this, 'add_users_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_users_column' ), 10, 3 );
	}

	/* ------------------------------------------------------------------
	 * Capability gate
	 * ---------------------------------------------------------------- */

	public static function required_cap() {
		return is_multisite() ? 'manage_network_users' : 'promote_users';
	}

	private function current_user_can_manage() {
		return current_user_can( self::required_cap() );
	}

	/* ------------------------------------------------------------------
	 * Menu registration
	 * ---------------------------------------------------------------- */

	public function register_menu() {
		add_users_page(
			__( 'User Groups', 'wp-user-groups' ),
			__( 'Groups', 'wp-user-groups' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_network_menu() {
		add_submenu_page(
			'users.php',
			__( 'User Groups', 'wp-user-groups' ),
			__( 'Groups', 'wp-user-groups' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-user-groups' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

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
		$groups  = WP_User_Groups::get_all_groups();
		$counts  = WP_User_Groups::count_members_per_group();
		$new_url = $this->page_url( array( 'action' => 'new' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'User Groups', 'wp-user-groups' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'wp-user-groups' ); ?></a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<p class="description">
				<?php esc_html_e( "Create groups that grant a WordPress role to every member. Users gain the group's role in addition to any role they already hold. Remove a user from a group and the access disappears immediately.", 'wp-user-groups' ); ?>
			</p>

			<?php if ( empty( $groups ) ) : ?>
				<p><em><?php esc_html_e( 'No groups yet. Create one to start organising user access.', 'wp-user-groups' ); ?></em></p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'wp-user-groups' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Slug', 'wp-user-groups' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'wp-user-groups' ); ?></th>
						<?php if ( is_multisite() ) : ?>
							<th scope="col"><?php esc_html_e( 'Sites', 'wp-user-groups' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Members', 'wp-user-groups' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $groups as $group ) :
					$role_names = wp_roles()->get_names();
					$edit_url   = $this->page_url( array( 'action' => 'edit', 'group_id' => $group['id'] ) );
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'      => 'wp_user_groups_delete',
								'group_id'    => $group['id'],
								'network_wide' => is_network_admin() ? '1' : '0',
							),
							admin_url( 'admin-post.php' )
						),
						self::NONCE_ACTION
					);
					$sites       = is_multisite() ? WP_User_Groups::get_group_sites( $group['id'] ) : array();
					$member_count = isset( $counts[ $group['id'] ] ) ? $counts[ $group['id'] ] : 0;
				?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $group['name'] ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wp-user-groups' ); ?></a> | </span>
								<span class="delete"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this group? Members will lose the role it grants.', 'wp-user-groups' ) ); ?>');" class="submitdelete"><?php esc_html_e( 'Delete', 'wp-user-groups' ); ?></a></span>
							</div>
						</td>
						<td><code><?php echo esc_html( $group['slug'] ); ?></code></td>
						<td>
							<?php
							if ( $group['role'] && isset( $role_names[ $group['role'] ] ) ) {
								echo esc_html( translate_user_role( $role_names[ $group['role'] ] ) );
							} elseif ( $group['role'] ) {
								/* translators: %s: role slug */
								printf( '<em>%s</em>', esc_html( sprintf( __( 'Unknown role: %s', 'wp-user-groups' ), $group['role'] ) ) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<?php if ( is_multisite() ) : ?>
							<td>
								<?php
								if ( empty( $sites ) ) {
									esc_html_e( 'All sites', 'wp-user-groups' );
								} else {
									/* translators: %s: number of sites */
									echo esc_html( sprintf( _n( '%s site', '%s sites', count( $sites ), 'wp-user-groups' ), number_format_i18n( count( $sites ) ) ) );
								}
								?>
							</td>
						<?php endif; ?>
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
		$group_id = isset( $_GET['group_id'] ) ? (int) $_GET['group_id'] : 0;
		$group    = null;

		if ( 'edit' === $action ) {
			$group = WP_User_Groups::get_group( $group_id );
			if ( ! $group ) {
				wp_die( esc_html__( 'Group not found.', 'wp-user-groups' ) );
			}
		}

		$current_role  = $group ? $group['role'] : '';
		$current_sites = ( $group && is_multisite() ) ? WP_User_Groups::get_group_sites( $group['id'] ) : array();
		$back_url      = $this->page_url();
		?>
		<div class="wrap">
			<h1>
				<?php
				echo esc_html(
					$group
						/* translators: %s: group name */
						? sprintf( __( 'Edit Group: %s', 'wp-user-groups' ), $group['name'] )
						: __( 'Add New Group', 'wp-user-groups' )
				);
				?>
			</h1>

			<?php $this->render_admin_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_user_groups_save" />
				<input type="hidden" name="network_wide" value="<?php echo is_network_admin() ? '1' : '0'; ?>" />
				<?php if ( $group ) : ?>
					<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpug-name"><?php esc_html_e( 'Name', 'wp-user-groups' ); ?></label></th>
						<td>
							<input name="name" type="text" id="wpug-name" value="<?php echo esc_attr( $group ? $group['name'] : '' ); ?>" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Display name, e.g. "WordPress Meta Team".', 'wp-user-groups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpug-slug"><?php esc_html_e( 'Slug', 'wp-user-groups' ); ?></label></th>
						<td>
							<input name="slug" type="text" id="wpug-slug" value="<?php echo esc_attr( $group ? $group['slug'] : '' ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional. Auto-generated from the name if left blank.', 'wp-user-groups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpug-role"><?php esc_html_e( 'Role granted', 'wp-user-groups' ); ?></label></th>
						<td>
							<select name="role" id="wpug-role">
								<option value=""><?php esc_html_e( '— No role —', 'wp-user-groups' ); ?></option>
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
							<p class="description"><?php esc_html_e( 'Members of this group effectively gain this role wherever the group applies.', 'wp-user-groups' ); ?></p>
						</td>
					</tr>
					<?php if ( is_multisite() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sites', 'wp-user-groups' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Sites where this group applies', 'wp-user-groups' ); ?></legend>
								<label>
									<input type="radio" name="sites_scope" value="all" <?php checked( empty( $current_sites ) ); ?> />
									<?php esc_html_e( 'All sites on the network (including sites added later)', 'wp-user-groups' ); ?>
								</label><br />
								<label>
									<input type="radio" name="sites_scope" value="selected" <?php checked( ! empty( $current_sites ) ); ?> />
									<?php esc_html_e( 'Only the selected sites', 'wp-user-groups' ); ?>
								</label>
								<div style="margin-top:0.75em;max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:0.5em 1em;background:#fff;">
									<?php
									$sites = get_sites( array( 'number' => 0 ) );
									foreach ( $sites as $site ) {
										$site_id = (int) $site->blog_id;
										printf(
											'<label style="display:block;"><input type="checkbox" name="sites[]" value="%1$d"%2$s /> <strong>%3$s</strong> <span style="color:#646970;">(%4$s)</span></label>',
											$site_id,
											checked( in_array( $site_id, $current_sites, true ), true, false ),
											esc_html( $site->blogname ),
											esc_html( untrailingslashit( $site->domain . $site->path ) )
										);
									}
									?>
								</div>
								<p class="description"><?php esc_html_e( 'Pick "All sites" for a team that should reach the entire network without manual per-site setup.', 'wp-user-groups' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( $group ? __( 'Update Group', 'wp-user-groups' ) : __( 'Create Group', 'wp-user-groups' ) ); ?>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Back to groups', 'wp-user-groups' ); ?></a>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	public function handle_save() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage groups.', 'wp-user-groups' ) );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug     = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$role     = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;

		if ( $group_id ) {
			$result = WP_User_Groups::update_group(
				$group_id,
				array(
					'name' => $name,
					'slug' => $slug,
					'role' => $role,
				)
			);
		} else {
			$result   = WP_User_Groups::create_group( $name, $slug, $role );
			$group_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'save-failed', $result->get_error_message() );
			return;
		}

		if ( is_multisite() && $group_id ) {
			$scope = isset( $_POST['sites_scope'] ) ? sanitize_key( wp_unslash( $_POST['sites_scope'] ) ) : 'all';
			$sites = array();
			if ( 'selected' === $scope && ! empty( $_POST['sites'] ) && is_array( $_POST['sites'] ) ) {
				$sites = array_map( 'intval', wp_unslash( $_POST['sites'] ) );
			}
			WP_User_Groups::set_group_sites( $group_id, $sites );
		}

		$this->redirect_with_notice( 'saved' );
	}

	public function handle_delete() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage groups.', 'wp-user-groups' ) );
		}

		$group_id = isset( $_GET['group_id'] ) ? (int) $_GET['group_id'] : 0;
		if ( ! $group_id || ! WP_User_Groups::delete_group( $group_id ) ) {
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

		$groups         = WP_User_Groups::get_all_groups();
		$user_group_ids = WP_User_Groups::get_user_group_ids( $user->ID );
		$role_names     = wp_roles()->get_names();
		?>
		<h2><?php esc_html_e( 'User Groups', 'wp-user-groups' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Groups', 'wp-user-groups' ); ?></th>
				<td>
					<?php wp_nonce_field( self::USER_NONCE, 'wpug_user_nonce' ); ?>
					<?php if ( empty( $groups ) ) : ?>
						<p><em><?php esc_html_e( 'No groups have been defined yet.', 'wp-user-groups' ); ?></em></p>
					<?php else : ?>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'User Groups', 'wp-user-groups' ); ?></legend>
							<?php foreach ( $groups as $group ) : ?>
								<label style="display:block;margin-bottom:0.25em;">
									<input type="checkbox" name="wpug_groups[]" value="<?php echo (int) $group['id']; ?>" <?php checked( in_array( $group['id'], $user_group_ids, true ) ); ?> />
									<strong><?php echo esc_html( $group['name'] ); ?></strong>
									<?php if ( $group['role'] && isset( $role_names[ $group['role'] ] ) ) : ?>
										<span style="color:#646970;">— <?php echo esc_html( translate_user_role( $role_names[ $group['role'] ] ) ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( "Membership grants the group's role in addition to the user's existing role.", 'wp-user-groups' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_user_field( $user_id ) {
		if ( ! $this->current_user_can_manage() ) {
			return;
		}
		if ( empty( $_POST['wpug_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpug_user_nonce'] ) ), self::USER_NONCE ) ) {
			return;
		}

		$submitted = ( isset( $_POST['wpug_groups'] ) && is_array( $_POST['wpug_groups'] ) )
			? array_map( 'intval', wp_unslash( $_POST['wpug_groups'] ) )
			: array();

		WP_User_Groups::set_user_groups( $user_id, $submitted );
	}

	/* ------------------------------------------------------------------
	 * Users list table column
	 * ---------------------------------------------------------------- */

	public function add_users_column( $columns ) {
		$columns['user_groups'] = __( 'Groups', 'wp-user-groups' );
		return $columns;
	}

	public function render_users_column( $output, $column, $user_id ) {
		if ( 'user_groups' !== $column ) {
			return $output;
		}
		$groups = WP_User_Groups::get_user_groups( $user_id );
		if ( empty( $groups ) ) {
			return '&mdash;';
		}
		return esc_html( implode( ', ', wp_list_pluck( $groups, 'name' ) ) );
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
		$detail = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : '';

		$map = array(
			'saved'         => array( 'success', __( 'Group saved.', 'wp-user-groups' ) ),
			'deleted'       => array( 'success', __( 'Group deleted.', 'wp-user-groups' ) ),
			'missing-name'  => array( 'error', __( 'A name is required.', 'wp-user-groups' ) ),
			'save-failed'   => array( 'error', __( 'The group could not be saved.', 'wp-user-groups' ) ),
			'delete-failed' => array( 'error', __( 'The group could not be deleted.', 'wp-user-groups' ) ),
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
