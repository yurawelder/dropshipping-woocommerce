/* Shipping Address Edit button */
jQuery( document).ready( function(){
	jQuery( ".knawat-shipment-wrap a.edit_shipment_traking" ).on("click", function(){
		jQuery( ".knawat-shipment-wrap .knawat-shipment-info" ).hide();
		jQuery( ".knawat-shipment-wrap .knawat-shipment-edit" ).show();
	});
});

jQuery( document).ready( function(){
	jQuery(document).on("change", ".knawat_dropshipper_select", function(e){
		knawat_hide_show_dropshipper_qty( this );
	});

	jQuery('#woocommerce-product-data').on('woocommerce_variations_loaded', function(event) {
		jQuery('.knawat_dropshipper_select' ).each(function() {
			knawat_hide_show_dropshipper_qty( this );
		});
	});
});

function knawat_hide_show_dropshipper_qty( element ){
	if( jQuery( element ).val() == 'default' ){
		jQuery( element ).closest( '.woocommerce_variation' ).find( 'p.knawat_dropshipwc_localds_stock' ).hide();
	}else{
		jQuery( element ).closest( '.woocommerce_variation' ).find( 'p.knawat_dropshipwc_localds_stock' ).show();
	}
}
