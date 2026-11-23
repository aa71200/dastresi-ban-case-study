<?php
/**
 * Request-local WooCommerce order/purchase index.
 *
 * @package MA_CO_AccessControlPro\Features\WooCommerce
 */

namespace MA_CO_AccessControlPro\Features\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shares an order lookup between product checks without changing query inputs.
 */
final class WooCommercePurchaseIndex {

	/** @var array<string,array<int,array<int,array<string,int>>>> */
	private static $purchases = [];

	/**
	 * Return purchases ordered by their effective payment timestamp.
	 *
	 * The public limit and paid-status filters are evaluated for every product,
	 * exactly as before. Only identical resulting order queries share work.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $product_id Product or variation ID.
	 * @param string $order_direction ASC or DESC.
	 * @return array
	 */
	public static function find( $user_id, $product_id, $order_direction = 'DESC' ) {
		$user_id         = absint( $user_id );
		$product_id      = absint( $product_id );
		$order_direction = 'ASC' === strtoupper( (string) $order_direction ) ? 'ASC' : 'DESC';

		if ( ! $user_id || ! $product_id ) {
			return [];
		}

		$limit    = apply_filters( 'ma_co_acp_wc_validity_order_lookup_limit', -1, $user_id, $product_id );
		$statuses = WooCommerceAccessValidityService::get_paid_order_statuses();
		$key      = md5( serialize( [ $user_id, $statuses, $order_direction, $limit ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		if ( ! array_key_exists( $key, self::$purchases ) ) {
			self::$purchases[ $key ] = self::index_orders(
				[
					'customer_id' => $user_id,
					'status'      => $statuses,
					'orderby'     => 'date',
					'order'       => $order_direction,
					'limit'       => $limit,
					'return'      => 'objects',
				]
			);

		}

		return isset( self::$purchases[ $key ][ $product_id ] )
			? self::$purchases[ $key ][ $product_id ]
			: [];
	}

	/** Legacy rules retain WooCommerce's email OR account identity and paid statuses. */
	public static function find_legacy( $user_id, $product_id, $email ) {
		$customers = array_values( array_filter( [ absint( $user_id ), sanitize_email( $email ) ] ) );
		$statuses  = wc_get_is_paid_statuses();
		$key       = md5( serialize( [ 'legacy', $customers, $statuses ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		if ( ! isset( self::$purchases[ $key ] ) ) {
			self::$purchases[ $key ] = self::index_orders( [
				'customer' => $customers, 'status' => $statuses, 'limit' => -1,
				'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects',
			] );
		}
		return self::$purchases[ $key ][ absint( $product_id ) ] ?? [];
	}

	/**
	 * Clear request-local data, primarily for long-running workers and tests.
	 *
	 * @return void
	 */
	public static function reset_request_cache() {
		self::$purchases = [];
	}

	/**
	 * Build product and variation indexes from an order result.
	 *
	 * @param array $query WooCommerce order query arguments.
	 * @return array Purchase rows grouped by product.
	 */
	private static function index_orders( array $query ) {
		$index = [];
		foreach ( self::order_pages( $query ) as $orders ) {
			self::index_page( $orders, $index );
		}
		$direction = $query['order'];
		foreach ( $index as &$purchases ) {
			usort( $purchases, static function ( $a, $b ) use ( $direction ) {
				$order = ( $a['purchased_at'] <=> $b['purchased_at'] ) ?: ( $a['order_id'] <=> $b['order_id'] );
				return 'ASC' === $direction ? $order : -$order;
			} );
		}
		unset( $purchases );
		return $index;
	}

	private static function order_pages( array $query ): \Generator {
		if ( -1 !== (int) $query['limit'] ) {
			yield (array) wc_get_orders( $query );
			return;
		}
		$query['orderby'] = 'ID';
		$query['order'] = 'ASC';
		$query['limit'] = 100;
		for ( $page = 1; ; ++$page ) {
			$query['paged'] = $page;
			$orders = (array) wc_get_orders( $query );
			yield $orders;
			if ( count( $orders ) < $query['limit'] ) {
				return;
			}
		}
	}

	private static function index_page( array $orders, array &$index ): void {
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$date = $order->get_date_paid();
			if ( ! $date ) {
				$date = $order->get_date_completed();
			}
			if ( ! $date ) {
				$date = $order->get_date_created();
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$quantity = self::remaining_quantity( $order, $item );
				if ( $quantity <= 0 ) { continue; }
				$purchase = [
					'order_id'     => absint( $order->get_id() ),
					'purchased_at' => $date ? $date->getTimestamp() : time(),
					'quantity'     => $quantity,
				];
				$product_ids = array_values(
					array_unique(
						array_filter(
							[
								absint( $item->get_product_id() ),
								absint( $item->get_variation_id() ),
							]
						)
					)
				);

				foreach ( $product_ids as $indexed_product_id ) {
					$index[ $indexed_product_id ][] = $purchase;
				}
			}
		}

	}

	/** Ignore unallocated order refunds and tax-only refunds; only this line is authoritative. */
	private static function remaining_quantity( $order, $item ) {
		$quantity = max( 0, (int) $item->get_quantity() );
		if ( method_exists( $item, 'get_id' ) && method_exists( $order, 'get_qty_refunded_for_item' ) ) {
			$quantity = max( 0, $quantity + min( 0, (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
		}
		if ( $quantity && method_exists( $item, 'get_total' ) && method_exists( $order, 'get_total_refunded_for_item' ) ) {
			$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
			$total = round( (float) $item->get_total(), $decimals );
			$refunded = round( (float) $order->get_total_refunded_for_item( $item->get_id() ), $decimals );
			if ( $total > 0 && $refunded >= $total ) { return 0; }
		}
		return $quantity;
	}
}
