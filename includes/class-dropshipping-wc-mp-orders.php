<?php
/**
 * Class for handle Create/Update Orders on Knawat MP
 *
 * @link       http://knawat.com
 * @since      2.0.0
 *
 * @package    Knawat_Dropshipping_Woocommerce
 * @subpackage Knawat_Dropshipping_Woocommerce/includes
 */
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Knawat_Dropshipping_WC_MP_Orders Class
 */
class Knawat_Dropshipping_WC_MP_Orders {

	/**
	 * MP API Wrapper object
	 *
	 * @var integer
	 */
	protected $mp_api;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2.0.0
	 */
	public function __construct() {
		// Create Order at Front-end.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'knawatds_order_created' ), 10 );

		// Create/Update Order
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'knawatds_order_created_updated' ), 99 );
		//add_action( 'woocommerce_update_order', array( $this, 'knawatds_order_created_updated' ), 99 );
	}

	/**
	 * knawatds_order_created_updated will run on order create/update from backend.
	 * if it is knawat order than create/update order on Knawat MP API.
	 *
	 * @param  int    $order_id    The ID of the order that was just created.
	 *
	 * @return null
	 */
	function knawatds_order_created_updated( $order_id ) {

		$post_type = get_post_type( $order_id );
		if( 'shop_order' !== $post_type ){
			return;
		}

		$order = wc_get_order( $order_id );
		if( !empty( $order ) ){
			$is_knawat = get_post_meta( $order_id, '_knawat_order', true );
			if( 1 == $is_knawat ){
				$korder_id = get_post_meta( $order_id, '_knawat_order_id', true );
				if( $korder_id != '' ){

					$update_order_json = $this->knawat_format_order( $order_id, true );
					if( $update_order_json ){
						$this->mp_api = new Knawat_Dropshipping_Woocommerce_API();
						$result = $this->mp_api->put( 'orders/'.$korder_id, $update_order_json );
						$current_action = current_action();

						if( !is_wp_error( $result ) ){
							if( isset( $result->status ) && 'success' === $result->status ){
								$korder_id = $result->data->id;
							}
						}else{
							// WC log error.
							update_post_meta( $order_id, '_knawat_update_failed', true );
						}
					}

				}else{
					$this->knawatds_order_created( $order_id );
				}
			}
		}
	}

	/**
	 * knawatds_order_created will run on order create.
	 * if it is knawat order than create order on Knawat MP API.
	 *
	 * @param  int    $order_id    The ID of the order that was just created.
	 *
	 * @return null
	 */
	function knawatds_order_created( $order_id ) {

		$post_type = get_post_type( $order_id );
		if( 'shop_order' !== $post_type ){
			return;
		}

		$order = wc_get_order( $order_id );
		if( !empty( $order ) ){
			$is_knawat = get_post_meta( $order_id, '_knawat_order', true );
			if( 1 == $is_knawat ){

				$new_order_json = $this->knawat_format_order( $order_id );
				if( $new_order_json ){
					$this->mp_api = new Knawat_Dropshipping_Woocommerce_API();
					$result = $this->mp_api->post( 'orders', $new_order_json );

					if( !is_wp_error( $result ) ){
						if( isset( $result->status ) && 'success' === $result->status ){
							$korder_id = $result->data->id;
							update_post_meta( $order_id, '_knawat_order_id', $korder_id );
							delete_post_meta( $order_id, '_knawat_sync_failed' );
						}else{
							update_post_meta( $order_id, '_knawat_sync_failed', true );
						}
					}else{
						update_post_meta( $order_id, '_knawat_sync_failed', true );
					}
				}
			}
		}
	}

	/**
	 * Get formated order Json for knawat mp API
	 *
	 * @param  int     $order_id    The ID of the order that was just created.
	 * @param  boolean $is_update   format order for update or not.
	 * @param  boolean $json        return data should json or array.
	 *
	 * @return string|Array  formatted order json.
	 */
	function knawat_format_order( $order_id, $is_update = false, $json = true ){

		if( empty( $order_id ) ){
			return false;
		}

		$request    = new WP_REST_Request( 'GET' );
		$controller = new WC_REST_Orders_Controller();

		// Set Order ID.
		$request->set_param( 'id', $order_id );
		$result  = $controller->get_item( $request );
		$order = isset( $result->data ) ? $result->data : array();
		$order_whitelist_fields = array( 'id', 'status', 'line_items', 'billing', 'shipping', 'pdf_invoice_url' );
		$item_whitelist_fields = array( 'id', 'sku' );
		$new_order = array();

		$search_order = array( 'line_items', 'pdf_invoice_url' );
		$replace_order = array( 'items', 'invoice_url' );
		foreach ( $order as $key => $value) {
			if( in_array( $key, $order_whitelist_fields ) ){

				$key = str_replace( $search_order, $replace_order, $key );
				$new_order[$key] = $value;
			}
		}

		if( isset( $new_order['items'] ) && !empty( $new_order['items'] ) ){
			foreach ( $new_order['items'] as $itemkey => $item ) {
				foreach ($item as $ikey => $ivalue) {
					if( !in_array( $ikey, $item_whitelist_fields ) ){
						unset( $new_order['items'][$itemkey][$ikey] );
					}
				}
			}
		}

		// Add Email and phone into Shipping.
		$new_order['shipping']['email'] = $new_order['billing']['email'];
		$new_order['shipping']['phone'] = $new_order['billing']['phone'];

		if( $json ){
			$new_order = json_encode( $new_order );
		}
		return $new_order;
	}

}

$mp_orders = new Knawat_Dropshipping_WC_MP_Orders();