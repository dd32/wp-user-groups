<?php
/**
 * Admin UI for Access Groups.
 *
 * - Groups management page (Users → Access Groups on single site; Network
 *   Admin → Users → Access Groups on multisite).
 * - User-edit profile section with group membership checkboxes.
 * - "Groups" column on the Users list table.
 */

defined( 'ABSPATH' ) || exit;

class Access_Groups_Admin {

	const PAGE_SLUG  = 'access-groups';
	const USER_NONCE = 'access_groups_user';

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

		add_action( 'admin_post_access_groups_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_access_groups_delete', array( $this, 'handle_delete' ) );

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
	 * Nonce helpers
	 *
	 * Nonces are bound to the group ID (or `new` for creation) so a nonce
	 * minted for one group cannot be replayed against another.
	 * ---------------------------------------------------------------- */

	private static function save_nonce_action( $group_id ) {
		$group_id = (int) $group_id;
		return 'access_groups_save_' . ( $group_id ? $group_id : 'new' );
	}

	private static function delete_nonce_action( $group_id ) {
		return 'access_groups_delete_' . (int) $group_id;
	}

	/* ------------------------------------------------------------------
	 * Menu registration
	 * ---------------------------------------------------------------- */

	public function register_menu() {
		add_users_page(
			__( 'Access Groups', 'access-groups' ),
			__( 'Access Groups', 'access-groups' ),
			self::required_cap(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_network_menu() {
		add_submenu_page(
			'users.php',
			__( 'Access Groups', 'access-groups' ),
			__( 'Access Groups', 'access-groups' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'access-groups' ) );
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
		$groups   = Access_Groups::get_all_groups();
		$counts   = Access_Groups::count_members_per_group();
		$new_url  = $this->page_url( array( 'action' => 'new' ) );
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Access Groups', 'access-groups' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'access-groups' ); ?></a>
			<hr class="wp-header-end">

			<?php $this->render_admin_notices(); ?>

			<p class="description">
				<?php esc_html_e( "Create groups that grant a WordPress role to every member. Users gain the group's role in addition to any role they already hold. Remove a user from a group and the access disappears immediately.", 'access-groups' ); ?>
			</p>

			<?php if ( empty( $groups ) ) : ?>
				<p><em><?php esc_html_e( 'No groups yet. Create one to start organising user access.', 'access-groups' ); ?></em></p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'access-groups' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Slug', 'access-groups' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'access-groups' ); ?></th>
						<?php if ( is_multisite() ) : ?>
							<th scope="col"><?php esc_html_e( 'Sites', 'access-groups' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Members', 'access-groups' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$role_names = wp_roles()->get_names();
				foreach ( $groups as $group ) :
					$edit_url     = $this->page_url( array( 'action' => 'edit', 'group_id' => $group['id'] ) );
					$sites        = is_multisite() ? Access_Groups::get_group_sites( $group['id'] ) : array();
					$member_count = isset( $counts[ $group['id'] ] ) ? $counts[ $group['id'] ] : 0;
					$confirm_msg  = sprintf(
						/* translators: %s: group name */
						__( 'Delete the "%s" group? Members will lose the role it grants.', 'access-groups' ),
						$group['name']
					);
				?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $group['name'] ); ?></a></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'access-groups' ); ?></a> | </span>
								<span class="delete">
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( $confirm_msg ); ?>');">
										<input type="hidden" name="action" value="access_groups_delete" />
										<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>" />
										<input type="hidden" name="network_wide" value="<?php echo is_network_admin() ? '1' : '0'; ?>" />
										<?php wp_nonce_field( self::delete_nonce_action( $group['id'] ) ); ?>
										<button type="submit" class="button-link submitdelete"><?php esc_html_e( 'Delete', 'access-groups' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><code><?php echo esc_html( $group['slug'] ); ?></code></td>
						<td>
							<?php
							if ( $group['role'] && isset( $role_names[ $group['role'] ] ) ) {
								echo esc_html( translate_user_role( $role_names[ $group['role'] ] ) );
							} elseif ( $group['role'] ) {
								/* translators: %s: role slug */
								printf( '<em>%s</em>', esc_html( sprintf( __( 'Unknown role: %s', 'access-groups' ), $group['role'] ) ) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<?php if ( is_multisite() ) : ?>
							<td>
								<?php
								if ( empty( $sites ) ) {
									esc_html_e( 'All sites', 'access-groups' );
								} else {
									/* translators: %s: number of sites */
									echo esc_html( sprintf( _n( '%s site', '%s sites', count( $sites ), 'access-groups' ), number_format_i18n( count( $sites ) ) ) );
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
			$group = Access_Groups::get_group( $group_id );
			if ( ! $group ) {
				wp_die( esc_html__( 'Group not found.', 'access-groups' ) );
			}
		} else {
			$group_id = 0;
		}

		$current_role  = $group ? $group['role'] : '';
		$current_sites = ( $group && is_multisite() ) ? Access_Groups::get_group_sites( $group['id'] ) : array();
		$back_url      = $this->page_url();
		?>
		<div class="wrap">
			<h1>
				<?php
				echo esc_html(
					$group
						/* translators: %s: group name */
						? sprintf( __( 'Edit Access Group: %s', 'access-groups' ), $group['name'] )
						: __( 'Add New Access Group', 'access-groups' )
				);
				?>
			</h1>

			<?php $this->render_admin_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="access_groups_save" />
				<input type="hidden" name="network_wide" value="<?php echo is_network_admin() ? '1' : '0'; ?>" />
				<input type="hidden" name="group_id" value="<?php echo (int) $group_id; ?>" />
				<?php wp_nonce_field( self::save_nonce_action( $group_id ) ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ag-name"><?php esc_html_e( 'Name', 'access-groups' ); ?></label></th>
						<td>
							<input name="name" type="text" id="ag-name" value="<?php echo esc_attr( $group ? $group['name'] : '' ); ?>" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Display name, e.g. "WordPress Meta Team".', 'access-groups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ag-slug"><?php esc_html_e( 'Slug', 'access-groups' ); ?></label></th>
						<td>
							<input name="slug" type="text" id="ag-slug" value="<?php echo esc_attr( $group ? $group['slug'] : '' ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional. Auto-generated from the name if left blank. Duplicates get a numeric suffix.', 'access-groups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ag-role"><?php esc_html_e( 'Default role', 'access-groups' ); ?></label></th>
						<td>
							<?php echo self::role_select_html( 'role', 'ag-role', $current_role, __( '— No role —', 'access-groups' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<p class="description"><?php esc_html_e( 'Applied on every site the group reaches, unless a site below picks a different role.', 'access-groups' ); ?></p>
						</td>
					</tr>
					<?php if ( is_multisite() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sites', 'access-groups' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Sites where this group applies', 'access-groups' ); ?></legend>
								<label>
									<input type="radio" name="sites_scope" value="all" <?php checked( empty( $current_sites ) ); ?> />
									<?php esc_html_e( 'All sites on the network (default role everywhere, including sites added later)', 'access-groups' ); ?>
								</label><br />
								<label>
									<input type="radio" name="sites_scope" value="selected" <?php checked( ! empty( $current_sites ) ); ?> />
									<?php esc_html_e( 'Only the selected sites (override the default role per site)', 'access-groups' ); ?>
								</label>
								<div style="margin-top:0.75em;max-height:320px;overflow:auto;border:1px solid #dcdcde;padding:0.5em 1em;background:#fff;">
									<?php
									$sites = get_sites( array( 'number' => 0 ) );
									foreach ( $sites as $site ) {
										$site_id       = (int) $site->blog_id;
										$is_selected   = array_key_exists( $site_id, $current_sites );
										$site_override = $is_selected ? (string) $current_sites[ $site_id ] : '';
										?>
										<div style="display:flex;align-items:center;gap:0.5em;padding:0.2em 0;">
											<label style="flex:1;">
												<input type="checkbox" name="sites[]" value="<?php echo $site_id; ?>" <?php checked( $is_selected ); ?> />
												<strong><?php echo esc_html( $site->blogname ); ?></strong>
												<span style="color:#646970;">(<?php echo esc_html( untrailingslashit( $site->domain . $site->path ) ); ?>)</span>
											</label>
											<?php
											echo self::role_select_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												'site_role[' . $site_id . ']',
												'',
												$site_override,
												__( 'Use default role', 'access-groups' )
											);
											?>
										</div>
										<?php
									}
									?>
								</div>
								<p class="description"><?php esc_html_e( 'Pick "All sites" for a team that should reach the entire network with the default role. Switch to "Only the selected sites" to grant a different role per site (e.g. administrator on one site, editor on another).', 'access-groups' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( $group ? __( 'Update Group', 'access-groups' ) : __( 'Create Group', 'access-groups' ) ); ?>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Back to groups', 'access-groups' ); ?></a>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Form handlers
	 * ---------------------------------------------------------------- */

	public function handle_save() {
		$group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( self::save_nonce_action( $group_id ) );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage groups.', 'access-groups' ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

		if ( is_multisite() ) {
			$scope = isset( $_POST['sites_scope'] ) ? sanitize_key( wp_unslash( $_POST['sites_scope'] ) ) : 'all';
			$sites = array();
			if ( 'selected' === $scope ) {
				if ( empty( $_POST['sites'] ) || ! is_array( $_POST['sites'] ) ) {
					$this->redirect_with_notice(
						'save-failed',
						__( 'Select at least one site, or choose "All sites".', 'access-groups' ),
						$group_id
					);
					return;
				}
				$checked     = array_map( 'intval', wp_unslash( $_POST['sites'] ) );
				$site_roles  = isset( $_POST['site_role'] ) && is_array( $_POST['site_role'] )
					? wp_unslash( $_POST['site_role'] )
					: array();
				foreach ( $checked as $blog_id ) {
					if ( $blog_id <= 0 ) {
						continue;
					}
					$override          = isset( $site_roles[ $blog_id ] ) ? sanitize_key( $site_roles[ $blog_id ] ) : '';
					$sites[ $blog_id ] = $override;
				}
			}
		}

		if ( $group_id ) {
			$result = Access_Groups::update_group(
				$group_id,
				array(
					'name' => $name,
					'slug' => $slug,
					'role' => $role,
				)
			);
		} else {
			$result   = Access_Groups::create_group( $name, $slug, $role );
			$group_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'save-failed', $result->get_error_message(), $group_id );
			return;
		}

		if ( is_multisite() && $group_id ) {
			Access_Groups::set_group_sites( $group_id, $sites );
		}

		$this->redirect_with_notice( 'saved' );
	}

	public function handle_delete() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Invalid request.', 'access-groups' ), '', array( 'response' => 405 ) );
		}

		$group_id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( self::delete_nonce_action( $group_id ) );

		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage groups.', 'access-groups' ) );
		}

		if ( ! $group_id || ! Access_Groups::delete_group( $group_id ) ) {
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

		$groups         = Access_Groups::get_all_groups();
		$user_group_ids = Access_Groups::get_user_group_ids( $user->ID );
		$role_names     = wp_roles()->get_names();
		?>
		<h2><?php esc_html_e( 'Access Groups', 'access-groups' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Groups', 'access-groups' ); ?></th>
				<td>
					<?php wp_nonce_field( self::USER_NONCE, 'ag_user_nonce' ); ?>
					<?php if ( empty( $groups ) ) : ?>
						<p><em><?php esc_html_e( 'No groups have been defined yet.', 'access-groups' ); ?></em></p>
					<?php else : ?>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Access Groups', 'access-groups' ); ?></legend>
							<?php foreach ( $groups as $group ) : ?>
								<label style="display:block;margin-bottom:0.25em;">
									<input type="checkbox" name="ag_groups[]" value="<?php echo (int) $group['id']; ?>" <?php checked( in_array( $group['id'], $user_group_ids, true ) ); ?> />
									<strong><?php echo esc_html( $group['name'] ); ?></strong>
									<?php if ( $group['role'] && isset( $role_names[ $group['role'] ] ) ) : ?>
										<span style="color:#646970;">— <?php echo esc_html( translate_user_role( $role_names[ $group['role'] ] ) ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( "Membership grants the group's role in addition to the user's existing role.", 'access-groups' ); ?></p>
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
		if ( empty( $_POST['ag_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ag_user_nonce'] ) ), self::USER_NONCE ) ) {
			return;
		}

		$submitted = ( isset( $_POST['ag_groups'] ) && is_array( $_POST['ag_groups'] ) )
			? array_map( 'intval', wp_unslash( $_POST['ag_groups'] ) )
			: array();

		Access_Groups::set_user_groups( $user_id, $submitted );
	}

	/* ------------------------------------------------------------------
	 * Users list table column
	 * ---------------------------------------------------------------- */

	public function add_users_column( $columns ) {
		$columns['access_groups'] = __( 'Groups', 'access-groups' );
		return $columns;
	}

	public function render_users_column( $output, $column, $user_id ) {
		if ( 'access_groups' !== $column ) {
			return $output;
		}
		$groups = Access_Groups::get_user_groups( $user_id );
		if ( empty( $groups ) ) {
			return '&mdash;';
		}
		return esc_html( implode( ', ', wp_list_pluck( $groups, 'name' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Render a role-picker <select>. Caller interpolates the returned HTML
	 * directly — the method escapes every value it emits.
	 */
	private static function role_select_html( $name, $id, $current, $empty_label ) {
		$html  = sprintf(
			'<select name="%s"%s>',
			esc_attr( $name ),
			$id ? ' id="' . esc_attr( $id ) . '"' : ''
		);
		$html .= '<option value="">' . esc_html( $empty_label ) . '</option>';
		foreach ( wp_roles()->roles as $slug => $data ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $slug, $current, false ),
				esc_html( translate_user_role( $data['name'] ) )
			);
		}
		$html .= '</select>';
		return $html;
	}

	private function page_url( $args = array() ) {
		$base = is_network_admin()
			? network_admin_url( 'users.php' )
			: admin_url( 'users.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), $base );
	}

	private function redirect_with_notice( $notice, $detail = '', $group_id = 0 ) {
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
		// On validation errors, keep the user on the edit screen they came from.
		if ( 'save-failed' === $notice && $group_id ) {
			$args['action']   = 'edit';
			$args['group_id'] = (int) $group_id;
		} elseif ( 'save-failed' === $notice ) {
			$args['action'] = 'new';
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
			'saved'         => array( 'success', __( 'Group saved.', 'access-groups' ) ),
			'deleted'       => array( 'success', __( 'Group deleted.', 'access-groups' ) ),
			'missing-name'  => array( 'error', __( 'A name is required.', 'access-groups' ) ),
			'save-failed'   => array( 'error', __( 'The group could not be saved.', 'access-groups' ) ),
			'delete-failed' => array( 'error', __( 'The group could not be deleted.', 'access-groups' ) ),
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
