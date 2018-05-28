<?php
/**
 * Common Knawat Dropshipping WooCommerce REST API Controller
 *
 * @author   Knawat.com
 * @category API
 * @package  Knawat_Dropshipping_Woocommerce/API
 * @since    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if( class_exists( 'WC_REST_Controller' ) ):
/**
 * @package Knawat_Dropshipping_Woocommerce/API
 * @extends WC_REST_Controller
 */
class Knawat_Dropshipping_Woocommerce_REST_Controller extends WC_REST_Controller {

    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = KNAWAT_DROPWC_API_NAMESPACE;

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'consumerkeys';

    /**
     * Register the route for /system_status
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_knawat_mpapi_consumerkeys' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
                'args'                => $this->get_collection_params(),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'update_knawat_mpapi_consumerkeys' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
                'args'                => array_merge( $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ), array(
                    'consumer_key' => array(
                        'required'    => true,
                        'type'        => 'string',
                        'description' => __( 'Knawat MP API Consumer Key', 'dropshipping-woocommerce' ),
                    ),
                    'consumer_secret' => array(
                        'required'    => true,
                        'type'        => 'string',
                        'description' => __( 'Knawat MP API Consumer Secret', 'dropshipping-woocommerce' ),
                    ),
                ) ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_knawat_mpapi_consumerkeys' ),
                'permission_callback' => array( $this, 'delete_item_permissions_check' ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        ) );
    }

    /**
     * Check if a given request has access to get/create/update Knawat MP API keys.
     *
     * @param  WP_REST_Request $request Full details about the request.
     * @return WP_Error|boolean
     */
    public function create_item_permissions_check( $request ) {
        if ( ! wc_rest_check_manager_permissions( 'settings', 'edit' ) ) {
            return new WP_Error( 'knawat_dropshipwc_rest_cannot_create', __( 'Sorry, you are not allowed to create/update resources.', 'dropshipping-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
        }

        return true;
    }

    /**
     * Check if a given request has access delete Knawat MP API keys.
     *
     * @param  WP_REST_Request $request Full details about the request.
     *
     * @return bool|WP_Error
     */
    public function delete_item_permissions_check( $request ) {
        if ( ! wc_rest_check_manager_permissions( 'settings', 'delete' ) ) {
            return new WP_Error( 'knawat_dropshipwc_rest_cannot_delete', __( 'Sorry, you are not allowed to delete this resource.', 'dropshipping-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
        }

        return true;
    }

    /**
     * Get Knawat MP Consumer Keys
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_knawat_mpapi_consumerkeys( $request ) {
        $response = knawat_dropshipwc_get_consumerkeys();

        /**
         * Filter Knawat MP Consumer Keys, before respond
         *
         * @param array           $response    Array of Knawat MP Consumer Keys
         * @param WP_REST_Request $request The current request.
         */
        $response = apply_filters( 'knawat_dropshipwc_api_consumer_keys', $response, $request );
        return rest_ensure_response( $response );
    }

    /**
     * Update Knawat MP Consumer Keys
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_knawat_mpapi_consumerkeys( $request ) {

        // Validate consumer_key.
        if ( empty( $request['consumer_key'] ) ) {
            return new WP_Error( "knawat_dropshipwc_rest_invalid_consumer_key", __( 'Consumer key is required and must be valid.', 'dropshipping-woocommerce' ), array( 'status' => 400 ) );
        }

        // Validate consumer_secret.
        if ( empty( $request['consumer_secret'] ) ) {
            return new WP_Error( "knawat_dropshipwc_rest_invalid_consumer_secret", __( 'Consumer secret is required and must be valid.', 'dropshipping-woocommerce' ), array( 'status' => 400 ) );
        }

        $knawat_options = knawat_dropshipwc_get_options();
        $knawat_options['mp_consumer_key'] = sanitize_text_field( $request['consumer_key'] );
        $knawat_options['mp_consumer_secret'] = sanitize_text_field( $request['consumer_secret'] );
        knawat_dropshipwc_update_options( $knawat_options );

        /**
         * Fires after Knawat MP Consumer Keys are updated via the REST API.
         *
         * @param array           $knawat_options   Knawat Dropshipping Options
         * @param WP_REST_Request $request  Request object.
         */
        do_action( 'knawat_dropshipwc_api_consumer_keys_updated', $knawat_options, $request );

        $response = knawat_dropshipwc_get_consumerkeys();
        return rest_ensure_response( $response );
    }

    /**
     * Update Knawat MP Consumer Keys
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function delete_knawat_mpapi_consumerkeys( $request ) {

        $knawat_options = knawat_dropshipwc_get_options();
        unset( $knawat_options['mp_consumer_key'] );
        unset( $knawat_options['mp_consumer_secret'] );
        knawat_dropshipwc_update_options( $knawat_options );

        /**
         * Fires after Knawat MP Consumer Keys are deleted via the REST API.
         *
         * @param array           $knawat_options   Knawat Dropshipping Options
         * @param WP_REST_Request $request  Request object.
         */
        do_action( 'knawat_dropshipwc_api_consumer_keys_deleted', $knawat_options, $request );

        $consumer_keys = knawat_dropshipwc_get_consumerkeys();
        if( empty( $consumer_keys ) ){
            $response = array( 
                'status'=> 'success',
                'data'  => array( 'message' => __( 'Knawat Consumer Keys are deleted successfully.', 'dropshipping-woocommerce' ) )
            );
        }else{
            $response = array( 
                'status'=> 'fail',
                'data'  => array( 'message' => __( 'Something went wrong during delete Consumer Keys. Please try again.', 'dropshipping-woocommerce' ) )
            );
        }
        return rest_ensure_response( $response );
    }

}

add_action( 'rest_api_init', function () {
    $knawat_handshake = new Knawat_Dropshipping_Woocommerce_REST_Controller();
    $knawat_handshake->register_routes();
} );

endif;