<?php
/**
 * Admin settings page, sanitization, and AJAX handlers.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings handler.
 *
 * @since 1.0.0
 */
class EL_AAS_Settings {

	/**
	 * Option group name.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'el_aas_settings';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'el-aas';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_el_aas_manual_deploy', array( $this, 'ajax_manual_deploy' ) );
		add_action( 'wp_ajax_el_aas_generate_secret', array( $this, 'ajax_generate_secret' ) );
		add_action( 'wp_ajax_el_aas_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_el_aas_refresh_log', array( $this, 'ajax_refresh_log' ) );
		add_action( 'wp_ajax_el_aas_check_self_update', array( $this, 'ajax_check_self_update' ) );
		add_filter( 'plugin_action_links_' . EL_AAS_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the admin menu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'EL Automated Update System', 'el-aas' ),
			__( 'EL Auto Update', 'el-aas' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update',
			80
		);
	}

	/**
	 * Add a settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'el-aas' )
		);
		return $links;
	}

	/**
	 * Register settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( self::OPTION_GROUP, 'el_aas_repository', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_repository' ), 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_branch', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_branch' ), 'default' => 'main' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_github_token', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_webhook_secret', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_secret' ), 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_target_type', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_target_type' ), 'default' => 'plugin' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_target_slug', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_slug' ), 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_source_subdir', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_subdir' ), 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_auto_deploy', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 1 ) );
		register_setting( self::OPTION_GROUP, 'el_aas_health_check', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 1 ) );
		register_setting( self::OPTION_GROUP, 'el_aas_notify_email', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_self_repository', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_repository' ), 'default' => 'Yaotsey/el-automated-update-system' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_self_branch', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_branch' ), 'default' => 'main' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_self_github_token', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( self::OPTION_GROUP, 'el_aas_self_auto_update', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 1 ) );

		add_settings_section( 'el_aas_section_github', __( 'GitHub Connection', 'el-aas' ), array( $this, 'section_github_intro' ), self::PAGE_SLUG );
		add_settings_section( 'el_aas_section_target', __( 'Deployment Target', 'el-aas' ), array( $this, 'section_target_intro' ), self::PAGE_SLUG );
		add_settings_section( 'el_aas_section_deploy', __( 'Deployment Behavior', 'el-aas' ), array( $this, 'section_deploy_intro' ), self::PAGE_SLUG );
		add_settings_section( 'el_aas_section_self_update', __( 'Updater Self-Update', 'el-aas' ), array( $this, 'section_self_update_intro' ), self::PAGE_SLUG );
		add_settings_section( 'el_aas_section_status', __( 'Status and Manual Deployment', 'el-aas' ), array( $this, 'section_status_intro' ), self::PAGE_SLUG );
		add_settings_section( 'el_aas_section_logs', __( 'Logs', 'el-aas' ), array( $this, 'section_logs_intro' ), self::PAGE_SLUG );

		add_settings_field( 'el_aas_repository', __( 'Repository', 'el-aas' ), array( $this, 'field_repository' ), self::PAGE_SLUG, 'el_aas_section_github' );
		add_settings_field( 'el_aas_branch', __( 'Branch', 'el-aas' ), array( $this, 'field_branch' ), self::PAGE_SLUG, 'el_aas_section_github' );
		add_settings_field( 'el_aas_github_token', __( 'GitHub Token', 'el-aas' ), array( $this, 'field_token' ), self::PAGE_SLUG, 'el_aas_section_github' );
		add_settings_field( 'el_aas_webhook_secret', __( 'Webhook Secret', 'el-aas' ), array( $this, 'field_webhook_secret' ), self::PAGE_SLUG, 'el_aas_section_github' );

		add_settings_field( 'el_aas_target_type', __( 'Target Type', 'el-aas' ), array( $this, 'field_target_type' ), self::PAGE_SLUG, 'el_aas_section_target' );
		add_settings_field( 'el_aas_target_slug', __( 'Target Slug', 'el-aas' ), array( $this, 'field_target_slug' ), self::PAGE_SLUG, 'el_aas_section_target' );
		add_settings_field( 'el_aas_source_subdir', __( 'Source Subdirectory', 'el-aas' ), array( $this, 'field_source_subdir' ), self::PAGE_SLUG, 'el_aas_section_target' );

		add_settings_field( 'el_aas_auto_deploy', __( 'Auto Deploy', 'el-aas' ), array( $this, 'field_auto_deploy' ), self::PAGE_SLUG, 'el_aas_section_deploy' );
		add_settings_field( 'el_aas_health_check', __( 'Health Check', 'el-aas' ), array( $this, 'field_health_check' ), self::PAGE_SLUG, 'el_aas_section_deploy' );
		add_settings_field( 'el_aas_notify_email', __( 'Failure Email', 'el-aas' ), array( $this, 'field_notify_email' ), self::PAGE_SLUG, 'el_aas_section_deploy' );

		add_settings_field( 'el_aas_self_repository', __( 'Self-Update Repository', 'el-aas' ), array( $this, 'field_self_repository' ), self::PAGE_SLUG, 'el_aas_section_self_update' );
		add_settings_field( 'el_aas_self_branch', __( 'Self-Update Branch', 'el-aas' ), array( $this, 'field_self_branch' ), self::PAGE_SLUG, 'el_aas_section_self_update' );
		add_settings_field( 'el_aas_self_github_token', __( 'Self-Update Token', 'el-aas' ), array( $this, 'field_self_token' ), self::PAGE_SLUG, 'el_aas_section_self_update' );
		add_settings_field( 'el_aas_self_auto_update', __( 'Automatic Self-Updates', 'el-aas' ), array( $this, 'field_self_auto_update' ), self::PAGE_SLUG, 'el_aas_section_self_update' );
		add_settings_field( 'el_aas_self_status', __( 'Self-Update Status', 'el-aas' ), array( $this, 'field_self_status' ), self::PAGE_SLUG, 'el_aas_section_self_update' );

		add_settings_field( 'el_aas_status', __( 'Current Status', 'el-aas' ), array( $this, 'field_status' ), self::PAGE_SLUG, 'el_aas_section_status' );
		add_settings_field( 'el_aas_logs', __( 'Deployment Log', 'el-aas' ), array( $this, 'field_logs' ), self::PAGE_SLUG, 'el_aas_section_logs' );
	}


	/**
	 * GitHub section intro.
	 *
	 * @return void
	 */
	public function section_github_intro() {
		echo '<p>' . esc_html__( 'Connect this site to a GitHub repository. Use a fine-grained personal access token for private repositories. The webhook secret must match the secret configured in the GitHub webhook.', 'el-aas' ) . '</p>';
	}

	/**
	 * Target section intro.
	 *
	 * @return void
	 */
	public function section_target_intro() {
		echo '<p>' . esc_html__( 'Choose whether the repository contains a WordPress plugin or theme. The target slug must match the existing folder name exactly.', 'el-aas' ) . '</p>';
	}

	/**
	 * Deployment behavior section intro.
	 *
	 * @return void
	 */
	public function section_deploy_intro() {
		echo '<p>' . esc_html__( 'Control when deployments run and how failures are handled.', 'el-aas' ) . '</p>';
	}

	/**
	 * Self-update section intro.
	 *
	 * @return void
	 */
	public function section_self_update_intro() {
		echo '<p>' . esc_html__( 'The updater can update itself from its own private GitHub repository through the WordPress plugin update system. Use a fine-grained token with read access to that repository.', 'el-aas' ) . '</p>';
	}

	/**
	 * Status section intro.
	 *
	 * @return void
	 */
	public function section_status_intro() {
		echo '<p>' . esc_html__( 'Copy the webhook URL into GitHub and use the button below to deploy the latest commit manually.', 'el-aas' ) . '</p>';
	}

	/**
	 * Logs section intro.
	 *
	 * @return void
	 */
	public function section_logs_intro() {
		echo '<p>' . esc_html__( 'The log records every webhook, download, deployment, health check, and rollback event. Secrets are redacted automatically.', 'el-aas' ) . '</p>';
	}


	/**
	 * Render the repository field.
	 *
	 * @return void
	 */
	public function field_repository() {
		$value = get_option( 'el_aas_repository', '' );
		printf(
			'<input type="text" name="el_aas_repository" id="el_aas_repository" value="%s" class="regular-text" placeholder="owner/repository" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The GitHub repository in owner/name format, for example acme/my-plugin.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the branch field.
	 *
	 * @return void
	 */
	public function field_branch() {
		$value = get_option( 'el_aas_branch', 'main' );
		printf(
			'<input type="text" name="el_aas_branch" id="el_aas_branch" value="%s" class="regular-text" placeholder="main" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The branch that triggers deployments. Push events on other branches are ignored.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the GitHub token field.
	 *
	 * @return void
	 */
	public function field_token() {
		$value = get_option( 'el_aas_github_token', '' );
		printf(
			'<input type="password" name="el_aas_github_token" id="el_aas_github_token" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Required for private repositories. The token needs read access to repository contents.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the webhook secret field.
	 *
	 * @return void
	 */
	public function field_webhook_secret() {
		$value = get_option( 'el_aas_webhook_secret', '' );
		printf(
			'<input type="text" name="el_aas_webhook_secret" id="el_aas_webhook_secret" value="%s" class="regular-text code" />',
			esc_attr( $value )
		);
		echo ' <button type="button" class="button" id="el_aas_generate_secret">' . esc_html__( 'Generate New Secret', 'el-aas' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Use this exact value as the webhook secret in GitHub. Save settings after generating a new secret.', 'el-aas' ) . '</p>';
	}


	/**
	 * Render the target type field.
	 *
	 * @return void
	 */
	public function field_target_type() {
		$value = get_option( 'el_aas_target_type', 'plugin' );
		?>
		<select name="el_aas_target_type" id="el_aas_target_type">
			<option value="plugin" <?php selected( $value, 'plugin' ); ?>><?php echo esc_html__( 'Plugin', 'el-aas' ); ?></option>
			<option value="theme" <?php selected( $value, 'theme' ); ?>><?php echo esc_html__( 'Theme', 'el-aas' ); ?></option>
		</select>
		<p class="description"><?php echo esc_html__( 'Select the WordPress component type that this repository deploys.', 'el-aas' ); ?></p>
		<?php
	}

	/**
	 * Render the target slug field.
	 *
	 * @return void
	 */
	public function field_target_slug() {
		$value = get_option( 'el_aas_target_slug', '' );
		printf(
			'<input type="text" name="el_aas_target_slug" id="el_aas_target_slug" value="%s" class="regular-text" placeholder="my-plugin" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The exact folder name of the plugin or theme to update. The plugin cannot update itself.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the source subdirectory field.
	 *
	 * @return void
	 */
	public function field_source_subdir() {
		$value = get_option( 'el_aas_source_subdir', '' );
		printf(
			'<input type="text" name="el_aas_source_subdir" id="el_aas_source_subdir" value="%s" class="regular-text" placeholder="plugin/my-plugin" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. If the deployable code is in a subfolder of the repository, enter that path. Leave blank to deploy the repository root.', 'el-aas' ) . '</p>';
	}


	/**
	 * Render the auto deploy checkbox.
	 *
	 * @return void
	 */
	public function field_auto_deploy() {
		$value = (int) get_option( 'el_aas_auto_deploy', 1 );
		?>
		<input type="hidden" name="el_aas_auto_deploy" value="0" />
		<label>
			<input type="checkbox" name="el_aas_auto_deploy" value="1" <?php checked( 1, $value ); ?> />
			<?php echo esc_html__( 'Deploy automatically when a matching push webhook arrives.', 'el-aas' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the health check checkbox.
	 *
	 * @return void
	 */
	public function field_health_check() {
		$value = (int) get_option( 'el_aas_health_check', 1 );
		?>
		<input type="hidden" name="el_aas_health_check" value="0" />
		<label>
			<input type="checkbox" name="el_aas_health_check" value="1" <?php checked( 1, $value ); ?> />
			<?php echo esc_html__( 'Request the site home page after deployment and roll back automatically if it does not return HTTP 200.', 'el-aas' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the notification email field.
	 *
	 * @return void
	 */
	public function field_notify_email() {
		$value = get_option( 'el_aas_notify_email', '' );
		printf(
			'<input type="email" name="el_aas_notify_email" id="el_aas_notify_email" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Receive an email when a deployment fails after all retries.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the self-update repository field.
	 *
	 * @return void
	 */
	public function field_self_repository() {
		$value = get_option( 'el_aas_self_repository', 'Yaotsey/el-automated-update-system' );
		printf(
			'<input type="text" name="el_aas_self_repository" id="el_aas_self_repository" value="%s" class="regular-text" placeholder="owner/repository" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The private GitHub repository that holds this updater plugin, in owner/name format.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the self-update branch field.
	 *
	 * @return void
	 */
	public function field_self_branch() {
		$value = get_option( 'el_aas_self_branch', 'main' );
		printf(
			'<input type="text" name="el_aas_self_branch" id="el_aas_self_branch" value="%s" class="regular-text" placeholder="main" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The branch used when the updater reads its latest release or tag.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the self-update token field.
	 *
	 * @return void
	 */
	public function field_self_token() {
		$value = get_option( 'el_aas_self_github_token', '' );
		printf(
			'<input type="password" name="el_aas_self_github_token" id="el_aas_self_github_token" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Required only for a private self-update repository. Use a fine-grained personal access token with Contents: Read and Metadata: Read. Leave empty for a public repository.', 'el-aas' ) . '</p>';
	}

	/**
	 * Render the automatic self-update toggle.
	 *
	 * @return void
	 */
	public function field_self_auto_update() {
		$value = (int) get_option( 'el_aas_self_auto_update', 1 );
		?>
		<input type="hidden" name="el_aas_self_auto_update" value="0" />
		<label>
			<input type="checkbox" name="el_aas_self_auto_update" id="el_aas_self_auto_update" value="1" <?php checked( 1, $value ); ?> />
			<?php echo esc_html__( 'Install new updater versions automatically without a click.', 'el-aas' ); ?>
		</label>
		<p class="description"><?php echo esc_html__( 'When disabled, WordPress shows the normal Update Now notice and you install the update yourself.', 'el-aas' ); ?></p>
		<?php
	}

	/**
	 * Render the self-update status field.
	 *
	 * @return void
	 */
	public function field_self_status() {
		$current = EL_AAS_VERSION;
		$latest  = '';

		if ( class_exists( 'EL_AAS_Self_Updater' ) ) {
			$result = EL_AAS_Plugin::instance()->self_updater->get_latest_version();

			if ( is_wp_error( $result ) ) {
				$latest = sprintf(
					/* translators: %s: error message. */
					__( 'Unavailable (%s)', 'el-aas' ),
					$result->get_error_message()
				);
			} else {
				$latest = (string) $result;
			}
		}
		?>
		<table class="el-aas-status-table">
			<tr>
				<th><?php echo esc_html__( 'Installed Version', 'el-aas' ); ?></th>
				<td><code><?php echo esc_html( $current ); ?></code></td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Latest Available', 'el-aas' ); ?></th>
				<td><code id="el_aas_self_latest"><?php echo esc_html( $latest ? $latest : 'N/A' ); ?></code></td>
			</tr>
		</table>
		<p class="el-aas-actions">
			<button type="button" class="button" id="el_aas_check_self_update"><?php echo esc_html__( 'Check for Updates Now', 'el-aas' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Render the status field.
	 *
	 * @return void
	 */
	public function field_status() {
		$webhook_url = rest_url( 'el-aas/v1/webhook' );
		$last_commit = get_option( 'el_aas_last_commit', '' );
		$last_time   = get_option( 'el_aas_last_deploy_time', '' );
		$last_status = get_option( 'el_aas_last_status', '' );
		$last_message = get_option( 'el_aas_last_message', '' );
		?>
		<table class="el-aas-status-table">
			<tr>
				<th><?php echo esc_html__( 'Webhook URL', 'el-aas' ); ?></th>
				<td>
					<code id="el_aas_webhook_url"><?php echo esc_html( $webhook_url ); ?></code>
					<button type="button" class="button button-small" id="el_aas_copy_webhook"><?php echo esc_html__( 'Copy', 'el-aas' ); ?></button>
					<p class="description"><?php echo esc_html__( 'Use this URL as the Payload URL in the GitHub webhook. Set the content type to application/json.', 'el-aas' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Last Deployed Commit', 'el-aas' ); ?></th>
				<td><code><?php echo esc_html( $last_commit ? $last_commit : 'N/A' ); ?></code></td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Last Deployment (UTC)', 'el-aas' ); ?></th>
				<td><?php echo esc_html( $last_time ? $last_time : 'N/A' ); ?></td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Last Status', 'el-aas' ); ?></th>
				<td><?php echo esc_html( $last_status ? $last_status : 'N/A' ); ?></td>
			</tr>
			<tr>
				<th><?php echo esc_html__( 'Last Message', 'el-aas' ); ?></th>
				<td><?php echo esc_html( $last_message ? $last_message : 'N/A' ); ?></td>
			</tr>
		</table>
		<p class="el-aas-actions">
			<button type="button" class="button button-primary" id="el_aas_manual_deploy"><?php echo esc_html__( 'Deploy Latest Commit Now', 'el-aas' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Render the log field.
	 *
	 * @return void
	 */
	public function field_logs() {
		$log = EL_AAS_Logger::tail( 200 );
		?>
		<textarea id="el_aas_log_output" class="large-text code el-aas-log" rows="20" readonly><?php echo esc_textarea( $log ); ?></textarea>
		<p class="el-aas-actions">
			<button type="button" class="button" id="el_aas_refresh_log"><?php echo esc_html__( 'Refresh Log', 'el-aas' ); ?></button>
			<button type="button" class="button" id="el_aas_clear_log"><?php echo esc_html__( 'Clear Log', 'el-aas' ); ?></button>
		</p>
		<?php
	}


	/**
	 * Enqueue admin assets on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'el-aas-admin', EL_AAS_URL . 'admin/css/admin.css', array(), EL_AAS_VERSION );
		wp_enqueue_script( 'el-aas-admin', EL_AAS_URL . 'admin/js/admin.js', array( 'jquery' ), EL_AAS_VERSION, true );
		wp_localize_script(
			'el-aas-admin',
			'elAas',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'el_aas_admin' ),
				'strings' => array(
					'confirmDeploy' => __( 'Deploy the latest commit now?', 'el-aas' ),
					'confirmClear'  => __( 'Clear the entire log file?', 'el-aas' ),
					'scheduled'     => __( 'Deployment scheduled. Check the log for progress.', 'el-aas' ),
					'cleared'       => __( 'Log cleared.', 'el-aas' ),
					'error'         => __( 'Request failed. Please try again.', 'el-aas' ),
					'copied'        => __( 'Webhook URL copied.', 'el-aas' ),
					'copyFailed'    => __( 'Copy failed. Select the URL manually.', 'el-aas' ),
					'checking'      => __( 'Checking...', 'el-aas' ),
					'checked'       => __( 'Update check complete.', 'el-aas' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors( self::OPTION_GROUP ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'el-aas' ) );
				?>
			</form>
			<div id="el_aas_ajax_message" class="notice" style="display:none;"></div>
		</div>
		<?php
	}


	/**
	 * AJAX handler for manual deployment.
	 *
	 * @return void
	 */
	public function ajax_manual_deploy() {
		check_ajax_referer( 'el_aas_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'el-aas' ) ), 403 );
		}

		$repo   = get_option( 'el_aas_repository', '' );
		$branch = get_option( 'el_aas_branch', 'main' );

		if ( empty( $repo ) || ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid repository configuration.', 'el-aas' ) ), 400 );
		}

		$api  = new EL_AAS_GitHub_API( get_option( 'el_aas_github_token', '' ) );
		$head = $api->get_branch_head( $repo, $branch );
		if ( is_wp_error( $head ) ) {
			wp_send_json_error( array( 'message' => $head->get_error_message() ), 500 );
		}

		$scheduled = wp_schedule_single_event( time() + 1, 'el_aas_process_deployment', array( $repo, $head['sha'] ) );
		if ( false === $scheduled && ! wp_next_scheduled( 'el_aas_process_deployment', array( $repo, $head['sha'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not schedule deployment.', 'el-aas' ) ), 500 );
		}

		spawn_cron();
		EL_AAS_Logger::log( sprintf( 'Manual deployment scheduled for %s@%s.', $repo, $head['sha'] ) );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: short commit hash. */
					__( 'Deployment scheduled for commit %s.', 'el-aas' ),
					substr( $head['sha'], 0, 7 )
				),
			)
		);
	}

	/**
	 * AJAX handler for generating a new webhook secret.
	 *
	 * @return void
	 */
	public function ajax_generate_secret() {
		check_ajax_referer( 'el_aas_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'el-aas' ) ), 403 );
		}

		wp_send_json_success( array( 'secret' => EL_AAS_Plugin::generate_secret() ) );
	}

	/**
	 * AJAX handler for clearing the log.
	 *
	 * @return void
	 */
	public function ajax_clear_log() {
		check_ajax_referer( 'el_aas_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'el-aas' ) ), 403 );
		}

		if ( EL_AAS_Logger::clear() ) {
			wp_send_json_success( array( 'message' => __( 'Log cleared.', 'el-aas' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Could not clear the log. Check file permissions.', 'el-aas' ) ), 500 );
	}

	/**
	 * AJAX handler for refreshing the log.
	 *
	 * @return void
	 */
	public function ajax_refresh_log() {
		check_ajax_referer( 'el_aas_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'el-aas' ) ), 403 );
		}

		wp_send_json_success( array( 'log' => EL_AAS_Logger::tail( 200 ) ) );
	}

	/**
	 * AJAX handler for checking for a self-update now.
	 *
	 * Clears the cached lookup, refreshes the WordPress plugin update
	 * transient, and returns the current and latest versions.
	 *
	 * @return void
	 */
	public function ajax_check_self_update() {
		check_ajax_referer( 'el_aas_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'el-aas' ) ), 403 );
		}

		// Clear both the version cache and the failure cache so the button
		// always performs a real lookup instead of replaying a cached result.
		delete_transient( EL_AAS_Self_Updater::CACHE_KEY );
		delete_transient( EL_AAS_Self_Updater::FAIL_KEY );

		// Passing true makes WordPress delete the update_plugins transient as
		// well as the plugins cache. Passing false would leave the transient
		// in place, so wp_update_plugins() would return the stale result.
		wp_clean_plugins_cache( true );

		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		wp_update_plugins();

		$latest = EL_AAS_Plugin::instance()->self_updater->get_latest_version();

		if ( is_wp_error( $latest ) ) {
			wp_send_json_error(
				array(
					'message' => $latest->get_error_message(),
					'current' => EL_AAS_VERSION,
				),
				200
			);
		}

		$available = version_compare( (string) $latest, EL_AAS_VERSION, '>' );

		wp_send_json_success(
			array(
				'current'   => EL_AAS_VERSION,
				'latest'    => (string) $latest,
				'available' => $available,
				'message'   => $available
					? sprintf(
						/* translators: %s: version number. */
						__( 'Version %s is available. WordPress will install it automatically.', 'el-aas' ),
						(string) $latest
					)
					: __( 'You are running the latest version.', 'el-aas' ),
			)
		);
	}

	/**
	 * Sanitize the repository setting.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_repository( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value ) ) {
			add_settings_error( 'el_aas_repository', 'el_aas_repository_invalid', __( 'Repository must be in owner/name format.', 'el-aas' ), 'error' );
			return get_option( 'el_aas_repository', '' );
		}

		return $value;
	}

	/**
	 * Sanitize the branch setting.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_branch( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'main';
		}

		if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $value ) || false !== strpos( $value, '..' ) ) {
			add_settings_error( 'el_aas_branch', 'el_aas_branch_invalid', __( 'Branch name contains invalid characters.', 'el-aas' ), 'error' );
			return get_option( 'el_aas_branch', 'main' );
		}

		return $value;
	}

	/**
	 * Sanitize the webhook secret.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_secret( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return EL_AAS_Plugin::generate_secret();
		}

		if ( strlen( $value ) < 16 ) {
			add_settings_error( 'el_aas_webhook_secret', 'el_aas_secret_short', __( 'Webhook secret must be at least 16 characters.', 'el-aas' ), 'error' );
			return get_option( 'el_aas_webhook_secret', '' );
		}

		return $value;
	}

	/**
	 * Sanitize the target type.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_target_type( $value ) {
		$value = (string) $value;
		if ( ! in_array( $value, array( 'plugin', 'theme' ), true ) ) {
			add_settings_error( 'el_aas_target_type', 'el_aas_target_type_invalid', __( 'Target type must be plugin or theme.', 'el-aas' ), 'error' );
			return 'plugin';
		}

		return $value;
	}

	/**
	 * Sanitize the target slug.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_slug( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '#^[A-Za-z0-9_-]+$#', $value ) ) {
			add_settings_error( 'el_aas_target_slug', 'el_aas_slug_invalid', __( 'Target slug may only contain letters, numbers, hyphens and underscores.', 'el-aas' ), 'error' );
			return get_option( 'el_aas_target_slug', '' );
		}

		return $value;
	}

	/**
	 * Sanitize the source subdirectory.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_subdir( $value ) {
		$value = trim( str_replace( '\\', '/', (string) $value ) );
		$value = trim( $value, '/' );

		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '..' ) ) {
			add_settings_error( 'el_aas_source_subdir', 'el_aas_subdir_invalid', __( 'Source subdirectory cannot contain "..".', 'el-aas' ), 'error' );
			return get_option( 'el_aas_source_subdir', '' );
		}

		return $value;
	}

	/**
	 * Sanitize a checkbox value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}
}

