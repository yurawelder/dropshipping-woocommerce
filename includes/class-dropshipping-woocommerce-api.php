<?php
/**
 * Knawat MP API Wrapper class.
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

class Knawat_Dropshipping_Woocommerce_API {

	/**
     * Contains cURL instance
     * @access protected
     */
    protected $token;

	/**
     * Contains the API url
     * @access protected
     */
    protected $api_url = 'https://dev.mp.knawat.io/api';

    /**
	* __construct function.
	*
	* @access public
	* @return void
	*/
    public function __construct() {
		// Set API Token
		$this->token = $this->get_access_token();

    }

    /**
    * Get Access Token
    *
    *
    * @access public
    * @return string
    */
    public function get_access_token( $token_only = true ) {

		// Check for transient, if none, grab channel
		if ( false === ( $channel = get_transient( 'knawat_mp_access_channel' ) ) ) {

			// Consumer Keys.
			$consumer_keys = knawat_dropshipwc_get_consumerkeys();
			if( empty( $consumer_keys ) ){
				return;
			}

			// post body
			$keydata = array();
			$keydata['consumerKey'] 	= $consumer_keys['consumer_key'];
			$keydata['consumerSecret']	= $consumer_keys['consumer_secret'];

			// Get Store channel
			$response = wp_remote_post( $this->api_url.'/token', array(
				'body' => $keydata
			) );

			if ( is_wp_error( $response ) ) {
				return;
			} else {
				$channel = wp_remote_retrieve_body( $response );
				if ( is_wp_error( $channel ) ) {
					return;
				}
				$channel = json_decode( $channel );
				if( !isset( $channel->channel ) ){
					return;
				}
				$channel = $channel->channel;
				set_transient( 'knawat_mp_access_channel', $channel, 24 * HOUR_IN_SECONDS );
			}
		}

		if( $token_only ){
			return $channel->token;
		}
		return $channel;
    }

    /**
    * get function.
    *
    * Performs an API GET request
    *
    * @access public
    * @return object
    */
    public function get( $path, $return_array = FALSE ) {

		if( empty( $this->token ) ){
			return new WP_Error( 'token_not_found', __( 'Access token not found for API. Please make sure your store is connected to knawat.com', 'dropshipping-woocommerce' ) );
		}
		$url = $this->api_url . '/' . $path;

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
			)
		) );

		if ( ! is_wp_error( $response ) ) {
			// In Case return full response
			if( $return_array ){
				return $response;
			}

			$response = wp_remote_retrieve_body( $response );
			if ( !is_wp_error( $response ) ) {
				// Return json Decoded response.
				return json_decode( $response );
			} else {
				return $response;
			}
		}else{
			return $response;
		}
		return;
    }


    /**
    * post function.
    *
    * Performs an API POST request
    *
    * @access public
    * @return object
    */
    public function post( $path, $data = array(), $return_array = FALSE ) {
		if( empty( $this->token ) ){
			return new WP_Error( 'token_not_found', __( 'Access token not found for API. Please make sure your store is connected to knawat.com', 'dropshipping-woocommerce' ) );
		}
		$url = $this->api_url . '/' . $path;

		$response = wp_remote_post( $url, array(
			'body'	  => $data,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
			)
		) );

		if ( ! is_wp_error( $response ) ) {
			// In Case return full response
			if( $return_array ){
				return $response;
			}

			$response = wp_remote_retrieve_body( $response );
			if ( !is_wp_error( $response ) ) {
				// Return json Decoded response.
				return json_decode( $response );
			} else {
				return $response;
			}
		}else{
			return $response;
		}
		return;
	}
}
