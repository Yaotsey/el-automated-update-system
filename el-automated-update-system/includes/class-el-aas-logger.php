<?php
/**
 * File-based logger with rotation and secret redaction.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger utility.
 *
 * @since 1.0.0
 */
class EL_AAS_Logger {

	/**
	 * Maximum log size in bytes (5 MB).
	 *
	 * @var int
	 */
	const MAX_SIZE = 5242880;

	/**
	 * Maximum number of rotated files to keep.
	 *
	 * @var int
	 */
	const MAX_ROTATED = 5;

	/**
	 * Get the log directory path.
	 *
	 * @return string
	 */
	public static function get_log_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'el-aas-logs';
	}

	/**
	 * Get the log file path.
	 *
	 * @return string
	 */
	public static function get_log_file() {
		return self::get_log_dir() . '/el-aas.log';
	}

	/**
	 * Write a log message.
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level: info, warning, error, debug.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		$dir = self::get_log_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$file = self::get_log_file();
		self::maybe_rotate( $file );

		$line = sprintf(
			"[%s] [%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( (string) $level ),
			self::sanitize_message( $message )
		);

		if ( is_writable( $dir ) ) {
			file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		}
	}

	/**
	 * Rotate the log when it exceeds the maximum size.
	 *
	 * @param string $file Log file path.
	 * @return void
	 */
	private static function maybe_rotate( $file ) {
		if ( ! file_exists( $file ) || filesize( $file ) < self::MAX_SIZE ) {
			return;
		}

		$rotated = $file . '.' . gmdate( 'YmdHis' );
		if ( @rename( $file, $rotated ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::prune_rotated( dirname( $file ) );
		}
	}

	/**
	 * Prune old rotated log files.
	 *
	 * @param string $dir Directory containing rotated logs.
	 * @return void
	 */
	private static function prune_rotated( $dir ) {
		$files = glob( $dir . '/el-aas.log.*' );
		if ( ! is_array( $files ) || count( $files ) <= self::MAX_ROTATED ) {
			return;
		}

		sort( $files );
		$remove = array_slice( $files, 0, count( $files ) - self::MAX_ROTATED );
		foreach ( $remove as $old ) {
			if ( is_file( $old ) ) {
				@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Sanitize a log message by removing newlines and known secrets.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function sanitize_message( $message ) {
		$message = str_replace( array( "\r", "\n" ), ' ', (string) $message );

		$secrets = array(
			get_option( 'el_aas_github_token' ),
			get_option( 'el_aas_webhook_secret' ),
			get_option( 'el_aas_self_github_token' ),
		);

		foreach ( $secrets as $secret ) {
			if ( ! empty( $secret ) && is_string( $secret ) && strlen( $secret ) > 7 ) {
				$message = str_replace( $secret, '[REDACTED]', $message );
			}
		}

		return $message;
	}

	/**
	 * Read the last N lines of the log.
	 *
	 * @param int $lines Number of lines to return.
	 * @return string
	 */
	public static function tail( $lines = 200 ) {
		$file = self::get_log_file();
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		$content = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $content ) {
			return '';
		}

		$content = array_slice( $content, -1 * absint( $lines ) );
		return implode( "\n", $content );
	}

	/**
	 * Clear the log file.
	 *
	 * @return bool
	 */
	public static function clear() {
		$file = self::get_log_file();
		if ( file_exists( $file ) && is_writable( $file ) ) {
			return false !== file_put_contents( $file, '' );
		}

		return false;
	}
}
