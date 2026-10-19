<?php
/**
 * Audit log of tool calls.
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Class Audit
 *
 * Every tool call this server runs is decided by a model whose context is
 * filled with content from this very site — post bodies, comments, profile
 * fields, product descriptions. That content is untrusted input reaching the
 * thing that chooses what to do next, and no capability check can tell a
 * genuine instruction from one written into a comment.
 *
 * Capabilities bound what a connection *may* do. This records what it *did*,
 * so a wrong call can be noticed and traced rather than only prevented.
 */
class Audit {

	/**
	 * Where the log lives.
	 */
	const OPTION = 'wpmcp_audit_log';

	/**
	 * Entries kept. Old ones fall off the end.
	 */
	const MAX = 100;

	/**
	 * Longest a recorded value may be.
	 */
	const MAX_VALUE = 80;

	/**
	 * Argument names worth recording.
	 *
	 * An allow-list, not a deny-list: arguments carry post bodies, passwords
	 * and option values, and a log that quietly accumulates those is a new
	 * place for secrets to sit. Identifiers are enough to retrace a call.
	 *
	 * @var string[]
	 */
	private static $loggable = array(
		'id',
		'post_id',
		'user_id',
		'attachment_id',
		'parent',
		'term_id',
		'plugin',
		'stylesheet',
		'slug',
		'taxonomy',
		'post_type',
		'status',
		'key',
		'name',
		'username',
		'role',
		'url',
		'force',
	);

	/**
	 * Record one call.
	 *
	 * @param string $tool    Tool name.
	 * @param string $outcome One of ok|error|denied|blocked|unknown.
	 * @param array  $args    Raw tool arguments.
	 * @param string $detail  Optional short explanation.
	 */
	public function record( $tool, $outcome, array $args = array(), $detail = '' ) {
		$entries = $this->entries();

		array_unshift(
			$entries,
			array(
				't'    => time(),
				'u'    => get_current_user_id(),
				'tool' => (string) $tool,
				'r'    => (string) $outcome,
				'a'    => $this->summarize( $args ),
				'd'    => $this->truncate( (string) $detail, 160 ),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX ), false );
	}

	/**
	 * The log, newest first.
	 *
	 * @return array
	 */
	public function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Empty the log.
	 */
	public function clear() {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Reduce arguments to the identifiers worth keeping.
	 *
	 * @param array $args Raw arguments.
	 * @return string
	 */
	private function summarize( array $args ) {
		$parts = array();

		foreach ( self::$loggable as $key ) {
			if ( ! isset( $args[ $key ] ) || ! is_scalar( $args[ $key ] ) ) {
				continue;
			}
			$value = (string) $args[ $key ];
			if ( '' === $value ) {
				continue;
			}
			$parts[] = $key . '=' . $this->truncate( $value, self::MAX_VALUE );
		}

		// Names the caller invented are not on the allow-list and are not
		// recorded, but the fact that something else was passed is worth
		// knowing when retracing a call.
		$extra = count( array_diff( array_keys( $args ), self::$loggable ) );
		if ( $extra > 0 ) {
			$parts[] = sprintf( '+%d more', $extra );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Shorten a value for storage.
	 *
	 * @param string $value Value.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	private function truncate( $value, $limit ) {
		$value = sanitize_text_field( $value );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $limit ) {
			return mb_substr( $value, 0, $limit ) . '…';
		}
		if ( strlen( $value ) > $limit ) {
			return substr( $value, 0, $limit ) . '…';
		}
		return $value;
	}
}
