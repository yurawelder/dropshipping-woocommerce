<?php
/**
 * Common functions class for Knawat Dropshipping Woocommerce.
 *
 * @link       http://knawat.com/
 * @since      1.0.0
 *
 * @package    Knawat_Dropshipping_Woocommerce
 * @subpackage Knawat_Dropshipping_Woocommerce/includes
 */
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Knawat_Dropshipping_Woocommerce_Common {

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Do anything Here. 
		add_action( 'knawat_dropshipwc_run_product_import', array( $this, 'knawat_dropshipwc_backgorund_product_importer' ) );
		add_action( 'admin_init', array( $this, 'handle_knawat_settings_submit' ), 99 );
		add_action( 'woocommerce_add_to_cart',  array( $this, 'knawat_dropshipwc_add_to_cart' ), 10, 2 );
		add_action( 'woocommerce_before_single_product', array( $this, 'knawat_dropshipwc_before_single_product' ) );
		add_action( 'knawat_dropshipwc_validate_access_token', array( $this, 'validate_access_token' ) );
		add_action( 'admin_init', array( $this, 'maybe_display_access_token_warning' ) );
		// add_action( 'admin_init', array( $this, 'display_knawat_persistent_notices' ) );
		add_action( 'wp_ajax_knawat_dismiss_admin_notice', array( $this, 'knawat_dismiss_admin_notice' ) );
	}

	/**
	 * Check is WooCommerce Activate or not.
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function knawat_dropshipwc_is_woocommerce_activated() {
		if( !function_exists( 'is_plugin_active' ) ){
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Check is WooCommerce Activate or not.
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function knawat_dropshipwc_is_woomulti_currency_activated() {
		if( !function_exists( 'is_plugin_active' ) ){
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		if ( is_plugin_active( 'woocommerce-currency-switcher/index.php' ) || is_plugin_active( 'currency-switcher-woocommerce/currency-switcher-woocommerce.php' ) || is_plugin_active( 'woocommerce-multilingual/wpml-woocommerce.php' ) || is_plugin_active( 'woo-multi-currency/woo-multi-currency.php' ) ) {
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Check order contains knawat products or not.
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function is_knawat_order( $order_id ) {
		if( empty( $order_id ) ){ return false; }

		$order = wc_get_order( $order_id );
		$items = $order->get_items();
		foreach ( $items as $item ) {
			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$product_id = $item->get_product_id();
				$dropshipping = get_post_meta( $product_id, 'dropshipping', true );
				if( $dropshipping == 'knawat' ){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Knawat Run Background import. This function is called by hourly cron.
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function knawat_dropshipwc_backgorund_product_importer(){
		$consumer_keys = knawat_dropshipwc_get_consumerkeys();
		if( empty( $consumer_keys ) ){
			return;
		}

		if ( ! class_exists( 'Knawat_Dropshipping_WC_Background', false ) ) {
			return;
		}

		// Validate access token
		do_action( 'knawat_dropshipwc_validate_access_token' );

		global $wpdb;
		$count_query = "SELECT count(option_id) as count FROM {$wpdb->options} WHERE option_name LIKE '%kdropship_import_batch_%' ORDER BY option_id ASC";
		$count = $wpdb->get_var( $count_query );

		if( $count > 0 ){
			global $knawatdswc_errors;
			$knawatdswc_errors[] = __( 'Another product import is in process already.', 'dropshipping-woocommerce' );
			return false;
		}

		$data = array();
		$data['limit'] = 10;
		$import_process = new Knawat_Dropshipping_WC_Background();
		$import_process->push_to_queue( $data );
		$import_process->save()->dispatch();
	}

	/**
	 * Process settings form and save settings.
	 *
	 * @since    2.0.0
	 */
	public function handle_knawat_settings_submit() {
		global $knawatdswc_success;
		if ( isset( $_POST['knawatds_action'] ) && $_POST['knawatds_action'] == 'knawatds_save_settings' ) {
			// Verify nonce.
			check_admin_referer( 'knawatds_setting_form_nonce_action', 'knawatds_setting_form_nonce' );

			$knawatds_options = isset( $_POST['knawat'] ) ? $_POST['knawat'] : array();
			$current_options = knawat_dropshipwc_get_options();
			if( isset( $knawatds_options['mp_consumer_key'] ) ){
				$current_options['mp_consumer_key'] = sanitize_text_field( $knawatds_options['mp_consumer_key'] );
			}
			if( isset( $knawatds_options['mp_consumer_secret'] ) ){
				$current_options['mp_consumer_secret'] = sanitize_text_field( $knawatds_options['mp_consumer_secret'] );
			}
			knawat_dropshipwc_update_options( $current_options );

			// Validate access token on keys.
			do_action( 'knawat_dropshipwc_validate_access_token' );
			$knawatdswc_success[] = __( 'Settings has been saved successfully.', 'dropshipping-woocommerce' );
		}
	}

	/**
	 * Sync prduct with knawat.com during product add_to_cart
	 *
	 * @since 2.0.0
	 */
	public function knawat_dropshipwc_add_to_cart( $cart_item_key, $product_id ){
		if( empty( $product_id ) ){
			return;
		}
		// Update product
		$this->knawat_dropshipwc_async_product_update_by_id( $product_id );
	}

	/**
	 * Sync prduct with knawat.com during custmer visit single product page
	 *
	 * @since 2.0.0
	 */
	public function knawat_dropshipwc_before_single_product(){
		$product_id = get_the_ID();
		if( empty( $product_id ) ){
			return;
		}
		// Update product
		$this->knawat_dropshipwc_async_product_update_by_id( $product_id );
	}

	/**
	 * Run Async call for Sync prduct with knawat.com by product_id
	 *
	 * @param $product_id 			int  Product ID.
	 *
	 * @since 2.0.0
	 */
	public function knawat_dropshipwc_async_product_update_by_id( $product_id ){

		if( empty( $product_id ) ){
			return;
		}

		$product = new WC_Product( $product_id );
		$sku = $product->get_sku();

		if( empty( $sku ) ){
			return;
		}

		if ( ! class_exists( 'Knawat_Dropshipping_WC_Async_Request', false ) ) {
			return;
		}

		// Async Product Update.
		$async_request = new Knawat_Dropshipping_WC_Async_Request();
		$async_request->data( array( 'sku' => $sku ) );
		$async_request->dispatch();
	}

	/**
	 * Sync prduct with knawat.com by Sku
	 *
	 * @param $sku 			string  Product SKU.
	 * @param $force_update boolean True if is force update.
	 * @return array Import status.
	 *
	 * @since 2.0.0
	 */
	function knawat_dropshipwc_import_product_by_sku( $sku, $force_update = false ){
		if( empty( $sku ) ){
			return false;
		}
		$sku = sanitize_text_field( $sku );
		$importer = new Knawat_Dropshipping_Woocommerce_Importer( 'single', array( 'sku' => $sku, 'limit' => 1, 'force_update' => $force_update ) );
		$import = $importer->import();
		return $import;
	}

	/**
    * Validate Access Token
    *
    *
    * @access public
    * @return string
    */
    public function validate_access_token() {

		$current_options = knawat_dropshipwc_get_options();
		// Remove knawat_mp_access_channel transient for re-fetch token
		$mp_api = new Knawat_Dropshipping_Woocommerce_API();
		delete_transient( 'knawat_mp_access_channel' );
		$token = $mp_api->get_access_token();
		if( !empty( $token ) ){
			$current_options['token_status'] = 'valid';
		}else{
			$current_options['token_status'] = 'invalid';
		}
		knawat_dropshipwc_update_options( $current_options );
    }

    /**
	 * Check if access token is not valid and display warning if token is not valid.
	 *
	 * @since    2.0.0
	 * @return 	 void
	 */
	public function maybe_display_access_token_warning() {
		$knawat_options = knawat_dropshipwc_get_options();
		$token_status = isset( $knawat_options['token_status'] ) ? esc_attr( $knawat_options['token_status'] ) : '';
		if( 'invalid' === $token_status ){
			if( ( isset( $_GET['page'] ) && 'knawat_dropship' === sanitize_text_field( $_GET['page'] ) ) && ( isset( $_GET['tab'] ) && 'settings' === sanitize_text_field( $_GET['tab'] ) ) ){
				return;
			}
			global $knawat_dropshipwc, $knawatdswc_warnings;
			$knawatdswc_warnings[] = sprintf( '%s <a href="' . esc_url( add_query_arg( 'tab', 'settings', $knawat_dropshipwc->admin->adminpage_url ) ) . '" >%s</a>',
										__('Your connection with knawat.com has been disconnected. Please check and verify your knawat consumer keys from', 'dropshipping-woocommerce' ),
										__('<strong>Knawat Dropshipping</strong> > <strong>Settings</strong>.', 'dropshipping-woocommerce' )
									);
		}
	}

	/**
	 * Dismiss Admin notice for Forever
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function knawat_dismiss_admin_notice() {
		$notice_type = sanitize_text_field( $_POST['notice_type'] );
		if ( $notice_type != '' ) {
			$notice_type = sanitize_key( $notice_type );
			check_ajax_referer( 'kdropshipping_nonce', 'nonce' );
			update_option( $notice_type, 'dismissed' );
		}
		wp_die();
	}

	/**
	 * Dismiss Admin notice for Forever
	 *
	 * @since    1.0.0
	 * @return 	 boolean
	 */
	public function is_admin_notice_active( $notice_type ) {
		if ( empty( $notice_type ) ) {
			return false;
		}
		$notice_type = sanitize_key( $notice_type );
		$is_active = get_option( $notice_type, false );
		if( $is_active ){
			return false;
		}
		return true;
	}

	/**
	 * Display persistent notices.
	 *
	 * @since    2.0.0
	 * @return 	 void
	 */
	public function display_knawat_persistent_notices( $remove = true ) {
		$persistent_notices = get_transient('knawat_persistent_notices');
		if( !empty( $persistent_notices ) ){
			global $knawatdswc_errors, $knawatdswc_success, $knawatdswc_warnings;
			if( isset( $persistent_notices['errors'] ) && is_array( $persistent_notices['errors'] ) ){
				$knawatdswc_errors = array_merge( $knawatdswc_errors, $persistent_notices['errors'] );
			}
			if( isset( $persistent_notices['success'] ) && is_array( $persistent_notices['success'] ) ){
				$knawatdswc_success = array_merge( $knawatdswc_success, $persistent_notices['success'] );
			}
			if( isset( $persistent_notices['warnings'] ) && is_array( $persistent_notices['warnings'] ) ){
				$knawatdswc_warnings = array_merge( $knawatdswc_warnings, $persistent_notices['warnings'] );
			}
		}
		delete_transient( 'knawat_persistent_notices' );
	}

}

/*
 * Woocommerce WebHooks Utilities
 */
add_filter( 'http_request_args', function( $args ) {
    $args['reject_unsafe_urls'] = false;

    return $args;
});

/*
 * Store is contected to knawat.com or not
 */
function knawat_dropshipwc_is_connected(){
	global $wpdb, $knawat_dropshipwc;

	if( $knawat_dropshipwc->is_woocommerce_activated() ){
		$t_api_keys = $wpdb->prefix.'woocommerce_api_keys';
		$api_key_query = "SELECT COUNT( key_id ) as count FROM {$t_api_keys} WHERE `description` LIKE '%Knawat - API%'";
		$key_count = $wpdb->get_var( $api_key_query );
		if( $key_count > 0 ){
			return true;
		}
	}

	return false;
}

/**
 * Get Defined DropShippers.
 *
 * @access public
 * @since 1.2.0
 * @return array
 */
function knawat_dropshipwc_get_dropshippers() {
	global $knawat_dropshipwc;
    $dropshippers = $knawat_dropshipwc->get_dropshippers();
    if( empty( $dropshippers ) && !is_array( $dropshippers ) ){
		$dropshippers = array();
    }
    return $dropshippers;
}

/**
 * Get Knawat DropShipping Options.
 *
 * @access public
 * @since 2.0.0
 * @return array
 */
function knawat_dropshipwc_get_options( $key = '' ) {
	$knawat_options = get_option( KNAWAT_DROPWC_OPTIONS, array() );
	if ( $key != '' ) {
		$knawat_options = isset( $knawat_options[$key] ) ? $knawat_options[$key] : '';
	}
	return $knawat_options;
}

/**
 * Update Knawat DropShipping Options.
 *
 * @access public
 * @since 2.0.0
 * @return array
 */
function knawat_dropshipwc_update_options( $knawat_options ) {
	update_option( KNAWAT_DROPWC_OPTIONS, $knawat_options );
}

/**
 * Get Knawat MP Consumer Keys
 *
 * @return array Knawat MP Consumer Keys
 */
function knawat_dropshipwc_get_consumerkeys(){
	$consumer_keys  = array();
	$knawat_options = knawat_dropshipwc_get_options();
	if( isset( $knawat_options['mp_consumer_key'] ) && !empty( $knawat_options['mp_consumer_key'] ) ){
		$consumer_keys['consumer_key'] = $knawat_options['mp_consumer_key'];
	}
	if( isset( $knawat_options['mp_consumer_secret'] ) && !empty( $knawat_options['mp_consumer_secret'] ) ){
		$consumer_keys['consumer_secret'] = $knawat_options['mp_consumer_secret'];
	}
	return $consumer_keys;
}

/**
 * Get Knawat Compatible Active plugins
 *
 * @return array Knawat Compatible plugins with status.
 */
function knawat_dropshipwc_get_activated_plugins(){
	$active_plugins = array(
		'featured-image-by-url' =>false,
		'woocommerce-currency-switcher' =>false,
		'qtranslate-x' =>false		
	);

	$blog_plugins = get_option( 'active_plugins', array() );
	$site_plugins = is_multisite() ? (array) maybe_unserialize( get_site_option('active_sitewide_plugins' ) ) : array();
	
	// Check if qTranslate X is activated
	if ( in_array( 'qtranslate-x/qtranslate.php', $blog_plugins ) || isset( $site_plugins['qtranslate-x/qtranslate.php'] ) ) {
		$active_plugins['qtranslate-x'] = true;
	}

	// Check if Featued image by URL is activated
	if ( in_array( 'featured-image-by-url/featured-image-by-url.php', $blog_plugins ) || isset( $site_plugins['featured-image-by-url/featured-image-by-url.php'] ) ) {
		$active_plugins['featured-image-by-url'] = true;
	}

	// Check if WooCommerce Currency Switcher is activated
	if ( in_array( 'woocommerce-currency-switcher/index.php', $blog_plugins ) || isset( $site_plugins['woocommerce-currency-switcher/index.php'] ) ) {
		$active_plugins['woocommerce-currency-switcher'] = true;
	}

	return $active_plugins;
}

/**
 * Check if order is for knawat local DS.
 *
 * @param  int    $order_id    The ID of the order
 *
 * @return string|bool
 */
function knawat_dropshipwc_is_order_local_ds( $order_id ){
	if( empty( $order_id ) ){ return false; }
	$knawat_order_ds = get_post_meta( $order_id, '_knawat_order_ds', true );
	$dropshippers = knawat_dropshipwc_get_dropshippers();
	if( !empty( $knawat_order_ds ) && isset( $dropshippers[$knawat_order_ds] ) ){
		return true;
	}
	return false;
}

/**
 * Check if Access token is valid or not.
 *
 * @return bool
 */
function knawat_dropshipwc_is_access_token_valid(){
	$knawat_options = knawat_dropshipwc_get_options();
	$token_status = isset( $knawat_options['token_status'] ) ? esc_attr( $knawat_options['token_status'] ) : '';
	if( 'valid' === $token_status ){
		return true;
	}
	return false;
}

/**
 * Log Errors and warning using WooCommerce Logger
 *
 * @return void
 */
function knawat_dropshipwc_logger( $message, $type = 'error' ){
	if( function_exists( 'wc_get_logger' ) && $message != '' ){
		$logger = wc_get_logger();
		$context = array( 'source' => 'dropshipping-woocommerce' );
		$logger->log( $type, $message, $context );
	}
}

/**
 * Knawat Set persistent notices (which is used to show after page redirect).
 *
 * @return void
 */
function knawat_set_notices( $messages ){
	if( !empty( $messages ) ){
		set_transient( 'knawat_persistent_notices', $messages, 5 * MINUTE_IN_SECONDS );
	}
}
