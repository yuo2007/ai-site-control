<?php
/**
 * Tool registry.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tool_Registry
 *
 * Holds every tool definition keyed by name.
 */
class Tool_Registry {

	/**
	 * Registered tools.
	 *
	 * @var array<string, array>
	 */
	private $tools = array();

	/**
	 * Register a tool.
	 *
	 * @param string   $name        Unique tool name (snake_case).
	 * @param string   $description Human-readable description for the LLM.
	 * @param array    $schema      JSON-Schema for the input (properties/required).
	 * @param callable $callback    fn( array $args ): mixed  — may throw \Exception.
	 * @param string   $capability  Required WP capability (checked before calling).
	 */
	public function add( $name, $description, array $schema, callable $callback, $capability = 'read' ) {
		$this->tools[ $name ] = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $this->normalize_schema( $schema ),
			'callback'    => $callback,
			'capability'  => $capability,
		);
	}

	/**
	 * Ensure the schema is a valid JSON-Schema object.
	 *
	 * @param array $schema Raw schema.
	 * @return array
	 */
	private function normalize_schema( array $schema ) {
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( ! isset( $schema['properties'] ) || array() === $schema['properties'] ) {
			// Empty PHP arrays JSON-encode to "[]"; JSON Schema requires an object.
			$schema['properties'] = new \stdClass();
		}
		return $schema;
	}

	/**
	 * Whether a tool exists (and is not disabled).
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->tools[ $name ] ) && ! $this->is_disabled( $name );
	}

	/**
	 * Get a single tool definition.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public function get( $name ) {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * Is a tool disabled in settings?
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	private function is_disabled( $name ) {
		$s = (array) get_option( Auth::OPTION, array() );
		return in_array( $name, (array) ( $s['disabled_tools'] ?? array() ), true );
	}

	/**
	 * List all enabled tools in MCP tools/list shape.
	 *
	 * @return array
	 */
	public function list_for_mcp() {
		$out = array();
		foreach ( $this->tools as $name => $def ) {
			if ( $this->is_disabled( $name ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $def['name'],
				'description' => $def['description'],
				'inputSchema' => $def['inputSchema'],
			);
		}
		return $out;
	}

	/**
	 * All tool names (including disabled), for the admin UI.
	 *
	 * @return array
	 */
	public function all_names() {
		return array_keys( $this->tools );
	}
}
