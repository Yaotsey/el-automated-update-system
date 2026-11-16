<?php
/**
 * Uninstall routine.
 *
 * @package EL_AAS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'el_aas_repository',
	'el_aas_branch',
	'el_aas_github_token',
	'el_aas_webhook_secret',
	'el_aas_target_type',
	'el_aas_target_slug',
	'el_aas_source_subdir',
	'el_aas_auto_deploy',
	'el_aas_health_check',
	'el_aas_notify_email',
	'el_aas_last_commit',
	'el_aas_last_deploy_time',
	'el_aas_last_status',
	'el_aas_last_message',
	'el_aas_pending_commit',
	'el_aas_deploy_lock',
	'el_aas_self_repository',
	'el_aas_self_branch',
	'el_aas_self_github_token',
	'el_aas_self_auto_update',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'el_aas_process_deployment' );
wp_clear_scheduled_hook( 'el_aas_retry_deployment' );

delete_transient( 'el_aas_self_update_check' );
delete_transient( 'el_aas_self_update_error' );

$auto_update_plugins = (array) get_option( 'auto_update_plugins', array() );
$auto_update_index   = array_search( 'el-automated-update-system/el-automated-update-system.php', $auto_update_plugins, true );

if ( false !== $auto_update_index ) {
	unset( $auto_update_plugins[ $auto_update_index ] );
	update_option( 'auto_update_plugins', array_values( $auto_update_plugins ) );
}

$upload = wp_upload_dir();
$dirs   = array(
	trailingslashit( $upload['basedir'] ) . 'el-aas-logs',
	trailingslashit( $upload['basedir'] ) . 'el-aas-backups',
	trailingslashit( $upload['basedir'] ) . 'el-aas-temp',
);

foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
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
}
