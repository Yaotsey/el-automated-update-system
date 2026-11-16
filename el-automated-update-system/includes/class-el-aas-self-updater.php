<?php
/**
 * WordPress-native self-update integration for the updater plugin.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrates the updater plugin with the WordPress plugin update system so it
 * can update itself from its private GitHub repository.
 *
 * @since 1.1.0
 */
class EL_AAS_Self_Updater {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	const SLUG = 'el-automated-update-system';

	/**
	 * Cache transient name.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'el_aas_self_update_check';

	/**
	 * Cache lifetime in seconds (6 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 21600;

	/**
	 * Failure cache transient name.
	 *
	 * @var string
	 */
	const FAIL_KEY = 'el_aas_self_update_error';

	/**
	 * Failure cache lifetime in seconds (15 minutes).
	 *
	 * @var int
	 */
	const FAIL_TTL = 900;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'source_selection' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Get the self-update repository.
	 *
	 * @return string
	 */
	private function get_repo() {
		$repo = (string) get_option( 'el_aas_self_repository', 'Yaotsey/el-automated-update-system' );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * Get the self-update token.
	 *
	 * @return string
	 */
	private function get_token() {
		return (string) get_option( 'el_aas_self_github_token', '' );
	}

	/**
	 * Fetch the latest version data from GitHub, cached for six hours.
	 *
	 * @return array|WP_Error
	 */
	private function get_latest() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$failure = get_transient( self::FAIL_KEY );
		if ( is_array( $failure ) && isset( $failure['message'] ) ) {
			return new WP_Error( 'el_aas_self_cached', (string) $failure['message'] );
		}

		$repo = $this->get_repo();
		if ( '' === $repo ) {
			return new WP_Error( 'el_aas_self_no_repo', __( 'The self-update repository is not configured.', 'el-aas' ) );
		}

		// The token is optional. A public repository needs no credentials,
		// and the GitHub API client only sends an Authorization header when a
		// token is actually present. A private repository still needs a token.
		$token = $this->get_token();

		$api  = new EL_AAS_GitHub_API( $token );
		$data = $api->get_latest_release( $repo );

		if ( is_wp_error( $data ) ) {
			$tag = $api->get_latest_tag( $repo );
			if ( is_wp_error( $tag ) ) {
				return $this->remember_failure( $tag );
			}

			$data = array(
				'tag'   => $tag,
				'notes' => '',
				'date'  => '',
			);
		}

		// Keep the original tag as the download reference. GitHub resolves
		// "v1.2.3" but not "1.2.3", so the prefix must not be stripped from
		// the ref even though versions are compared without it.
		$ref     = isset( $data['tag'] ) ? (string) $data['tag'] : '';
		$version = ltrim( $ref, 'vV' );

		if ( '' === $version ) {
			return $this->remember_failure(
				new WP_Error( 'el_aas_self_no_tag', __( 'The self-update repository has no release tag.', 'el-aas' ) )
			);
		}

		$latest = array(
			'version' => $version,
			'ref'     => $ref,
			'package' => sprintf( 'https://api.github.com/repos/%s/zipball/%s', $repo, rawurlencode( $ref ) ),
			'notes'   => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'date'    => isset( $data['date'] ) ? (string) $data['date'] : '',
			'repo'    => $repo,
		);

		delete_transient( self::FAIL_KEY );
		set_transient( self::CACHE_KEY, $latest, self::CACHE_TTL );

		return $latest;
	}

	/**
	 * Cache a failed lookup so repeated checks do not hammer GitHub.
	 *
	 * @param WP_Error $error Error to cache.
	 * @return WP_Error
	 */
	private function remember_failure( $error ) {
		if ( $error instanceof WP_Error ) {
			set_transient(
				self::FAIL_KEY,
				array( 'message' => $error->get_error_message() ),
				self::FAIL_TTL
			);
		}

		return $error;
	}

	/**
	 * Expose the latest available version for the admin status field.
	 *
	 * @return string|WP_Error Version string or an error.
	 */
	public function get_latest_version() {
		$latest = $this->get_latest();

		if ( is_wp_error( $latest ) ) {
			return $latest;
		}

		return isset( $latest['version'] ) ? (string) $latest['version'] : '';
	}

	/**
	 * Inject the update into the plugins transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$latest = $this->get_latest();
		if ( is_wp_error( $latest ) ) {
			return $transient;
		}

		if ( version_compare( $latest['version'], EL_AAS_VERSION, '>' ) ) {
			$transient->response[ EL_AAS_BASENAME ] = (object) array(
				'id'            => 'github.com/' . $latest['repo'],
				'slug'          => self::SLUG,
				'plugin'        => EL_AAS_BASENAME,
				'new_version'   => $latest['version'],
				'url'           => 'https://github.com/' . $latest['repo'],
				'package'       => $latest['package'],
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new stdClass(),
			);

			unset( $transient->no_update[ EL_AAS_BASENAME ] );
		} else {
			$transient->no_update[ EL_AAS_BASENAME ] = (object) array(
				'id'          => 'github.com/' . $latest['repo'],
				'slug'        => self::SLUG,
				'plugin'      => EL_AAS_BASENAME,
				'new_version' => EL_AAS_VERSION,
				'url'         => 'https://github.com/' . $latest['repo'],
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the details modal.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$latest = $this->get_latest();
		if ( is_wp_error( $latest ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'EL Automated Update System',
			'slug'          => self::SLUG,
			'version'       => $latest['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( $latest['repo'] ) . '">EL</a>',
			'homepage'      => 'https://github.com/' . $latest['repo'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'download_link' => $latest['package'],
			'last_updated'  => $latest['date'],
			'sections'      => array(
				'description' => __( 'Automatically deploys GitHub pushes to a WordPress plugin or theme with backups, atomic replacement, health checks, and rollback.', 'el-aas' ),
				'changelog'   => nl2br( esc_html( $latest['notes'] ) ),
			),
		);
	}

	/**
	 * Rename the extracted folder to the plugin slug.
	 *
	 * @param string $source        Source path.
	 * @param string $remote_source Remote source path.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra hook arguments.
	 * @return string|WP_Error
	 */
	public function source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['plugin'] ) || EL_AAS_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$source = trailingslashit( $source );

		// A GitHub zipball wraps the repository in a single top-level folder.
		// This repository keeps the plugin in the "el-automated-update-system"
		// subfolder, so descend into it before the final rename. Without this
		// step WordPress would install the repository root and report that no
		// valid plugin header was found.
		$nested = $source . self::SLUG . '/';
		if ( $wp_filesystem->is_dir( $nested ) && $wp_filesystem->exists( $nested . self::SLUG . '.php' ) ) {
			$source = $nested;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG;

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return new WP_Error( 'el_aas_self_source', __( 'Could not prepare the downloaded plugin folder.', 'el-aas' ) );
	}

	/**
	 * Download the private package with the bearer token.
	 *
	 * @param false|string $reply    Reply.
	 * @param string       $package  Package URL.
	 * @param object       $upgrader Upgrader instance.
	 * @return false|string|WP_Error
	 */
	public function pre_download( $reply, $package, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}

		$repo = $this->get_repo();
		if ( '' === $repo || ! is_string( $package ) || false === strpos( $package, '/repos/' . $repo . '/' ) ) {
			return $reply;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			// Public repository: hand the download back to WordPress so it
			// fetches the release package without a bearer token.
			return $reply;
		}

		$ref = '';
		if ( preg_match( '#/zipball/([^/?]+)#', $package, $matches ) ) {
			$ref = rawurldecode( $matches[1] );
		}

		if ( '' === $ref ) {
			return new WP_Error( 'el_aas_self_package', __( 'The self-update package URL is invalid.', 'el-aas' ) );
		}

		$tmp = wp_tempnam( 'el-aas-self-update.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'el_aas_self_temp', __( 'Could not create a temporary file for the self-update.', 'el-aas' ) );
		}

		$api    = new EL_AAS_GitHub_API( $token );
		$result = $api->download_zipball( $repo, $ref, $tmp );

		if ( is_wp_error( $result ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return $result;
		}

		return $tmp;
	}

	/**
	 * Enable automatic updates for this plugin when the toggle is on.
	 *
	 * @param bool   $update Whether to update.
	 * @param object $item   Update item.
	 * @return bool
	 */
	public function auto_update( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->plugin ) || EL_AAS_BASENAME !== $item->plugin ) {
			return $update;
		}

		return (bool) get_option( 'el_aas_self_auto_update', 1 );
	}

	/**
	 * Clear the cached version after an update.
	 *
	 * @param object $upgrader   Upgrader instance.
	 * @param array  $hook_extra Extra hook arguments.
	 * @return void
	 */
	public function clear_cache( $upgrader, $hook_extra ) {
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && EL_AAS_BASENAME === $hook_extra['plugin'] ) {
			delete_transient( self::CACHE_KEY );
			delete_transient( self::FAIL_KEY );
		}
	}
}
