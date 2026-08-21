<?php
/**
 * Media library tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Media_Tools
 */
class Media_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_media',
			'List media library items (attachments).',
			$this->schema(
				array(
					'search'    => $this->str( 'Optional search term.' ),
					'mime_type' => $this->str( 'Filter by MIME type, e.g. image, image/png, application/pdf.' ),
					'per_page'  => $this->int( 'Results per page (default 20).' ),
					'page'      => $this->int( 'Page number.' ),
				)
			),
			array( $this, 'list_media' ),
			'upload_files'
		);

		$r->add(
			'upload_media_from_url',
			'Download a file from a URL and add it to the media library. Optionally attach to a post.',
			$this->schema(
				array(
					'url'         => $this->str( 'Publicly reachable file URL.' ),
					'filename'    => $this->str( 'Optional filename override.' ),
					'title'       => $this->str( 'Attachment title.' ),
					'alt'         => $this->str( 'Image alt text.' ),
					'caption'     => $this->str( 'Caption.' ),
					'description' => $this->str( 'Description.' ),
					'post_id'     => $this->int( 'Attach to this post ID.' ),
				),
				array( 'url' )
			),
			array( $this, 'upload_media_from_url' ),
			'upload_files'
		);

		$r->add(
			'upload_media_base64',
			'Upload a file provided as base64 data to the media library.',
			$this->schema(
				array(
					'filename' => $this->str( 'Filename including extension, e.g. photo.jpg.' ),
					'data'     => $this->str( 'Base64-encoded file contents.' ),
					'title'    => $this->str( 'Attachment title.' ),
					'alt'      => $this->str( 'Image alt text.' ),
					'post_id'  => $this->int( 'Attach to this post ID.' ),
				),
				array( 'filename', 'data' )
			),
			array( $this, 'upload_media_base64' ),
			'upload_files'
		);

		$r->add(
			'update_media',
			'Update attachment metadata (title, alt text, caption, description).',
			$this->schema(
				array(
					'id'          => $this->int( 'Attachment ID.' ),
					'title'       => $this->str( 'New title.' ),
					'alt'         => $this->str( 'New alt text.' ),
					'caption'     => $this->str( 'New caption.' ),
					'description' => $this->str( 'New description.' ),
				),
				array( 'id' )
			),
			array( $this, 'update_media' ),
			'upload_files'
		);

		$r->add(
			'delete_media',
			'Delete a media attachment.',
			$this->schema(
				array(
					'id'    => $this->int( 'Attachment ID.' ),
					'force' => $this->bool( 'Permanently delete (default true for attachments).' ),
				),
				array( 'id' )
			),
			array( $this, 'delete_media' ),
			'delete_posts'
		);

		$r->add(
			'set_featured_image',
			'Set (or clear) the featured image of a post.',
			$this->schema(
				array(
					'post_id'       => $this->int( 'Post ID.' ),
					'attachment_id' => $this->int( 'Attachment ID (0 to remove).' ),
				),
				array( 'post_id', 'attachment_id' )
			),
			array( $this, 'set_featured_image' ),
			'edit_posts'
		);
	}

	/**
	 * Ensure media-handling functions are loaded.
	 */
	private function load_media_deps() {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	/**
	 * List media.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_media( array $args ) {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $args['search'] ?? '',
				'post_mime_type' => $args['mime_type'] ?? '',
				'posts_per_page' => $this->per_page( $args['per_page'] ?? null ),
				'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
			)
		);
		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->format_attachment( $p->ID );
		}
		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * Upload from URL.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function upload_media_from_url( array $args ) {
		$this->load_media_deps();
		$url = esc_url_raw( (string) $this->required( $args, 'url' ) );

		$this->assert_external_url( $url );

		$tmp = download_url( $url );
		$this->unwrap( $tmp );

		// download_url() imposes no size cap; a multi-GB target would fill the
		// partition and take the database down with it.
		$max = wp_max_upload_size();
		if ( $max > 0 && filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			throw new \Exception( 'Downloaded file exceeds the maximum upload size.' );
		}

		$filename  = ! empty( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : basename( wp_parse_url( $url, PHP_URL_PATH ) );
		$file_array = array(
			'name'     => $filename ? $filename : 'upload',
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new \Exception( $id->get_error_message() );
		}

		$this->apply_media_meta( $id, $args );
		return $this->format_attachment( $id );
	}

	/**
	 * Upload from base64.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function upload_media_base64( array $args ) {
		$this->load_media_deps();
		$filename = sanitize_file_name( (string) $this->required( $args, 'filename' ) );
		$raw      = (string) $this->required( $args, 'data' );

		// Strip data URI prefix if present.
		if ( false !== strpos( $raw, ',' ) && 0 === strpos( $raw, 'data:' ) ) {
			$raw = substr( $raw, strpos( $raw, ',' ) + 1 );
		}
		// Peak memory is roughly 4x the payload (request body, $raw, the
		// substr() copy, $decoded), so cap before decoding, not after.
		$max = wp_max_upload_size();
		$max = $max > 0 ? $max : 8 * MB_IN_BYTES;
		if ( strlen( $raw ) > (int) ( $max * 1.4 ) ) {
			throw new \Exception( 'Payload too large.' );
		}

		$decoded = base64_decode( $raw, true );
		unset( $raw );
		if ( false === $decoded ) {
			throw new \Exception( 'Invalid base64 data.' );
		}
		if ( strlen( $decoded ) > $max ) {
			throw new \Exception( 'Decoded file exceeds the maximum upload size.' );
		}

		// The URL path gets wp_check_filetype_and_ext() for free via
		// media_handle_sideload(); wp_upload_bits() only ever looked at the
		// extension. Route both through the same hardened path so the bytes
		// have to match the name.
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			throw new \Exception( 'Could not create a temporary file.' );
		}
		file_put_contents( $tmp, $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unset( $decoded );

		$check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			wp_delete_file( $tmp );
			throw new \Exception( 'File content does not match its extension, or the type is not allowed.' );
		}

		$id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			(int) ( $args['post_id'] ?? 0 )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new \Exception( $id->get_error_message() );
		}

		$this->apply_media_meta( $id, $args );
		return $this->format_attachment( $id );
	}

	/**
	 * Update media metadata.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function update_media( array $args ) {
		$id = (int) $this->required( $args, 'id' );

		// Without this the tool is a general-purpose post editor: nothing
		// constrains the ID to an attachment, and wp_update_post() checks no
		// capabilities, so any page on the site could be rewritten.
		$this->assert_attachment( $id, 'edit_post' );

		$this->apply_media_meta( $id, $args );
		$update = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = (string) $args['title'];
		}
		if ( isset( $args['caption'] ) ) {
			$update['post_excerpt'] = (string) $args['caption'];
		}
		if ( isset( $args['description'] ) ) {
			$update['post_content'] = (string) $args['description'];
		}
		if ( count( $update ) > 1 ) {
			$this->unwrap( wp_update_post( $update, true ) );
		}
		return $this->format_attachment( $id );
	}

	/**
	 * Delete media.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception On failure.
	 */
	public function delete_media( array $args ) {
		$id = (int) $this->required( $args, 'id' );
		$this->assert_attachment( $id, 'delete_post' );

		// Was: ! isset( $force ) || ! empty( $force ) — which is true even for
		// an explicit "force": false, making every delete permanent.
		$force = isset( $args['force'] ) ? (bool) $args['force'] : true;
		$res   = wp_delete_attachment( $id, $force );
		if ( ! $res ) {
			throw new \Exception( 'Failed to delete attachment: ' . $id );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	/**
	 * Set featured image.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function set_featured_image( array $args ) {
		$post_id       = (int) $this->required( $args, 'post_id' );
		$attachment_id = (int) $this->required( $args, 'attachment_id' );

		$this->assert_editable_post( $post_id );
		if ( $attachment_id > 0 ) {
			$this->assert_attachment( $attachment_id, 'read_post' );
		}

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		} else {
			delete_post_thumbnail( $post_id );
		}
		return array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Reject URLs that resolve to an internal address.
	 *
	 * wp_safe_remote_get() (used by download_url) blocks file://, localhost
	 * and most RFC1918, but it does NOT block 169.254.0.0/16 — the cloud
	 * metadata range — nor 100.64.0.0/10. Since the fetched body is stored
	 * and handed back as a public URL, that would be a readable SSRF, not a
	 * blind one.
	 *
	 * @param string $url URL to fetch.
	 * @throws \Exception When the target is internal or unresolvable.
	 */
	private function assert_external_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			throw new \Exception( 'Invalid URL.' );
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			throw new \Exception( 'Only http and https URLs may be fetched.' );
		}

		$ips = gethostbynamel( $parts['host'] );
		if ( empty( $ips ) ) {
			// A literal IPv6 host never resolves through gethostbynamel().
			$ips = filter_var( trim( $parts['host'], '[]' ), FILTER_VALIDATE_IP ) ? array( trim( $parts['host'], '[]' ) ) : array();
		}
		if ( empty( $ips ) ) {
			throw new \Exception( 'Could not resolve host: ' . $parts['host'] );
		}

		foreach ( $ips as $ip ) {
			// NO_RES_RANGE covers 169.254/16, 127/8, 240/4 and friends;
			// NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				throw new \Exception( 'Refusing to fetch an internal address: ' . $ip );
			}
			// Carrier-grade NAT / common k8s range, not covered by the flags.
			if ( 0 === strpos( $ip, '100.' ) ) {
				$octet = (int) explode( '.', $ip )[1];
				if ( $octet >= 64 && $octet <= 127 ) {
					throw new \Exception( 'Refusing to fetch an internal address: ' . $ip );
				}
			}
		}
	}

	/**
	 * Apply alt/caption/etc. to an attachment.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $args Args.
	 */
	private function apply_media_meta( $id, array $args ) {
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}
	}

	/**
	 * Format an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function format_attachment( $id ) {
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => get_post_mime_type( $id ),
			'alt'       => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => wp_get_attachment_caption( $id ),
			'metadata'  => wp_get_attachment_metadata( $id ),
		);
	}
}
