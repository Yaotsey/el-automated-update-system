<?php
/**
 * Plugin Name:       EL Automated Update System
 * Plugin URI:        https://github.com/Yaotsey/el-automated-update-system
 * Description:       Automatically deploys GitHub pushes to a WordPress plugin or theme with backups, atomic replacement, health checks, and rollback.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            EL
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       el-aas
 * Domain Path:       /languages
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

define( 'EL_AAS_VERSION', '1.1.0' );
define( 'EL_AAS_FILE', __FILE__ );
define( 'EL_AAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EL_AAS_URL', plugin_dir_url( __FILE__ ) );
define( 'EL_AAS_BASENAME', plugin_basename( __FILE__ ) );

require_once EL_AAS_DIR . 'includes/class-el-aas-logger.php';
require_once EL_AAS_DIR . 'includes/class-el-aas-settings.php';
require_once EL_AAS_DIR . 'includes/class-el-aas-github-api.php';
require_once EL_AAS_DIR . 'includes/class-el-aas-deployer.php';
require_once EL_AAS_DIR . 'includes/class-el-aas-webhook.php';
require_once EL_AAS_DIR . 'includes/class-el-aas-self-updater.php';

/**
 * Main plugin bootstrap.
 *
 * @since 1.0.0
 */
final class EL_AAS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var EL_AAS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var EL_AAS_Settings
	 */
	public $settings;

	/**
	 * Webhook handler.
	 *
	 * @var EL_AAS_Webhook
	 */
	public $webhook;

	/**
	 * Self-update handler.
	 *
	 * @var EL_AAS_Self_Updater
	 */
	public $self_updater;

	/**
	 * Get the singleton instance.
	 *
	 * @return EL_AAS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'el_aas_process_deployment', array( $this, 'process_deployment' ), 10, 2 );
		add_action( 'el_aas_retry_deployment', array( $this, 'process_deployment' ), 10, 2 );

		// Keep the WordPress auto_update_plugins list in step with the
		// self-update toggle whenever the setting is saved, not only on
		// activation.
		add_action( 'update_option_el_aas_self_auto_update', array( __CLASS__, 'sync_auto_update_option' ) );
		add_action( 'add_option_el_aas_self_auto_update', array( __CLASS__, 'sync_auto_update_option' ) );

		register_activation_hook( EL_AAS_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( EL_AAS_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'el-aas', false, dirname( EL_AAS_BASENAME ) . '/languages' );
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings     = new EL_AAS_Settings();
		$this->webhook      = new EL_AAS_Webhook();
		$this->self_updater = new EL_AAS_Self_Updater();
	}

	/**
	 * Handle a scheduled deployment.
	 *
	 * @param string $repo   Repository in owner/name format.
	 * @param string $commit Commit hash or ref.
	 * @return void
	 */
	public function process_deployment( $repo, $commit ) {
		$deployer = new EL_AAS_Deployer();
		$deployer->process( $repo, $commit );
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		$defaults = array(
			'el_aas_branch'            => 'main',
			'el_aas_target_type'       => 'plugin',
			'el_aas_auto_deploy'       => 1,
			'el_aas_health_check'      => 1,
			'el_aas_notify_email'      => get_option( 'admin_email' ),
			'el_aas_source_subdir'     => '',
			'el_aas_self_repository'   => 'Yaotsey/el-automated-update-system',
			'el_aas_self_branch'       => 'main',
			'el_aas_self_auto_update'  => 1,
			'el_aas_self_github_token' => '',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				update_option( $key, $value );
			}
		}

		if ( ! get_option( 'el_aas_webhook_secret' ) ) {
			update_option( 'el_aas_webhook_secret', self::generate_secret() );
		}

		self::sync_auto_update_option();

		EL_AAS_Logger::log( 'Plugin activated.' );
	}

	/**
	 * Deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'el_aas_deploy_lock' );
		delete_option( 'el_aas_pending_commit' );
		wp_clear_scheduled_hook( 'el_aas_process_deployment' );
		wp_clear_scheduled_hook( 'el_aas_retry_deployment' );
		EL_AAS_Logger::log( 'Plugin deactivated.' );
	}

	/**
	 * Generate a cryptographically secure webhook secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( 32 ) );
		}

		return wp_generate_password( 64, false, false );
	}

	/**
	 * Add or remove this plugin from the WordPress auto-update list.
	 *
	 * @return void
	 */
	public static function sync_auto_update_option() {
		$plugins = get_option( 'auto_update_plugins', array() );
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}

		$enabled = (bool) get_option( 'el_aas_self_auto_update', 1 );
		$index   = array_search( EL_AAS_BASENAME, $plugins, true );

		if ( $enabled && false === $index ) {
			$plugins[] = EL_AAS_BASENAME;
			update_option( 'auto_update_plugins', $plugins );
		} elseif ( ! $enabled && false !== $index ) {
			unset( $plugins[ $index ] );
			update_option( 'auto_update_plugins', array_values( $plugins ) );
		}
	}

	/**
	 * Display admin notices for incomplete configuration.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = array();
		if ( ! get_option( 'el_aas_repository' ) ) {
			$missing[] = __( 'GitHub repository', 'el-aas' );
		}
		if ( ! get_option( 'el_aas_webhook_secret' ) ) {
			$missing[] = __( 'Webhook secret', 'el-aas' );
		}
		if ( ! get_option( 'el_aas_target_slug' ) ) {
			$missing[] = __( 'Target slug', 'el-aas' );
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of missing settings. */
					__( 'EL Automated Update System is missing required settings: %s.', 'el-aas' ),
					implode( ', ', $missing )
				)
			),
			esc_url( admin_url( 'admin.php?page=el-aas' ) ),
			esc_html__( 'Configure now', 'el-aas' )
		);
	}
}

EL_AAS_Plugin::instance();
