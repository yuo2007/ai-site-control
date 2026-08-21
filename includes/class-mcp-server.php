<?php
/**
 * JSON-RPC 2.0 / MCP protocol handler.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class MCP_Server
 *
 * Implements the subset of the Model Context Protocol needed for tools:
 * initialize, notifications/initialized, ping, tools/list, tools/call.
 */
class MCP_Server {

	const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Protocol versions we can speak, newest first.
	 *
	 * @var string[]
	 */
	const SUPPORTED_VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Tool registry.
	 *
	 * @var Tool_Registry
	 */
	private $tools;

	/**
	 * Constructor.
	 *
	 * @param Tool_Registry $tools Registry.
	 */
	public function __construct( Tool_Registry $tools ) {
		$this->tools = $tools;
	}

	/**
	 * Handle a decoded JSON-RPC payload (single object or batch array).
	 *
	 * @param mixed $payload Decoded JSON.
	 * @return array|null Response payload, or null for notification-only input.
	 */
	public function handle( $payload ) {
		if ( is_array( $payload ) && array_is_list( $payload ) ) {
			$responses = array();
			foreach ( $payload as $item ) {
				$r = $this->handle_single( $item );
				if ( null !== $r ) {
					$responses[] = $r;
				}
			}
			return empty( $responses ) ? null : $responses;
		}
		return $this->handle_single( $payload );
	}

	/**
	 * Handle a single JSON-RPC request.
	 *
	 * @param mixed $req Request object.
	 * @return array|null
	 */
	private function handle_single( $req ) {
		if ( ! is_array( $req ) || ! isset( $req['method'] ) ) {
			return $this->error( null, -32600, 'Invalid Request' );
		}

		$id     = $req['id'] ?? null;
		$method = $req['method'];
		$params = $req['params'] ?? array();

		// Notifications have no id and expect no response.
		$is_notification = ! array_key_exists( 'id', $req );

		switch ( $method ) {
			case 'initialize':
				return $this->result( $id, $this->initialize( $params ) );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null;

			case 'ping':
				return $this->result( $id, new \stdClass() );

			case 'tools/list':
				return $this->result( $id, array( 'tools' => $this->tools->list_for_mcp() ) );

			// We expose no resources or prompts, but clients probe these right
			// after initialize; an empty list is a valid answer, -32601 is not.
			case 'resources/list':
				return $this->result( $id, array( 'resources' => array() ) );

			case 'resources/templates/list':
				return $this->result( $id, array( 'resourceTemplates' => array() ) );

			case 'prompts/list':
				return $this->result( $id, array( 'prompts' => array() ) );

			case 'tools/call':
				return $this->call_tool( $id, $params );

			default:
				if ( $is_notification ) {
					return null;
				}
				return $this->error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Build the initialize result.
	 *
	 * The spec requires us to echo the client's requested protocol version when
	 * we support it; answering with a different version makes the client
	 * disconnect. Only when we cannot speak the requested version do we reply
	 * with our own latest.
	 *
	 * @param array $params Initialize params.
	 * @return array
	 */
	private function initialize( $params ) {
		$requested = is_array( $params ) && isset( $params['protocolVersion'] )
			? (string) $params['protocolVersion']
			: '';
		$version = in_array( $requested, self::SUPPORTED_VERSIONS, true )
			? $requested
			: self::PROTOCOL_VERSION;

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false, 'subscribe' => false ),
				'prompts'   => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'wp-mcp-control',
				'title'   => get_bloginfo( 'name' ) . ' (WP MCP Control)',
				'version' => WPMCP_VERSION,
			),
			'instructions'    => 'WordPress site control server. Use tools/list to discover available operations for posts, media, users, taxonomies, comments, options, plugins, themes, WooCommerce and Polylang.',
		);
	}

	/**
	 * Execute a tools/call request.
	 *
	 * @param mixed $id     Request id.
	 * @param array $params Params: { name, arguments }.
	 * @return array
	 */
	private function call_tool( $id, $params ) {
		$name = $params['name'] ?? '';
		$args = $params['arguments'] ?? array();
		if ( ! is_array( $args ) ) {
			$args = (array) $args;
		}

		if ( ! $this->tools->has( $name ) ) {
			return $this->error( $id, -32602, 'Unknown or disabled tool: ' . $name );
		}

		$def = $this->tools->get( $name );

		if ( ! empty( $def['capability'] ) && ! current_user_can( $def['capability'] ) ) {
			return $this->tool_error( $id, sprintf( 'Permission denied: the connected user lacks the "%s" capability.', $def['capability'] ) );
		}

		try {
			$result = call_user_func( $def['callback'], $args );
			return $this->result(
				$id,
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						),
					),
					// No structuredContent: the spec ties it to a declared
					// outputSchema, and our tools declare none — strict clients
					// reject the result otherwise.
					'isError'           => false,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->tool_error( $id, $e->getMessage() );
		}
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param mixed $id     Id.
	 * @param mixed $result Result.
	 * @return array
	 */
	private function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC protocol error.
	 *
	 * @param mixed  $id      Id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @return array
	 */
	private function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Build a tool-level error (isError content, not a protocol error).
	 * MCP convention: tool failures come back as a normal result with isError=true.
	 *
	 * @param mixed  $id      Id.
	 * @param string $message Message.
	 * @return array
	 */
	private function tool_error( $id, $message ) {
		return $this->result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Error: ' . $message,
					),
				),
				'isError' => true,
			)
		);
	}
}

// Polyfill for PHP < 8.1.
if ( ! function_exists( 'WPMCP\\array_is_list' ) && ! function_exists( 'array_is_list' ) ) {
	/**
	 * Polyfill array_is_list.
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	function array_is_list( array $arr ) {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
