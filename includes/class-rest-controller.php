<?php
/**
 * REST controller exposing the MCP Streamable-HTTP endpoint.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class REST_Controller
 *
 * Registers /wp-json/wp-mcp/v1/mcp as a single JSON-RPC endpoint.
 */
class REST_Controller {

	/**
	 * Auth handler.
	 *
	 * @var Auth
	 */
	private $auth;

	/**
	 * Tool registry.
	 *
	 * @var Tool_Registry
	 */
	private $tools;

	/**
	 * Constructor.
	 *
	 * @param Auth          $auth  Auth.
	 * @param Tool_Registry $tools Registry.
	 */
	public function __construct( Auth $auth, Tool_Registry $tools ) {
		$this->auth  = $auth;
		$this->tools = $tools;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			WPMCP_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this->auth, 'permission_callback' ),
				),
				// GET is used by some clients to probe / open an SSE stream.
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this->auth, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Handle the POST JSON-RPC request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		// A privileged POST expressed as a form with enctype="text/plain" is a
		// CORS-simple request (no preflight), so a token that has leaked into a
		// URL could be driven from any visitor's browser. Requiring a JSON
		// content type costs a real MCP client nothing and closes that vector.
		$ct = (string) $request->get_header( 'content-type' );
		if ( '' === $ct || false === stripos( $ct, 'application/json' ) ) {
			return $this->respond(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Content-Type must be application/json.',
					),
				),
				415
			);
		}

		$body    = $request->get_body();
		$payload = json_decode( $body, true );

		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->respond(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32700,
						'message' => 'Parse error',
					),
				),
				400
			);
		}

		$server   = new MCP_Server( $this->tools );
		$response = $server->handle( $payload );

		if ( null === $response ) {
			// Notification(s) only — MCP expects 202 with no body.
			return new \WP_REST_Response( null, 202 );
		}

		return $this->respond( $response, 200 );
	}

	/**
	 * Handle GET — we do not keep long-lived SSE streams open; return 405
	 * so clients fall back to plain POST JSON responses.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_get() {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32000,
					'message' => 'This server uses POST JSON-RPC (Streamable HTTP without server-initiated SSE).',
				),
			),
			405
		);
	}

	/**
	 * Emit a raw JSON response (bypassing WP REST envelope wrapping).
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function respond( $data, $status ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		// If a token ever rides in the URL, keep the response out of shared caches.
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
