<?php
/**
 * Knawat MP Importer class.
 *
 * @link       http://knawat.com/
 * @since      2.0.0
 * @category   Class
 * @author 	   Dharmesh Patel
 *
 * @package    Knawat_Dropshipping_Woocommerce
 * @subpackage Knawat_Dropshipping_Woocommerce/includes
 */
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include dependencies.
 */
if ( ! class_exists( 'WC_Product_Importer', false ) ) {
	include_once dirname( WC_PLUGIN_FILE ) . '/includes/import/abstract-wc-product-importer.php';
}

if( class_exists( 'WC_Product_Importer', false ) ):

/**
 * Knawat_Dropshipping_Woocommerce_Importer Class.
 */
class Knawat_Dropshipping_Woocommerce_Importer extends WC_Product_Importer {

	/**
	 * MP API Wrapper object
	 *
	 * @var integer
	 */
	protected $mp_api;

	/**
	 * Import Type
	 *
	 * @var string
	 */
	protected $import_type = 'full';

	/**
	 * Response Data
	 *
	 * @var object
	 */
	protected $data;

	/**
	 * Parameters which contains information regarding import.
	 *
	 * @var array
	 */
	public $params;

	/**
	* __construct function.
	*
	* @access public
	* @return void
	*/
    public function __construct( $import_type = 'full', $params = array() ) {

		$default_args = array(
			'import_id'       	=> 0,  // Import_ID
			'limit'           	=> 25, // Limit for Fetch Products
			'page'            	=> 1,  // Page Number
			'product_index'     => -1, // product index needed incase of memory issuee or timeout
			'force_update' 	 	=> false, // Whether to force update existing items.
			'prevent_timeouts' 	=> true,  // Check memory and time usage and abort if reaching limit.
			'is_complete'		=> false, // Is Import Complete?
			'products_total'	=> -1,
			'imported'			=> 0,
			'failed'			=> 0,
			'updated'			=> 0,
		);

		$this->import_type = $import_type;
		$this->params = wp_parse_args( $params, $default_args );

		$this->mp_api = new Knawat_Dropshipping_Woocommerce_API();

    }

    public function import(){

		$this->start_time = time();
		$data             = array(
			'imported' => array(),
			'failed'   => array(),
			'updated'  => array(),
		);

		switch ( $this->import_type ) {
			case 'full':
				$this->data = $this->mp_api->get( 'catalog/products/?limit='.$this->params['limit'].'&page='.$this->params['page'] );
				break;

			case 'single':
				$sku = sanitize_text_field( $this->params['sku'] );
				if( empty( $sku ) ){
					return array( 'status' => 'fail', 'message' => __( 'Please provide product sku.', 'dropshipping-woocommerce' ) );
				}
				$this->data = $this->mp_api->get( 'catalog/products/'. $sku );
				break;

			default:
				break;
		}

		if( !is_wp_error( $this->data ) ){
			$response = $this->data;
			if( isset( $response->products ) || ( 'single' === $this->import_type && isset( $response->product ) ) ){

				$products = array();
				if ( 'single' === $this->import_type ) {
					if( isset( $response->product->status ) && 'failed' == $response->product->status ){
						$error_message = isset( $response->product->message ) ? $response->product->message : __( 'Something went wrong during get data from Knawat MP API. Please try again later.', 'dropshipping-woocommerce' );
						return array( 'status' => 'fail', 'message' => $error_message );
					}
					$products[] = $response->product;
				}else{
					$products = $response->products;
				}

				// Handle errors
				if( isset( $products->code ) || !is_array( $products ) ){
					return array( 'status' => 'fail', 'message' => __( 'Something went wrong during get data from Knawat MP API. Please try again later.', 'dropshipping-woocommerce' ) );
				}

				// Update Product totals.
				$this->params['products_total'] = count( $products );
				if( empty( $products ) ){
					$this->params['is_complete'] = true;
					return $data;
				}

				foreach( $products as $index => $product ){

					if( $index <= $this->params['product_index'] ){
						continue;
					}

					$formated_data = $this->get_formatted_product( $product );
					$variations = $formated_data['variations'];
					unset( $formated_data['variations'] );

					// Prevent new import for 0 qty products.
					$total_qty = 0;
					if( !empty( $variations )){
						foreach ($variations as $vars) {
							$total_qty += isset($vars['stock_quantity']) ? $vars['stock_quantity'] : 0;
						}
					}

					if( isset( $formated_data['id'] ) && !$this->params['force_update'] ){
						// Fake it
						$result = array( 'id' => $formated_data['id'], 'updated' => true );
					}else{
						if( $total_qty > 0 ){
							$result = $this->process_item( $formated_data );
						} else {
							knawat_dropshipwc_logger("[0_QTY_PRODUCT] SKU:".$formated_data['sku']);
							continue;
						}
					}
					if ( is_wp_error( $result ) ) {
						$result->add_data( array( 'data' => $formated_data ) );
						$data['failed'][] = $result;
					} else{
						if ( $result['updated'] ) {
							$data['updated'][] = $result['id'];
						} else {
							$data['imported'][] = $result['id'];
						}
						$product_id = $result['id'];
						if( !empty( $variations ) ){

							foreach ( $variations as $vindex => $variation ) {
								$variation['parent_id'] = $product_id;
								$variation_result = $this->process_item( $variation );
								if ( is_wp_error( $variation_result ) ) {
									$variation_result->add_data( array( 'data' => $formated_data ) );
									$variation_result->add_data( array( 'variation' => 1 ) );
									$data['failed'][] = $variation_result;
								}
							}
						}
					}
					$this->params['product_index'] = $index;

					if ( $this->params['prevent_timeouts'] && ( $this->time_exceeded() || $this->memory_exceeded() ) ) {
						break;
					}
				}

				if( $this->params['products_total'] === 0 ){
					$this->params['is_complete'] = true;
				}elseif( $this->params['products_total'] < $this->params['limit'] ){
					$this->params['is_complete'] = true;
				}else{
					$this->params['is_complete'] = false;
				}

				return $data;
			}else{
				knawat_dropshipwc_logger( '[GET_PRODUCTS_FROM_API_ERROR]'.print_r( $this->data, true ) );
				return array( 'status' => 'fail', 'message' => __( 'Something went wrong during get data from Knawat MP API. Please try again later.', 'dropshipping-woocommerce' ) );
			}
		}else{
			knawat_dropshipwc_logger( '[GET_PRODUCTS_FROM_API_ERROR]'.$this->data->get_error_message() );
			return array( 'status' => 'fail', 'message' => $this->data->get_error_message() );
		}
	}

    public function get_formatted_product( $product ){

		if( empty( $product) ){
			return $product;
		}
		$active_langs = array();
		$default_lang = get_locale();
		$default_lang = explode( '_', $default_lang );
		$default_lang = $default_lang[0];

		$active_plugins = knawat_dropshipwc_get_activated_plugins();

		if( $active_plugins['qtranslate-x'] ){
			global $q_config;
			$default_lang = isset( $q_config['default_language'] ) ? sanitize_text_field( $q_config['default_language']  ) : $default_lang;
			$active_langs = isset( $q_config['enabled_languages'] ) ? $q_config['enabled_languages'] : array();
		}

		$new_product = array();
		$product_id = wc_get_product_id_by_sku( $product->sku );
		if ( $product_id ) {
			$new_product['id'] = $product_id;
		}else{
			$new_product['sku'] = $product->sku;
		}

		if( !$product_id || $this->params['force_update'] ){

			if( isset( $product->variations ) && !empty( $product->variations ) ){
				$new_product['type'] = 'variable';
			}
			$new_product['name'] = isset( $product->name->$default_lang ) ? sanitize_text_field( $product->name->$default_lang ) : '';
			$new_product['description'] = isset( $product->description->$default_lang ) ? sanitize_textarea_field( $product->description->$default_lang ) : '';

			if( $active_plugins['qtranslate-x'] && !empty( $active_langs ) ){

				$new_product['name'] = '';
				$new_product['description'] = '';
				$categories = array();

				foreach ( $active_langs as $active_lang ) {
					if( isset( $product->name->$active_lang ) ){
						$new_product['name'] .= '[:'.$active_lang.']'.$product->name->$active_lang;
					}
					if( isset( $product->description->$active_lang ) ){
						$new_product['description'] .= '[:'.$active_lang.']'.$product->description->$active_lang;
					}
				}
				if( $new_product['name'] != ''){
					$new_product['name'] .= '[:]';
				}
				if( $new_product['description'] != ''){
					$new_product['description'] .= '[:]';
				}
			}

			//$new_product['short_description'] = $new_product['description'];
			$new_product['short_description'] = '';

			// Added Meta Data.
			$new_product['meta_data'] = array();
			$new_product['meta_data'][] = array( 'key' => 'dropshipping', 'value' => 'knawat' );

			// Formatting Image Data
			if( $active_plugins['featured-image-by-url'] && isset( $product->images ) && !empty( $product->images ) ){
				$images = $product->images;
				$new_product['meta_data'][] = array( 'key' => '_knawatfibu_url', 'value' => array_shift( $images ) );
				if ( ! empty( $images ) ) {
					$new_product['meta_data'][] = array( 'key' => '_knawatfibu_wcgallary', 'value' => implode(',', $images) );
				}
			} elseif ( isset( $product->images ) && !empty( $product->images ) ) {
				$images = $product->images;
				$new_product['raw_image_id'] = array_shift( $images );
				if ( ! empty( $images ) ) {
					$new_product['raw_gallery_image_ids'] = $images;
				}
			}
		}

		$variations = array();
		$attributes = array();
		if( isset( $product->variations ) && !empty( $product->variations ) ){
			foreach ( $product->variations as $variation ) {
				$temp_variant = array();
				$varient_id = wc_get_product_id_by_sku( $variation->sku );
				if ( $varient_id ) {
					$temp_variant['id'] = $varient_id;
				}else{
					$temp_variant['sku']  = $variation->sku;
					$temp_variant['name'] = $new_product['name'];
					$temp_variant['type'] = 'variation';
				}

				// Add Meta Data.
				$temp_variant['meta_data'] = array();

				if( $product_id && !$this->params['force_update'] ){

					if( is_numeric( $variation->sale_price ) ){
						$temp_variant['price'] = wc_format_decimal( $variation->sale_price );
					}
					if( is_numeric( $variation->market_price ) ){
						$temp_variant['regular_price'] = wc_format_decimal( $variation->market_price );
					}
					if( is_numeric( $variation->sale_price ) ){
						$temp_variant['sale_price'] = wc_format_decimal( $variation->sale_price );
					}
					$temp_variant['stock_quantity'] = $this->parse_stock_quantity_field( $variation->quantity );
				}else{

					$temp_variant['price'] = wc_format_decimal( $variation->sale_price );
					$temp_variant['regular_price'] = wc_format_decimal( $variation->market_price );
					$temp_variant['sale_price'] = wc_format_decimal( $variation->sale_price );
					if( isset( $variation->quantity ) ){
						$temp_variant['manage_stock'] = true;
					}
					$temp_variant['stock_quantity'] = $this->parse_stock_quantity_field( $variation->quantity );
					$temp_variant['weight'] = wc_format_decimal( $variation->weight );
					$temp_variant['meta_data'][] = array( 'key' => '_knawat_cost', 'value' => wc_format_decimal( $variation->cost_price ) );

					if( isset( $variation->attributes ) && !empty( $variation->attributes ) ){
						foreach ( $variation->attributes as $attribute ) {
							/*///////////////////////////////////////////*/
							/////// @TODO: Add MULTILINGUAL SUPPORT ///////
							/*///////////////////////////////////////////*/
							$temp_attribute_name = isset( $attribute->name->en ) ? $attribute->name->en : '';
							$temp_attribute_value = isset( $attribute->option->en ) ? $attribute->option->en : '';

							// if attribute name is blank then take a chance for TR.
							if( $temp_attribute_name == '' ){
								$temp_attribute_name = isset( $attribute->name->tr ) ? $attribute->name->tr : '';
							}
							if( $temp_attribute_value == '' ){
								$temp_attribute_value = isset( $attribute->option->tr ) ? $attribute->option->tr : '';
							}

							// continue if no attribute name found.
							if( $temp_attribute_name == '' ){
								continue;
							}

							$temp_var_attribute = array();
							$temp_var_attribute['name'] = $temp_attribute_name;
							$temp_var_attribute['value'] = array( $temp_attribute_value );
							$temp_var_attribute['taxonomy'] = true;
							$temp_variant['raw_attributes'][] = $temp_var_attribute;

							if( isset( $attributes[ $temp_attribute_name ] ) ){
								if( !in_array( $temp_attribute_value, $attributes[ $temp_attribute_name ] ) ){
									$attributes[ $temp_attribute_name ][] = $temp_attribute_value;
								}
							}else{
								$attributes[ $temp_attribute_name ][] = $temp_attribute_value;
							}
						}
					}
				}
				$variations[] = $temp_variant;
			}
		}

		if( !empty( $attributes ) ){
			foreach ( $attributes as $name => $value ) {
				$temp_raw = array();
				$temp_raw['name'] = $name;
				$temp_raw['value'] = $value;
				$temp_raw['taxonomy'] = true;
				$temp_raw['visible'] = true;
				$temp_raw['default'] = isset( $value[0] ) ? $value[0] : '';
				$new_product['raw_attributes'][] = $temp_raw;
			}
		}
		$new_product['variations'] = $variations;
		return $new_product;
	}

	/**
	 * Parse a float value field.
	 *
	 * @param string $value Field value.
	 * @return float|string
	 */
	public function parse_float_field( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		// Remove the ' prepended to fields that start with - if needed.
		$value = $this->unescape_negative_number( $value );

		return floatval( $value );
	}

	/**
	 * Parse the stock qty field.
	 *
	 * @param string $value Field value.
	 * @return float|string
	 */
	public function parse_stock_quantity_field( $value ) {
		if ( '' === $value ) {
			return $value;
		}

		// Remove the ' prepended to fields that start with - if needed.
		$value = $this->unescape_negative_number( $value );

		return wc_stock_amount( $value );
	}

	/**
	 * Get Import Parameters
	 *
	 * @param string $value Field value.
	 * @return float|string
	 */
	public function get_import_params(){
		return $this->params;
	}

}

endif;
