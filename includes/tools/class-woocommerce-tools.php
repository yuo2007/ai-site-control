<?php
/**
 * WooCommerce tools (only registered when WooCommerce is active).
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

use WPMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerce_Tools
 */
class WooCommerce_Tools extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public function register( Tool_Registry $r ) {

		$r->add(
			'wc_list_products',
			'List WooCommerce products with optional search, category and status filters.',
			$this->schema(
				array(
					'search'   => $this->str( 'Search term.' ),
					'status'   => $this->str( 'publish, draft, pending, private.' ),
					'category' => $this->str( 'Product category slug.' ),
					'per_page' => $this->int( 'Results per page (default 20).' ),
					'page'     => $this->int( 'Page number.' ),
				)
			),
			array( $this, 'list_products' ),
			'edit_products'
		);

		$r->add(
			'wc_get_product',
			'Get a single WooCommerce product with pricing, stock and attributes.',
			$this->schema( array( 'id' => $this->int( 'Product ID.' ) ), array( 'id' ) ),
			array( $this, 'get_product' ),
			'edit_products'
		);

		$r->add(
			'wc_create_product',
			'Create a WooCommerce product.',
			$this->schema(
				array(
					'name'           => $this->str( 'Product name.' ),
					'type'           => $this->str( 'simple (default), grouped, external, variable.' ),
					'regular_price'  => $this->str( 'Regular price, e.g. "19.99".' ),
					'sale_price'     => $this->str( 'Sale price.' ),
					'description'    => $this->str( 'Full description.' ),
					'short_description' => $this->str( 'Short description.' ),
					'sku'            => $this->str( 'SKU.' ),
					'manage_stock'   => $this->bool( 'Enable stock management.' ),
					'stock_quantity' => $this->int( 'Stock quantity.' ),
					'status'         => $this->str( 'publish (default), draft, pending.' ),
					'categories'     => array(
						'type'        => 'array',
						'description' => 'Category names or IDs.',
						'items'       => array( 'type' => array( 'string', 'integer' ) ),
					),
					'image_id'       => $this->int( 'Attachment ID for the main image.' ),
				),
				array( 'name' )
			),
			array( $this, 'create_product' ),
			'edit_products'
		);

		$r->add(
			'wc_update_product',
			'Update a WooCommerce product. Only provided fields change.',
			$this->schema(
				array(
					'id'             => $this->int( 'Product ID.' ),
					'name'           => $this->str( 'New name.' ),
					'regular_price'  => $this->str( 'New regular price.' ),
					'sale_price'     => $this->str( 'New sale price.' ),
					'description'    => $this->str( 'New description.' ),
					'short_description' => $this->str( 'New short description.' ),
					'sku'            => $this->str( 'New SKU.' ),
					'manage_stock'   => $this->bool( 'Enable stock management.' ),
					'stock_quantity' => $this->int( 'New stock quantity.' ),
					'status'         => $this->str( 'New status.' ),
				),
				array( 'id' )
			),
			array( $this, 'update_product' ),
			'edit_products'
		);

		$r->add(
			'wc_delete_product',
			'Delete a WooCommerce product (trash or permanent).',
			$this->schema(
				array(
					'id'    => $this->int( 'Product ID.' ),
					'force' => $this->bool( 'Permanently delete.' ),
				),
				array( 'id' )
			),
			array( $this, 'delete_product' ),
			'delete_products'
		);

		$r->add(
			'wc_list_orders',
			'List WooCommerce orders with optional status filter.',
			$this->schema(
				array(
					'status'   => $this->str( 'Order status without wc- prefix, e.g. processing, completed, refunded.' ),
					'customer' => $this->int( 'Filter by customer user ID.' ),
					'per_page' => $this->int( 'Results per page (default 20).' ),
					'page'     => $this->int( 'Page number.' ),
				)
			),
			array( $this, 'list_orders' ),
			'edit_shop_orders'
		);

		$r->add(
			'wc_get_order',
			'Get a WooCommerce order with line items, totals and customer.',
			$this->schema( array( 'id' => $this->int( 'Order ID.' ) ), array( 'id' ) ),
			array( $this, 'get_order' ),
			'edit_shop_orders'
		);

		$r->add(
			'wc_update_order_status',
			'Change a WooCommerce order status.',
			$this->schema(
				array(
					'id'     => $this->int( 'Order ID.' ),
					'status' => $this->str( 'New status, e.g. processing, completed, cancelled, refunded.' ),
					'note'   => $this->str( 'Optional order note.' ),
				),
				array( 'id', 'status' )
			),
			array( $this, 'update_order_status' ),
			'edit_shop_orders'
		);
	}

	/**
	 * List products.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_products( array $args ) {
		$query = array(
			'status'   => $args['status'] ?? array( 'publish', 'draft', 'pending', 'private' ),
			'limit'    => $this->per_page( $args['per_page'] ?? null ),
			'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
			's'        => $args['search'] ?? '',
			'category' => ! empty( $args['category'] ) ? array( $args['category'] ) : array(),
			'paginate' => true,
		);
		$results  = wc_get_products( $query );
		$products = array();
		foreach ( $results->products as $p ) {
			$products[] = $this->format_product( $p, false );
		}
		return array(
			'total'    => (int) $results->total,
			'products' => $products,
		);
	}

	/**
	 * Get product.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When missing.
	 */
	public function get_product( array $args ) {
		$product = $this->load_product( $args, 'read_post' );
		return $this->format_product( $product, true );
	}

	/**
	 * Create product.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function create_product( array $args ) {
		$type = (string) ( $args['type'] ?? 'simple' );
		// Allow-list the type: 'ucfirst' lets user input steer class_exists()
		// into autoloading arbitrary WC_Product_* classes otherwise.
		if ( ! in_array( $type, array( 'simple', 'grouped', 'external', 'variable' ), true ) ) {
			throw new \Exception( 'Unsupported product type: ' . $type );
		}
		$class   = 'WC_Product_' . ucfirst( $type );
		$product = class_exists( $class ) ? new $class() : new \WC_Product_Simple();
		$product->set_name( (string) $this->required( $args, 'name' ) );
		$this->apply_product_fields( $product, $args );
		$product->save();
		$this->apply_product_categories( $product, $args );
		return $this->format_product( wc_get_product( $product->get_id() ), true );
	}

	/**
	 * Update product.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When missing.
	 */
	public function update_product( array $args ) {
		$product = $this->load_product( $args, 'edit_post' );
		if ( isset( $args['name'] ) ) {
			$product->set_name( (string) $args['name'] );
		}
		$this->apply_product_fields( $product, $args );
		$product->save();
		$this->apply_product_categories( $product, $args );
		return $this->format_product( wc_get_product( $product->get_id() ), true );
	}

	/**
	 * Delete product.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When missing.
	 */
	public function delete_product( array $args ) {
		$product = $this->load_product( $args, 'delete_post' );
		$id      = $product->get_id();
		$product->delete( ! empty( $args['force'] ) );
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	/**
	 * List orders.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function list_orders( array $args ) {
		$query = array(
			'limit'    => $this->per_page( $args['per_page'] ?? null ),
			'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'paginate' => true,
		);
		if ( ! empty( $args['status'] ) ) {
			$query['status'] = $args['status'];
		}
		if ( ! empty( $args['customer'] ) ) {
			$query['customer_id'] = (int) $args['customer'];
		}
		$results = wc_get_orders( $query );
		$orders  = array();
		foreach ( $results->orders as $o ) {
			$orders[] = $this->format_order( $o, false );
		}
		return array(
			'total'  => (int) $results->total,
			'orders' => $orders,
		);
	}

	/**
	 * Get order.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When missing.
	 */
	public function get_order( array $args ) {
		$order = $this->load_order( $args, 'read_post' );
		return $this->format_order( $order, true );
	}

	/**
	 * Update order status.
	 *
	 * @param array $args Args.
	 * @return array
	 * @throws \Exception When missing.
	 */
	public function update_order_status( array $args ) {
		$order = $this->load_order( $args, 'edit_post' );
		$order->update_status( (string) $this->required( $args, 'status' ), $args['note'] ?? '', true );
		return $this->format_order( wc_get_order( $order->get_id() ), false );
	}

	/**
	 * Apply scalar product fields.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $args    Args.
	 */
	private function apply_product_fields( $product, array $args ) {
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( (string) $args['regular_price'] );
		}
		if ( isset( $args['sale_price'] ) ) {
			$product->set_sale_price( (string) $args['sale_price'] );
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( (string) $args['description'] );
		}
		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( (string) $args['short_description'] );
		}
		if ( isset( $args['sku'] ) ) {
			$product->set_sku( (string) $args['sku'] );
		}
		if ( isset( $args['status'] ) ) {
			$product->set_status( (string) $args['status'] );
		}
		if ( isset( $args['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $args['manage_stock'] );
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
		}
		if ( ! empty( $args['image_id'] ) ) {
			$product->set_image_id( (int) $args['image_id'] );
		}
	}

	/**
	 * Apply categories (names or IDs) to a product.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $args    Args.
	 */
	private function apply_product_categories( $product, array $args ) {
		if ( empty( $args['categories'] ) || ! is_array( $args['categories'] ) ) {
			return;
		}
		$ids = array();
		foreach ( $args['categories'] as $cat ) {
			if ( is_numeric( $cat ) ) {
				$ids[] = (int) $cat;
				continue;
			}
			$term = get_term_by( 'name', $cat, 'product_cat' );
			if ( ! $term ) {
				$new = wp_insert_term( $cat, 'product_cat' );
				if ( ! is_wp_error( $new ) ) {
					$ids[] = (int) $new['term_id'];
				}
			} else {
				$ids[] = (int) $term->term_id;
			}
		}
		wp_set_object_terms( $product->get_id(), $ids, 'product_cat' );
	}

	/**
	 * Format a product.
	 *
	 * @param \WC_Product $p    Product.
	 * @param bool        $full Full detail.
	 * @return array
	 */
	private function format_product( $p, $full ) {
		$data = array(
			'id'            => $p->get_id(),
			'name'          => $p->get_name(),
			'type'          => $p->get_type(),
			'status'        => $p->get_status(),
			'sku'           => $p->get_sku(),
			'price'         => $p->get_price(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'    => $p->get_sale_price(),
			'stock_status'  => $p->get_stock_status(),
			'stock_quantity'=> $p->get_stock_quantity(),
			'permalink'     => $p->get_permalink(),
		);
		if ( $full ) {
			$data['description']       = $p->get_description();
			$data['short_description'] = $p->get_short_description();
			$data['categories']        = wp_get_object_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			$data['image_url']         = wp_get_attachment_url( $p->get_image_id() );
		}
		return $data;
	}

	/**
	 * Format an order.
	 *
	 * @param \WC_Order $o    Order.
	 * @param bool      $full Full detail.
	 * @return array
	 */
	private function format_order( $o, $full ) {
		$data = array(
			'id'       => $o->get_id(),
			'number'   => $o->get_order_number(),
			'status'   => $o->get_status(),
			'total'    => $o->get_total(),
			'currency' => $o->get_currency(),
			'customer' => $o->get_customer_id(),
			'email'    => $o->get_billing_email(),
			'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
		);
		if ( $full ) {
			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = array(
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'total'    => $item->get_total(),
					'product_id' => $item->get_product_id(),
				);
			}
			$data['items']            = $items;
			$data['billing_address']  = $o->get_formatted_billing_address();
			$data['shipping_address'] = $o->get_formatted_shipping_address();
			$data['payment_method']   = $o->get_payment_method_title();
		}
		return $data;
	}

	/**
	 * Load a product the current user may act on.
	 *
	 * Every other tool group routes object access through Abstract_Tool's
	 * assert_* helpers; these did not, and relied on the blanket capability the
	 * registry checks. That is enough for a shop manager but not for the custom
	 * roles WooCommerce installs collect: edit_products without
	 * edit_others_products is a common combination, and it was granting write
	 * access to everyone's products. WooCommerce's own REST API checks the
	 * object, so this only brings the bridge back in line with it.
	 *
	 * @param array  $args Args.
	 * @param string $cap  Meta capability to check against the product.
	 * @return \WC_Product
	 * @throws \Exception When missing or not permitted.
	 */
	private function load_product( array $args, $cap = 'edit_post' ) {
		$id      = (int) $this->required( $args, 'id' );
		$product = wc_get_product( $id );
		if ( ! $product ) {
			throw new \Exception( 'Product not found.' );
		}
		$this->assert_can( $cap, $product->get_id() );
		return $product;
	}

	/**
	 * Load an order the current user may act on.
	 *
	 * Orders carry customer personal data — billing and shipping addresses,
	 * email, phone — so the per-object check matters more here than anywhere
	 * else in this group.
	 *
	 * @param array  $args Args.
	 * @param string $cap  Meta capability to check against the order.
	 * @return \WC_Order
	 * @throws \Exception When missing or not permitted.
	 */
	private function load_order( array $args, $cap = 'edit_post' ) {
		$id    = (int) $this->required( $args, 'id' );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			throw new \Exception( 'Order not found.' );
		}
		$this->assert_can( $cap, $order->get_id() );
		return $order;
	}
}
