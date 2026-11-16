<?php
/**
 * GitHub API client.
 *
 * @package EL_AAS
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub API client.
 *
 * @since 1.0.0
 */
class EL_AAS_GitHub_API {

	/**
	 * GitHub personal access token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Optional personal access token.
	 */
	public function __construct( $token = '' ) {
		$this->token = trim( (string) $token );
	}

	/**
	 * Get the latest commit on a branch.
	 *
	 * @param string $repo   Repository in owner/name format.
	 * @param string $branch Branch name.
	 * @return array|WP_Error
	 */
	public function get_branch_head( $repo, $branch ) {
		$url  = $this->build_url( $repo, 'branches/' . rawurlencode( $branch ) );
		$data = $this->request( $url );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) || empty( $data['commit']['sha'] ) ) {
			return new WP_Error( 'el_aas_github_api', __( 'GitHub API response did not include a commit SHA.', 'el-aas' ) );
		}

		return array(
			'sha'     => $data['commit']['sha'],
			'message' => isset( $data['commit']['commit']['message'] ) ? $data['commit']['commit']['message'] : '',
			'author'  => isset( $data['commit']['commit']['author']['name'] ) ? $data['commit']['commit']['author']['name'] : '',
			'date'    => isset( $data['commit']['commit']['author']['date'] ) ? $data['commit']['commit']['author']['date'] : '',
			'raw'     => $data,
		);
	}

	/**
	 * Get a specific commit.
	 *
	 * @param string $repo Repository in owner/name format.
	 * @param string $sha  Commit SHA.
	 * @return array|WP_Error
	 */
	public function get_commit( $repo, $sha ) {
		$url = $this->build_url( $repo, 'commits/' . rawurlencode( $sha ) );
		return $this->request( $url );
	}

	/**
	 * Get the latest published release.
	 *
	 * @param string $repo Repository in owner/name format.
	 * @return array|WP_Error
	 */
	public function get_latest_release( $repo ) {
		$url = $this->build_url( $repo, 'releases/latest' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
		if ( '' === $tag ) {
			return new WP_Error( 'el_aas_github_api', __( 'The latest release did not include a tag.', 'el-aas' ) );
		}

		return array(
			'tag'   => $tag,
			'notes' => isset( $data['body'] ) ? (string) $data['body'] : '',
			'date'  => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'raw'   => $data,
		);
	}

	/**
	 * Get the latest tag when the repository has no releases.
	 *
	 * @param string $repo Repository in owner/name format.
	 * @return string|WP_Error
	 */
	public function get_latest_tag( $repo ) {
		$url = $this->build_url( $repo, 'tags' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$data = $this->request( $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data ) || empty( $data[0]['name'] ) ) {
			return new WP_Error( 'el_aas_github_api', __( 'The repository has no tags.', 'el-aas' ) );
		}

		return (string) $data[0]['name'];
	}

	/**
	 * Download a zipball for a commit to a file.
	 *
	 * @param string $repo        Repository in owner/name format.
	 * @param string $sha         Commit SHA.
	 * @param string $destination Destination file path.
	 * @return true|WP_Error
	 */
	public function download_zipball( $repo, $sha, $destination ) {
		$url = $this->build_url( $repo, 'zipball/' . rawurlencode( $sha ) );

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'EL-Automated-Update-System/' . EL_AAS_VERSION,
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 120,
				'headers'     => $headers,
				'stream'      => true,
				'filename'    => $destination,
				'sslverify'   => apply_filters( 'el_aas_sslverify', true ),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cleanup_file( $destination );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( (string) $body, true );
			$message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub zipball download returned HTTP %d.', 'el-aas' ),
					$code
				);

			$this->cleanup_file( $destination );
			return new WP_Error( 'el_aas_github_download', $message, array( 'status' => $code ) );
		}

		if ( ! file_exists( $destination ) || filesize( $destination ) < 1 ) {
			$this->cleanup_file( $destination );
			return new WP_Error( 'el_aas_github_download', __( 'Downloaded zipball is empty or missing.', 'el-aas' ) );
		}

		return true;
	}

	/**
	 * Perform a GitHub API request.
	 *
	 * @param string $url API URL.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'EL-Automated-Update-System/' . EL_AAS_VERSION,
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'headers'   => $headers,
				'sslverify' => apply_filters( 'el_aas_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( (string) $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub API returned HTTP %d.', 'el-aas' ),
					$code
				);

			return new WP_Error( 'el_aas_github_api', $message, array( 'status' => $code, 'body' => $data ) );
		}

		if ( null === $data && '' !== trim( (string) $body ) ) {
			return new WP_Error( 'el_aas_github_api', __( 'GitHub API returned invalid JSON.', 'el-aas' ) );
		}

		return $data;
	}

	/**
	 * Build a GitHub API URL.
	 *
	 * @param string $repo   Repository in owner/name format.
	 * @param string $suffix URL suffix.
	 * @return string|WP_Error
	 */
	private function build_url( $repo, $suffix ) {
		$parts = explode( '/', (string) $repo, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return new WP_Error( 'el_aas_github_api', __( 'Repository must be in owner/name format.', 'el-aas' ) );
		}

		return sprintf(
			'https://api.github.com/repos/%s/%s/%s',
			rawurlencode( $parts[0] ),
			rawurlencode( $parts[1] ),
			$suffix
		);
	}

	/**
	 * Remove a partial download.
	 *
	 * @param string $file File path.
	 * @return void
	 */
	private function cleanup_file( $file ) {
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
