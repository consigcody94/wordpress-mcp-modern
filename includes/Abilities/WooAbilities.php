<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * WooCommerce capabilities: the legacy wordpress-mcp Woo toolset plus product
 * brands (core taxonomy since WooCommerce 9.4) and full order CRUD. The entire
 * group is gated on WooCommerce being active: definitions() returns an empty
 * list when WooCommerce is absent, so nothing is registered or advertised.
 */
final class WooAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			RestProxyAbility::register( $def );
		}
	}

	private static function woo_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Definitions, gated on WooCommerce being active.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		return self::woo_active() ? self::catalog() : array();
	}

	/**
	 * The raw (ungated) tool catalog. Split from definitions() so the shape and
	 * naming of every definition stays testable without WooCommerce installed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function catalog(): array {
		$ns   = AbilityRegistrar::NS;
		$cap  = 'manage_woocommerce';
		$defs = array();

		// --- Products ---
		$defs[] = array(
			'name'         => "$ns/wc-products-search",
			'mcp_name'     => 'wc_products_search',
			'label'        => 'Search products',
			'description'  => 'Search and list WooCommerce products.',
			'type'         => 'read',
			'method'       => 'GET',
			'route'        => '/wc/v3/products',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string' ),
					'sku'      => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'pending', 'private', 'publish' ), 'default' => 'any' ),
					'category' => array( 'type' => 'string', 'description' => 'Category ID(s), comma-separated.' ),
					'tag'      => array( 'type' => 'string', 'description' => 'Tag ID(s), comma-separated.' ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'orderby'  => array( 'type' => 'string', 'enum' => array( 'date', 'id', 'title', 'price', 'popularity', 'rating' ), 'default' => 'date' ),
					'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-get-product",
			'mcp_name'     => 'wc_get_product',
			'label'        => 'Get product',
			'description'  => 'Retrieve a single WooCommerce product by ID.',
			'type'         => 'read',
			'method'       => 'GET',
			'route'        => '/wc/v3/products/{id}',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
		);

		$product_fields = array(
			'name'              => array( 'type' => 'string', 'description' => 'Product name.' ),
			'type'              => array( 'type' => 'string', 'enum' => array( 'simple', 'grouped', 'external', 'variable' ), 'default' => 'simple' ),
			'status'            => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'publish' ),
			'regular_price'     => array( 'type' => 'string', 'description' => 'Regular price as a decimal string, e.g. "19.99".' ),
			'sale_price'        => array( 'type' => 'string' ),
			'description'       => array( 'type' => 'string' ),
			'short_description' => array( 'type' => 'string' ),
			'sku'               => array( 'type' => 'string' ),
			'manage_stock'      => array( 'type' => 'boolean' ),
			'stock_quantity'    => array( 'type' => 'integer' ),
		);
		$defs[] = array(
			'name'         => "$ns/wc-add-product",
			'mcp_name'     => 'wc_add_product',
			'label'        => 'Add product',
			'description'  => 'Create a new WooCommerce product.',
			'type'         => 'create',
			'method'       => 'POST',
			'route'        => '/wc/v3/products',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => $product_fields,
				'required'   => array( 'name' ),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-update-product",
			'mcp_name'     => 'wc_update_product',
			'label'        => 'Update product',
			'description'  => 'Update an existing WooCommerce product by ID.',
			'type'         => 'update',
			'method'       => 'PUT',
			'route'        => '/wc/v3/products/{id}',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $product_fields ),
				'required'   => array( 'id' ),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-delete-product",
			'mcp_name'     => 'wc_delete_product',
			'label'        => 'Delete product',
			'description'  => 'Delete a WooCommerce product by ID.',
			'type'         => 'delete',
			'method'       => 'DELETE',
			'route'        => '/wc/v3/products/{id}',
			'capability'   => $cap,
			'extra_params' => array( 'force' => true ),
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
		);

		// --- Product categories, tags & brands (term CRUD) ---
		// Brands ship in WooCommerce core since 9.4 (/wc/v3/products/brands);
		// on older versions the call returns a clean rest_no_route error.
		foreach (
			array(
				array( 'route' => 'products/categories', 'sing' => 'product_category', 'plur' => 'product_categories', 'word' => 'product category' ),
				array( 'route' => 'products/tags', 'sing' => 'product_tag', 'plur' => 'product_tags', 'word' => 'product tag' ),
				array( 'route' => 'products/brands', 'sing' => 'product_brand', 'plur' => 'product_brands', 'word' => 'product brand' ),
			) as $tax
		) {
			$sing_slug = str_replace( '_', '-', $tax['sing'] );
			$plur_slug = str_replace( '_', '-', $tax['plur'] );

			$defs[] = array(
				'name'         => "$ns/wc-list-{$plur_slug}",
				'mcp_name'     => "wc_list_{$tax['plur']}",
				'label'        => "List {$tax['word']}s",
				'description'  => "List WooCommerce {$tax['word']} terms.",
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => "/wc/v3/{$tax['route']}",
				'capability'   => $cap,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wc-add-{$sing_slug}",
				'mcp_name'     => "wc_add_{$tax['sing']}",
				'label'        => "Add {$tax['word']}",
				'description'  => "Create a WooCommerce {$tax['word']}.",
				'type'         => 'create',
				'method'       => 'POST',
				'route'        => "/wc/v3/{$tax['route']}",
				'capability'   => $cap,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'name' ),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wc-update-{$sing_slug}",
				'mcp_name'     => "wc_update_{$tax['sing']}",
				'label'        => "Update {$tax['word']}",
				'description'  => "Update a WooCommerce {$tax['word']} by ID.",
				'type'         => 'update',
				'method'       => 'PUT',
				'route'        => "/wc/v3/{$tax['route']}/{id}",
				'capability'   => $cap,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
			);
			$defs[] = array(
				'name'         => "$ns/wc-delete-{$sing_slug}",
				'mcp_name'     => "wc_delete_{$tax['sing']}",
				'label'        => "Delete {$tax['word']}",
				'description'  => "Delete a WooCommerce {$tax['word']} by ID.",
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => "/wc/v3/{$tax['route']}/{id}",
				'capability'   => $cap,
				'extra_params' => array( 'force' => true ),
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
			);
		}

		// --- Orders ---
		$defs[] = array(
			'name'         => "$ns/wc-orders-search",
			'mcp_name'     => 'wc_orders_search',
			'label'        => 'Search orders',
			'description'  => 'Search and list WooCommerce orders.',
			'type'         => 'read',
			'method'       => 'GET',
			'route'        => '/wc/v3/orders',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string', 'description' => 'Order status slug (e.g. processing, completed).' ),
					'customer' => array( 'type' => 'integer', 'description' => 'Customer user ID.' ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-get-order",
			'mcp_name'     => 'wc_get_order',
			'label'        => 'Get order',
			'description'  => 'Retrieve a single WooCommerce order by ID.',
			'type'         => 'read',
			'method'       => 'GET',
			'route'        => '/wc/v3/orders/{id}',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
		);

		$address_schema = array(
			'type'        => 'object',
			'description' => 'Address object: first_name, last_name, company, address_1, address_2, city, state, postcode, country (and email/phone on billing).',
		);
		$order_fields   = array(
			'status'        => array( 'type' => 'string', 'description' => 'Order status slug (e.g. pending, processing, completed, cancelled).' ),
			'customer_id'   => array( 'type' => 'integer', 'description' => 'Customer user ID (0 for guest).' ),
			'customer_note' => array( 'type' => 'string' ),
			'set_paid'      => array( 'type' => 'boolean', 'description' => 'Mark the order as paid (completes payment without a gateway).' ),
			'billing'       => $address_schema,
			'shipping'      => $address_schema,
		);
		$defs[]         = array(
			'name'         => "$ns/wc-add-order",
			'mcp_name'     => 'wc_add_order',
			'label'        => 'Add order',
			'description'  => 'Create a new WooCommerce order with optional line items.',
			'type'         => 'create',
			'method'       => 'POST',
			'route'        => '/wc/v3/orders',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					$order_fields,
					array(
						'line_items' => array(
							'type'        => 'array',
							'description' => 'Products to add to the order.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'product_id'   => array( 'type' => 'integer' ),
									'variation_id' => array( 'type' => 'integer' ),
									'quantity'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
								),
								'required'   => array( 'product_id' ),
							),
						),
					)
				),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-update-order",
			'mcp_name'     => 'wc_update_order',
			'label'        => 'Update order',
			'description'  => 'Update an existing WooCommerce order by ID (status, note, addresses, paid state).',
			'type'         => 'update',
			'method'       => 'PUT',
			'route'        => '/wc/v3/orders/{id}',
			'capability'   => $cap,
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array_merge( array( 'id' => array( 'type' => 'integer' ) ), $order_fields ),
				'required'   => array( 'id' ),
			),
		);
		$defs[] = array(
			'name'         => "$ns/wc-delete-order",
			'mcp_name'     => 'wc_delete_order',
			'label'        => 'Delete order',
			'description'  => 'Permanently delete a WooCommerce order by ID.',
			'type'         => 'delete',
			'method'       => 'DELETE',
			'route'        => '/wc/v3/orders/{id}',
			'capability'   => $cap,
			'extra_params' => array( 'force' => true ),
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
		);

		// --- Reports (read) ---
		$reports = array(
			'sales'            => 'reports/sales',
			'orders_totals'    => 'reports/orders/totals',
			'products_totals'  => 'reports/products/totals',
			'customers_totals' => 'reports/customers/totals',
			'coupons_totals'   => 'reports/coupons/totals',
			'reviews_totals'   => 'reports/reviews/totals',
		);
		foreach ( $reports as $key => $route ) {
			$defs[] = array(
				'name'         => "$ns/wc-reports-" . str_replace( '_', '-', $key ),
				'mcp_name'     => "wc_reports_{$key}",
				'label'        => 'WooCommerce report: ' . str_replace( '_', ' ', $key ),
				'description'  => "Retrieve the WooCommerce {$key} report.",
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => "/wc/v3/{$route}",
				'capability'   => $cap,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'period'     => array( 'type' => 'string', 'enum' => array( 'week', 'month', 'last_month', 'year' ) ),
						'date_min'   => array( 'type' => 'string', 'description' => 'Start date (YYYY-MM-DD).' ),
						'date_max'   => array( 'type' => 'string', 'description' => 'End date (YYYY-MM-DD).' ),
					),
				),
			);
		}

		return $defs;
	}
}
