<?php
/**
 * Deployment engine with backup, atomic replacement, health check, and rollback.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deploys GitHub commits to a plugin or theme directory.
 *
 * The deployer is intentionally defensive: it validates configuration, locks
 * against concurrent runs, downloads the exact commit, extracts safely,
 * validates the payload, swaps directories atomically where possible, runs a
 * health check, and rolls back automatically on failure.
 *
 * @since 1.0.0
 */
class EL_AAS_Deployer {

	/**
	 * Lock option name.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'el_aas_deploy_lock';

	/**
	 * Lock lifetime in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 900;

	/**
	 * Maximum number of automatic retries for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 2;

	/**
	 * Process a deployment.
	 *
	 * @param string $repo   Repository in owner/name format.
	 * @param string $commit Commit SHA.
	 * @return true|WP_Error
	 */
	public function process( $repo, $commit ) {
		$repo   = trim( (string) $repo );
		$commit = trim( (string) $commit );

		if ( '' === $repo || '' === $commit ) {
			return new WP_Error( 'el_aas_invalid_request', __( 'Repository and commit are required.', 'el-aas' ) );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo ) ) {
			return new WP_Error( 'el_aas_invalid_repo', __( 'Repository must be in owner/name format.', 'el-aas' ) );
		}

		if ( ! preg_match( '/^[0-9a-f]{7,40}$/i', $commit ) ) {
			return new WP_Error( 'el_aas_invalid_commit', __( 'Invalid commit SHA.', 'el-aas' ) );
		}

		$last = (string) get_option( 'el_aas_last_commit', '' );
		if ( '' !== $last && hash_equals( $last, $commit ) ) {
			EL_AAS_Logger::log( sprintf( 'Deployment skipped: commit %s already deployed.', $commit ) );
			return true;
		}

		if ( ! $this->acquire_lock( $commit ) ) {
			EL_AAS_Logger::log( sprintf( 'Deployment deferred: another deployment is in progress; retrying %s in 2 minutes.', $commit ), 'warning' );
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'el_aas_process_deployment', array( $repo, $commit ) );
			return true;
		}

		try {
			$result = $this->run_deployment( $repo, $commit );

			if ( is_wp_error( $result ) ) {
				$this->handle_failure( $repo, $commit, $result );
			} else {
				$this->handle_success( $commit );
			}

			return $result;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquire the deployment lock atomically.
	 *
	 * @param string $commit Commit SHA.
	 * @return bool
	 */
	private function acquire_lock( $commit ) {
		$existing = get_option( self::LOCK_KEY );

		if ( is_array( $existing ) && isset( $existing['expires'] ) && (int) $existing['expires'] > time() ) {
			return false;
		}

		if ( false !== $existing ) {
			delete_option( self::LOCK_KEY );
		}

		return add_option(
			self::LOCK_KEY,
			array(
				'commit'  => $commit,
				'expires' => time() + self::LOCK_TTL,
			),
			'',
			false
		);
	}

	/**
	 * Release the deployment lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_KEY );
	}

	/**
	 * Run the deployment pipeline.
	 *
	 * @param string $repo   Repository in owner/name format.
	 * @param string $commit Commit SHA.
	 * @return true|WP_Error
	 */
	private function run_deployment( $repo, $commit ) {
		$target_type = (string) get_option( 'el_aas_target_type', 'plugin' );
		$target_slug = (string) get_option( 'el_aas_target_slug', '' );
		$subdir      = (string) get_option( 'el_aas_source_subdir', '' );

		if ( '' === $target_slug ) {
			return new WP_Error( 'el_aas_not_configured', __( 'Target slug is not configured.', 'el-aas' ) );
		}

		$target = $this->get_target_path( $target_type, $target_slug );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $this->is_self_target( $target ) ) {
			return new WP_Error( 'el_aas_self_update', __( 'The plugin cannot update its own directory.', 'el-aas' ) );
		}

		$temp_dir = $this->create_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$zip_file = trailingslashit( $temp_dir ) . 'source.zip';
		$extract  = trailingslashit( $temp_dir ) . 'extract';

		try {
			EL_AAS_Logger::log( sprintf( 'Downloading %s@%s from GitHub.', $repo, $commit ) );

			$api      = new EL_AAS_GitHub_API( (string) get_option( 'el_aas_github_token', '' ) );
			$download = $api->download_zipball( $repo, $commit, $zip_file );
			if ( is_wp_error( $download ) ) {
				return $download;
			}

			EL_AAS_Logger::log( 'Extracting the downloaded archive.' );

			$extracted = $this->extract_zip( $zip_file, $extract );
			if ( is_wp_error( $extracted ) ) {
				return $extracted;
			}

			$source = $this->find_source_directory( $extract, $subdir );
			if ( is_wp_error( $source ) ) {
				return $source;
			}

			$valid = $this->validate_source( $source, $target_type );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			EL_AAS_Logger::log( sprintf( 'Deploying to %s.', $target ) );

			$deploy = $this->deploy_directory( $source, $target );
			if ( is_wp_error( $deploy ) ) {
				return $deploy;
			}

			if ( get_option( 'el_aas_health_check', 1 ) ) {
				EL_AAS_Logger::log( 'Running the post-deployment health check.' );

				$health = $this->health_check();
				if ( is_wp_error( $health ) ) {
					EL_AAS_Logger::log( 'Health check failed; rolling back.', 'error' );
					$this->rollback( $deploy['backup'], $deploy['target'], $deploy['target_existed'] );
					return $health;
				}
			}

			if ( ! empty( $deploy['backup'] ) ) {
				$this->delete_directory( $deploy['backup'] );
			}

			return true;
		} finally {
			$this->delete_directory( $temp_dir );
		}
	}

	/**
	 * Handle a failed deployment.
	 *
	 * @param string   $repo   Repository in owner/name format.
	 * @param string   $commit Commit SHA.
	 * @param WP_Error $error  Failure error.
	 * @return void
	 */
	private function handle_failure( $repo, $commit, $error ) {
		EL_AAS_Logger::log( 'Deployment failed: ' . $error->get_error_message(), 'error' );
		update_option( 'el_aas_last_status', 'failed' );
		update_option( 'el_aas_last_message', $error->get_error_message() );

		$retryable = array( 'el_aas_github_api', 'el_aas_github_download' );
		$retry_key = 'el_aas_retry_' . md5( $commit );
		$retries   = (int) get_transient( $retry_key );

		if ( in_array( $error->get_error_code(), $retryable, true ) && $retries < self::MAX_RETRIES ) {
			set_transient( $retry_key, $retries + 1, HOUR_IN_SECONDS );
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'el_aas_retry_deployment', array( $repo, $commit ) );
			EL_AAS_Logger::log( sprintf( 'Deployment retry %d scheduled in 5 minutes.', $retries + 1 ), 'warning' );
			return;
		}

		delete_transient( $retry_key );
		$this->notify( $error->get_error_message() );
	}

	/**
	 * Handle a successful deployment.
	 *
	 * @param string $commit Commit SHA.
	 * @return void
	 */
	private function handle_success( $commit ) {
		delete_transient( 'el_aas_retry_' . md5( $commit ) );
		update_option( 'el_aas_last_status', 'success' );
		update_option( 'el_aas_last_message', __( 'Deployment completed successfully.', 'el-aas' ) );
		update_option( 'el_aas_last_commit', $commit );
		update_option( 'el_aas_last_deploy_time', current_time( 'mysql', true ) );

		$pending = (string) get_option( 'el_aas_pending_commit', '' );
		delete_option( 'el_aas_pending_commit' );

		EL_AAS_Logger::log( 'Deployment completed successfully.' );

		if ( '' !== $pending && ! hash_equals( $pending, $commit ) ) {
			EL_AAS_Logger::log( sprintf( 'Scheduling a follow-up deployment for pending commit %s.', $pending ) );
			wp_schedule_single_event( time() + 5, 'el_aas_process_deployment', array( (string) get_option( 'el_aas_repository', '' ), $pending ) );
		}
	}

	/**
	 * Resolve the target directory for a plugin or theme.
	 *
	 * @param string $target_type plugin or theme.
	 * @param string $target_slug Folder name.
	 * @return string|WP_Error
	 */
	private function get_target_path( $target_type, $target_slug ) {
		$target_slug = trim( (string) $target_slug );

		if ( '' === $target_slug ) {
			return new WP_Error( 'el_aas_invalid_target', __( 'Target slug is empty.', 'el-aas' ) );
		}

		if ( 'plugin' === $target_type ) {
			return trailingslashit( WP_PLUGIN_DIR ) . $target_slug;
		}

		if ( 'theme' === $target_type ) {
			return trailingslashit( get_theme_root() ) . $target_slug;
		}

		return new WP_Error( 'el_aas_invalid_target', __( 'Invalid target type.', 'el-aas' ) );
	}

	/**
	 * Create a unique temporary directory inside uploads.
	 *
	 * @return string|WP_Error
	 */
	private function create_temp_dir() {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'el-aas-temp';

		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'el_aas_temp_failed', __( 'Could not create the temporary directory.', 'el-aas' ) );
		}

		$dir = $base . '/deploy-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'el_aas_temp_failed', __( 'Could not create the deployment directory.', 'el-aas' ) );
		}

		return $dir;
	}

	/**
	 * Create a backup path outside the plugin or theme directory.
	 *
	 * @param string $target Target directory.
	 * @return string|WP_Error
	 */
	private function get_backup_path( $target ) {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'el-aas-backups';

		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'el_aas_backup_failed', __( 'Could not create the backup directory.', 'el-aas' ) );
		}

		return $base . '/' . basename( $target ) . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * Check whether the target is the plugin's own directory.
	 *
	 * @param string $target Target directory.
	 * @return bool
	 */
	private function is_self_target( $target ) {
		$self   = untrailingslashit( wp_normalize_path( EL_AAS_DIR ) );
		$target = untrailingslashit( wp_normalize_path( $target ) );

		return $self === $target;
	}

	/**
	 * Extract a zip archive after validating every entry path.
	 *
	 * @param string $zip_file    Archive path.
	 * @param string $destination Destination directory.
	 * @return true|WP_Error
	 */
	private function extract_zip( $zip_file, $destination ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'el_aas_no_zip', __( 'The PHP ZipArchive extension is required but is not available.', 'el-aas' ) );
		}

		if ( ! file_exists( $zip_file ) ) {
			return new WP_Error( 'el_aas_zip_missing', __( 'The downloaded archive is missing.', 'el-aas' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_file ) ) {
			return new WP_Error( 'el_aas_zip_open', __( 'Could not open the downloaded archive.', 'el-aas' ) );
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			$zip->close();
			return new WP_Error( 'el_aas_temp_failed', __( 'Could not create the extraction directory.', 'el-aas' ) );
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );
			if ( false === $name ) {
				continue;
			}

			$name = str_replace( '\\', '/', $name );
			if ( '' === $name || '/' === substr( $name, 0, 1 ) || false !== strpos( $name, '..' ) ) {
				$zip->close();
				$this->delete_directory( $destination );
				return new WP_Error( 'el_aas_zip_unsafe', __( 'The archive contains unsafe file paths.', 'el-aas' ) );
			}
		}

		$extracted = $zip->extractTo( $destination );
		$zip->close();

		if ( ! $extracted ) {
			$this->delete_directory( $destination );
			return new WP_Error( 'el_aas_zip_extract', __( 'Could not extract the downloaded archive.', 'el-aas' ) );
		}

		return true;
	}

	/**
	 * Locate the deployable source directory inside the extracted archive.
	 *
	 * GitHub zipballs wrap everything in a single top-level directory. The
	 * optional subdirectory setting lets the administrator deploy only a
	 * nested folder from that repository.
	 *
	 * @param string $extract_dir Extraction directory.
	 * @param string $subdir      Optional subdirectory.
	 * @return string|WP_Error
	 */
	private function find_source_directory( $extract_dir, $subdir ) {
		$extract_dir = trailingslashit( $extract_dir );
		$entries     = scandir( $extract_dir );

		if ( false === $entries ) {
			return new WP_Error( 'el_aas_extract_failed', __( 'Could not read the extraction directory.', 'el-aas' ) );
		}

		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		if ( empty( $entries ) ) {
			return new WP_Error( 'el_aas_extract_empty', __( 'The archive is empty.', 'el-aas' ) );
		}

		$source = $extract_dir;
		if ( 1 === count( $entries ) && is_dir( $extract_dir . $entries[0] ) ) {
			$source = trailingslashit( $extract_dir . $entries[0] );
		}

		$subdir = trim( str_replace( '\\', '/', (string) $subdir ), '/' );
		if ( '' !== $subdir ) {
			if ( false !== strpos( $subdir, '..' ) ) {
				return new WP_Error( 'el_aas_invalid_subdir', __( 'The source subdirectory is invalid.', 'el-aas' ) );
			}

			$candidate = trailingslashit( $source . $subdir );
			if ( ! is_dir( $candidate ) ) {
				return new WP_Error(
					'el_aas_subdir_missing',
					sprintf(
						/* translators: %s: source subdirectory. */
						__( 'The source subdirectory "%s" was not found in the archive.', 'el-aas' ),
						$subdir
					)
				);
			}

			$source = $candidate;
		}

		return untrailingslashit( $source );
	}

	/**
	 * Validate that the source looks like a plugin or theme.
	 *
	 * @param string $source      Source directory.
	 * @param string $target_type plugin or theme.
	 * @return true|WP_Error
	 */
	private function validate_source( $source, $target_type ) {
		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'el_aas_source_missing', __( 'The source directory does not exist.', 'el-aas' ) );
		}

		if ( 'plugin' === $target_type ) {
			$files = glob( trailingslashit( $source ) . '*.php' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$header = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( false !== $header && preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $header ) ) {
						return true;
					}
				}
			}

			return new WP_Error( 'el_aas_invalid_plugin', __( 'No plugin header was found in the source directory.', 'el-aas' ) );
		}

		if ( 'theme' === $target_type ) {
			$style = trailingslashit( $source ) . 'style.css';
			if ( ! file_exists( $style ) ) {
				return new WP_Error( 'el_aas_invalid_theme', __( 'style.css was not found in the theme source directory.', 'el-aas' ) );
			}

			$header = file_get_contents( $style, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $header || ! preg_match( '/^[ \t\/*#@]*Theme Name:/mi', $header ) ) {
				return new WP_Error( 'el_aas_invalid_theme', __( 'The theme header was not found in style.css.', 'el-aas' ) );
			}

			return true;
		}

		return new WP_Error( 'el_aas_invalid_target', __( 'Invalid target type.', 'el-aas' ) );
	}

	/**
	 * Replace the target directory with the source directory.
	 *
	 * A copy of the current target is kept in the uploads directory so the
	 * previous version can be restored if the replacement or health check
	 * fails.
	 *
	 * @param string $source Source directory.
	 * @param string $target Target directory.
	 * @return array|WP_Error
	 */
	private function deploy_directory( $source, $target ) {
		$target_exists = is_dir( $target );
		$backup        = '';

		if ( $target_exists ) {
			$backup = $this->get_backup_path( $target );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}

			$copied = $this->copy_directory( $target, $backup );
			if ( is_wp_error( $copied ) ) {
				$this->delete_directory( $backup );
				return $copied;
			}
		}

		$swap = $this->swap_directories( $source, $target, $target_exists );
		if ( is_wp_error( $swap ) ) {
			if ( $target_exists && '' !== $backup ) {
				$this->rollback( $backup, $target, true );
			}
			return $swap;
		}

		return array(
			'backup'         => $backup,
			'target'         => $target,
			'target_existed' => $target_exists,
		);
	}

	/**
	 * Swap the source into the target directory.
	 *
	 * A rename is attempted first because it is atomic on the same
	 * filesystem. When the rename is not possible (for example on Windows
	 * or when uploads and plugins live on different mounts) the replacement
	 * falls back to a copy-based update.
	 *
	 * @param string $source        Source directory.
	 * @param string $target        Target directory.
	 * @param bool   $target_exists Whether the target already exists.
	 * @return true|WP_Error
	 */
	private function swap_directories( $source, $target, $target_exists ) {
		if ( ! $target_exists ) {
			if ( @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}

			$copied = $this->copy_directory( $source, $target );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}

			$this->delete_directory( $source );
			return true;
		}

		$old = $target . '.el-aas-old-' . wp_generate_password( 6, false, false );

		if ( ! @rename( $target, $old ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $this->replace_by_copy( $source, $target );
		}

		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rename( $old, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'el_aas_deploy_failed', __( 'Could not move the new files into place.', 'el-aas' ) );
		}

		$this->delete_directory( $old );
		return true;
	}

	/**
	 * Replace the target contents using a copy when a rename is not possible.
	 *
	 * @param string $source Source directory.
	 * @param string $target Target directory.
	 * @return true|WP_Error
	 */
	private function replace_by_copy( $source, $target ) {
		$this->delete_directory_contents( $target );

		$copied = $this->copy_directory( $source, $target );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		$this->delete_directory( $source );
		return true;
	}

	/**
	 * Restore the previous version from a backup.
	 *
	 * @param string $backup         Backup directory.
	 * @param string $target         Target directory.
	 * @param bool   $target_existed Whether the target existed before deployment.
	 * @return true|WP_Error
	 */
	private function rollback( $backup, $target, $target_existed ) {
		EL_AAS_Logger::log( 'Rolling back to the previous version.', 'warning' );

		if ( $target_existed && '' !== $backup && is_dir( $backup ) ) {
			$this->delete_directory( $target );

			$copied = $this->copy_directory( $backup, $target );
			if ( is_wp_error( $copied ) ) {
				EL_AAS_Logger::log( 'Rollback failed: ' . $copied->get_error_message(), 'error' );
				return $copied;
			}

			EL_AAS_Logger::log( 'Rollback completed.' );
			return true;
		}

		if ( ! $target_existed ) {
			$this->delete_directory( $target );
			EL_AAS_Logger::log( 'Removed the newly deployed directory during rollback.' );
			return true;
		}

		EL_AAS_Logger::log( 'Rollback skipped: no backup was available.', 'error' );
		return new WP_Error( 'el_aas_rollback_failed', __( 'No backup was available for rollback.', 'el-aas' ) );
	}

	/**
	 * Request the site home page to confirm the deployment did not break it.
	 *
	 * @return true|WP_Error
	 */
	private function health_check() {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'el_aas_sslverify', true ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'EL-Automated-Update-System/' . EL_AAS_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'el_aas_health_check',
				sprintf(
					/* translators: %s: error message. */
					__( 'The health check request failed: %s', 'el-aas' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'el_aas_health_check',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The health check returned HTTP %d.', 'el-aas' ),
					$code
				)
			);
		}

		return true;
	}

	/**
	 * Send a failure notification email when one is configured.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	private function notify( $message ) {
		$email = sanitize_email( (string) get_option( 'el_aas_notify_email', '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Automated deployment failed', 'el-aas' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = implode(
			"\n",
			array(
				__( 'The automated deployment failed.', 'el-aas' ),
				'',
				sprintf( '%s: %s', __( 'Site', 'el-aas' ), home_url( '/' ) ),
				sprintf( '%s: %s', __( 'Repository', 'el-aas' ), (string) get_option( 'el_aas_repository', '' ) ),
				sprintf( '%s: %s', __( 'Branch', 'el-aas' ), (string) get_option( 'el_aas_branch', 'main' ) ),
				'',
				sprintf( '%s: %s', __( 'Error', 'el-aas' ), $message ),
				'',
				sprintf( '%s: %s', __( 'Log file', 'el-aas' ), EL_AAS_Logger::get_log_file() ),
			)
		);

		wp_mail( $email, $subject, $body );
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source Source directory.
	 * @param string $dest   Destination directory.
	 * @return true|WP_Error
	 */
	private function copy_directory( $source, $dest ) {
		$source = untrailingslashit( $source );
		$dest   = untrailingslashit( $dest );

		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'el_aas_copy_failed', __( 'The source directory does not exist.', 'el-aas' ) );
		}

		if ( ! wp_mkdir_p( $dest ) ) {
			return new WP_Error( 'el_aas_copy_failed', __( 'Could not create the destination directory.', 'el-aas' ) );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
			$path     = $dest . DIRECTORY_SEPARATOR . $relative;

			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $path ) ) {
					return new WP_Error(
						'el_aas_copy_failed',
						sprintf(
							/* translators: %s: relative path. */
							__( 'Could not create the directory %s.', 'el-aas' ),
							$relative
						)
					);
				}
				continue;
			}

			if ( ! @copy( $item->getPathname(), $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error(
					'el_aas_copy_failed',
					sprintf(
						/* translators: %s: relative path. */
						__( 'Could not copy the file %s.', 'el-aas' ),
						$relative
					)
				);
			}
		}

		return true;
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function delete_directory( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return true;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	/**
	 * Delete the contents of a directory without removing the directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function delete_directory_contents( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return true;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return true;
		}

		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return true;
	}
}
