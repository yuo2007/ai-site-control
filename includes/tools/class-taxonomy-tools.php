<?php
/**
 * Taxonomy & term tools.
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Taxonomy_Tools
 */
class Taxonomy_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'list_taxonomies',
			'List all registered taxonomies (categories, tags, product categories, custom taxonomies).',
			$this->schema( array() ),
			array( $this, 'list_taxonomies' ),
			'read'
		);

		$r->add(
			'list_terms',
			'List terms in a taxonomy (e.g. categories or tags).',
			$this->schema(
				array(
					'taxonomy'   => $this->str( 'Taxonomy slug, e.g. category, post_tag, product_cat.' ),
					'search'     => $this->str( 'Optional name search.' ),
					'hide_empty' => $this->bool( 'Hide terms with no posts (default false).' ),
					'per_page'   => $this->int( 'Max terms (default 100).' ),
				),
				array( 'taxonomy' )
			),
			array( $this, 'list_terms' ),
			'read'
		);

		$r->add(
			'create_term',
			'Create a term in a taxonomy.',
			$this->schema(
				array(
					'taxonomy'    => $this->str( 'Taxonomy slug.' ),
					'name'        => $this->str( 'Term name.' ),
					'slug'        => $this->str( 'Optional slug.' ),
					'description' => $this->str( 'Optional description.' ),
					'parent'      => $this->int( 'Parent term ID (hierarchical taxonomies).' ),
				),
				array( 'taxonomy', 'name' )
			),
			array( $this, 'create_term' ),
			'manage_categories'
		);

		$r->add(
			'update_term',
			'Update a term (name, slug, description, parent).',
			$this->schema(
				array(
					'taxonomy'    => $this->str( 'Taxonomy slug.' ),
					'term_id'     => $this->int( 'Term ID.' ),
					'name'        => $this->str( 'New name.' ),
					'slug'        => $this->str( 'New slug.' ),
					'description' => $this->str( 'New description.' ),
					'parent'      => $this->int( 'New parent term ID.' ),
				),
				array( 'taxonomy', 'term_id' )
			),
			array( $this, 'update_term' ),
			'manage_categories'
		);

		$r->add(
			'delete_term',
			'Delete a term from a taxonomy.',
			$this->schema(
				array(
					'taxonomy' => $this->str( 'Taxonomy slug.' ),
					'term_id'  => $this->int( 'Term ID.' ),
				),
				array( 'taxonomy', 'term_id' )
			),
			array( $this, 'delete_term' ),
			'manage_categories'
		);

		$r->add(
			'assign_post_terms',
			'Assign (or append) taxonomy terms to a post. Terms may be IDs or names; names are created if missing.',
			$this->schema(
				array(
					'post_id'  => $this->int( 'Post ID.' ),
					'taxonomy' => $this->str( 'Taxonomy slug.' ),
					'terms'    => array(
						'type'        => 'array',
						'description' => 'Array of term IDs (integers) or term names (strings).',
						'items'       => array( 'type' => array( 'string', 'integer' ) ),
					),
					'append'   => $this->bool( 'Append instead of replacing existing terms.' ),
				),
				array( 'post_id', 'taxonomy', 'terms' )
			),
			array( $this, 'assign_post_terms' ),
			'edit_posts'
		);
	}

	/**
	 * List taxonomies.
	 *
	 * @return array
	 */
	public function list_taxonomies() {
		$taxes = get_taxonomies( array(), 'objects' );
		$out   = array();
		foreach ( $taxes as $t ) {
			$out[] = array(
				'slug'         => $t->name,
				'label'        => $t->label,
				'hierarchical' => (bool) $t->hierarchical,
				'object_type'  => $t->object_type,
				'public'       => (bool) $t->public,
			);
		}
		return $out;
	}

	/**
	 * List terms.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_terms( array $args ) {
		$taxonomy = (string) $this->required( $args, 'taxonomy' );
		$terms    = $this->unwrap(
			get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'search'     => $args['search'] ?? '',
					'hide_empty' => ! empty( $args['hide_empty'] ),
					'number'     => $this->per_page( $args['per_page'] ?? null, 100, 500 ),
				)
			)
		);
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = $this->format_term( $t );
		}
		return $out;
	}

	/**
	 * Create a term.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_term( array $args ) {
		$taxonomy = (string) $this->required( $args, 'taxonomy' );
		// The tools are gated on the generic manage_categories; the real
		// capability is the taxonomy's own — product_cat needs
		// manage_woocommerce, and wp_insert_term() checks neither.
		$this->assert_taxonomy( $taxonomy, 'manage' );
		$name = (string) $this->required( $args, 'name' );
		$res  = $this->unwrap(
			wp_insert_term(
				$name,
				$taxonomy,
				array(
					'slug'        => $args['slug'] ?? '',
					'description' => $args['description'] ?? '',
					'parent'      => (int) ( $args['parent'] ?? 0 ),
				)
			)
		);
		return $this->format_term( get_term( $res['term_id'], $taxonomy ) );
	}

	/**
	 * Update a term.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function update_term( array $args ) {
		$taxonomy = (string) $this->required( $args, 'taxonomy' );
		$this->assert_taxonomy( $taxonomy, 'edit' );
		$term_id  = (int) $this->required( $args, 'term_id' );
		$fields   = array();
		foreach ( array( 'name', 'slug', 'description' ) as $f ) {
			if ( isset( $args[ $f ] ) ) {
				$fields[ $f ] = (string) $args[ $f ];
			}
		}
		if ( isset( $args['parent'] ) ) {
			$fields['parent'] = (int) $args['parent'];
		}
		$this->unwrap( wp_update_term( $term_id, $taxonomy, $fields ) );
		return $this->format_term( get_term( $term_id, $taxonomy ) );
	}

	/**
	 * Delete a term.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function delete_term( array $args ) {
		$taxonomy = (string) $this->required( $args, 'taxonomy' );
		$this->assert_taxonomy( $taxonomy, 'delete' );
		$term_id  = (int) $this->required( $args, 'term_id' );
		$this->unwrap( wp_delete_term( $term_id, $taxonomy ) );
		return array(
			'deleted'  => true,
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Assign terms to a post.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function assign_post_terms( array $args ) {
		$post_id  = (int) $this->required( $args, 'post_id' );
		$taxonomy = (string) $this->required( $args, 'taxonomy' );
		$terms    = $this->required( $args, 'terms' );

		$this->assert_editable_post( $post_id );
		$tax = $this->assert_taxonomy( $taxonomy, 'assign' );

		if ( ! is_array( $terms ) ) {
			$terms = array( $terms );
		}

		// A string term name would otherwise be auto-created by
		// wp_set_object_terms() — a weaker gate than create_term's. Only allow
		// that when the caller could actually manage the taxonomy; otherwise
		// resolve names to existing terms and reject unknown ones.
		$may_create = current_user_can( $tax->cap->manage_terms );
		$normalized = array();
		foreach ( $terms as $t ) {
			if ( is_numeric( $t ) ) {
				$normalized[] = (int) $t;
				continue;
			}
			$name = (string) $t;
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				$normalized[] = (int) $term->term_id;
			} elseif ( $may_create ) {
				$normalized[] = $name;
			} else {
				throw new \Exception( esc_html( 'Unknown term (creating it requires manage rights): ' . $name ) );
			}
		}

		$res = $this->unwrap( wp_set_object_terms( $post_id, $normalized, $taxonomy, ! empty( $args['append'] ) ) );
		return array(
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'term_ids' => $res,
		);
	}

	/**
	 * Format a term.
	 *
	 * @param \WP_Term $t Term.
	 * @return array
	 */
	private function format_term( $t ) {
		return array(
			'term_id'     => $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'taxonomy'    => $t->taxonomy,
			'parent'      => $t->parent,
			'count'       => $t->count,
			'description' => $t->description,
		);
	}
}
