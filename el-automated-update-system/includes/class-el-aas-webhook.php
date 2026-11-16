<?php
/**
 * GitHub webhook receiver and asynchronous process endpoint.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook handler.
 *
 * @since 1.0.0
 */
class EL_AAS_Webhook {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'el-aas/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'el-aas/v1',
			'/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_process' ),
				'permission_callback' => array( $this, 'verify_process_token' ),
			)
		);
	}

	/**
	 * Handle an incoming GitHub webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$delivery  = (string) $request->get_header( 'X-GitHub-Delivery' );
		$event     = (string) $request->get_header( 'X-GitHub-Event' );
		$signature = (string) $request->get_header( 'X-Hub-Signature-256' );
		$secret    = (string) get_option( 'el_aas_webhook_secret', '' );
		$raw       = (string) $request->get_body();

		if ( '' === $secret ) {
			EL_AAS_Logger::log( 'Webhook rejected: no secret configured.', 'warning' );
			return new WP_Error( 'el_aas_no_secret', __( 'Webhook secret is not configured.', 'el-aas' ), array( 'status' => 500 ) );
		}

		if ( '' === $signature ) {
			EL_AAS_Logger::log( 'Webhook rejected: missing signature.', 'warning' );
			return new WP_Error( 'el_aas_missing_signature', __( 'Missing signature.', 'el-aas' ), array( 'status' => 401 ) );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			EL_AAS_Logger::log( sprintf( 'Webhook rejected: invalid signature (delivery %s).', $delivery ? $delivery : 'unknown' ), 'warning' );
			return new WP_Error( 'el_aas_invalid_signature', __( 'Invalid signature.', 'el-aas' ), array( 'status' => 401 ) );
		}

		if ( 'ping' === $event ) {
			EL_AAS_Logger::log( 'Webhook ping received.' );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'pong' ), 200 );
		}

		if ( 'push' !== $event ) {
			EL_AAS_Logger::log( sprintf( 'Webhook ignored: event %s.', $event ? $event : 'unknown' ) );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'ignored' ), 200 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			EL_AAS_Logger::log( 'Webhook rejected: invalid JSON payload.', 'warning' );
			return new WP_Error( 'el_aas_invalid_payload', __( 'Invalid JSON payload.', 'el-aas' ), array( 'status' => 400 ) );
		}

		$repo    = isset( $payload['repository']['full_name'] ) ? (string) $payload['repository']['full_name'] : '';
		$ref     = isset( $payload['ref'] ) ? (string) $payload['ref'] : '';
		$sha     = isset( $payload['after'] ) ? (string) $payload['after'] : '';
		$deleted = isset( $payload['deleted'] ) && $payload['deleted'];
		$branch  = (string) get_option( 'el_aas_branch', 'main' );

		if ( $deleted ) {
			EL_AAS_Logger::log( 'Webhook ignored: branch deletion.' );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'ignored' ), 200 );
		}

		if ( 'refs/heads/' . $branch !== $ref ) {
			EL_AAS_Logger::log( sprintf( 'Webhook ignored: ref %s does not match configured branch %s.', $ref, $branch ) );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'ignored' ), 200 );
		}

		if ( '' === $sha || ! preg_match( '/^[0-9a-f]{40}$/i', $sha ) ) {
			EL_AAS_Logger::log( 'Webhook rejected: invalid commit SHA.', 'warning' );
			return new WP_Error( 'el_aas_invalid_sha', __( 'Invalid commit SHA.', 'el-aas' ), array( 'status' => 400 ) );
		}

		$configured_repo = (string) get_option( 'el_aas_repository', '' );
		if ( '' !== $configured_repo && $repo !== $configured_repo ) {
			EL_AAS_Logger::log( sprintf( 'Webhook ignored: repository %s does not match configured %s.', $repo, $configured_repo ) );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'ignored' ), 200 );
		}

		if ( ! get_option( 'el_aas_auto_deploy', 1 ) ) {
			EL_AAS_Logger::log( 'Webhook received but auto-deploy is disabled.' );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'auto-deploy-disabled' ), 200 );
		}

		$last = (string) get_option( 'el_aas_last_commit', '' );
		if ( '' !== $last && hash_equals( $last, $sha ) ) {
			EL_AAS_Logger::log( sprintf( 'Webhook ignored: commit %s already deployed.', $sha ) );
			return new WP_REST_Response( array( 'ok' => true, 'message' => 'already-deployed' ), 200 );
		}

		update_option( 'el_aas_pending_commit', $sha, false );
		EL_AAS_Logger::log( sprintf( 'Webhook accepted for %s@%s (delivery %s).', $repo, $sha, $delivery ? $delivery : 'unknown' ) );

		wp_schedule_single_event( time() + 1, 'el_aas_process_deployment', array( $repo, $sha ) );
		spawn_cron();

		$token = hash_hmac( 'sha256', $repo . '|' . $sha, $secret );
		wp_remote_post(
			rest_url( 'el-aas/v1/process' ),
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => apply_filters( 'el_aas_sslverify', true ),
				'body'      => array(
					'repo'   => $repo,
					'commit' => $sha,
					'token'  => $token,
				),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => 'scheduled',
				'commit'  => $sha,
			),
			200
		);
	}

	/**
	 * Verify the signed token used by the internal process endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_process_token( $request ) {
		$repo   = (string) $request->get_param( 'repo' );
		$commit = (string) $request->get_param( 'commit' );
		$token  = (string) $request->get_param( 'token' );
		$secret = (string) get_option( 'el_aas_webhook_secret', '' );

		if ( '' === $secret || '' === $repo || '' === $commit || '' === $token ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $repo . '|' . $commit, $secret );
		return hash_equals( $expected, $token );
	}

	/**
	 * Handle an internal process request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_process( WP_REST_Request $request ) {
		$repo   = (string) $request->get_param( 'repo' );
		$commit = (string) $request->get_param( 'commit' );

		if ( '' === $repo || '' === $commit ) {
			return new WP_Error( 'el_aas_invalid_request', __( 'Missing parameters.', 'el-aas' ), array( 'status' => 400 ) );
		}

		$deployer = new EL_AAS_Deployer();
		$result   = $deployer->process( $repo, $commit );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'ok' => true, 'commit' => $commit ), 200 );
	}
}
