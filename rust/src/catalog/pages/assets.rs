//! Script and style blocks catalog.php prints verbatim (js(), css(), output_unit_filter_script()).

pub const JS: &str = r###"<script>
jQuery(document).ready(function() {
    jQuery('.products_cart').on('mouseover', function(){
        jQuery(this).attr('src', '/scs_images/addtocart-hover.png');
    });
    jQuery('.products_cart').on('mouseout', function(){
        jQuery(this).attr('src', '/scs_images/addtocart.png');
    });
});
function quick_cad(id, sku, configurator) {
    if (id.length) jQuery("#" + id).prop("checked", false);
    cad_name=jQuery("#cad_name").val();
    if (!cad_name.length) {
        jQuery("#profile_tab").val(jQuery("#products_jquery_div").tabs('option', 'active'));
        jQuery('#products_jquery_div').tabs({ active: jQuery("#tab_catalog_profile_no").val() });
        return;
    }
    spinner_message("Generating CAD ...", "quick_cad_status");

    data=new Object();
    data['action']="quick_cad";
    data['sku']=sku;
    if (configurator == 1) data['configurator']=scscpq_data;
	jQuery.ajax({
        url: "/scscpq_api.php",
        type: "post",
	    dataType: "json",
		data,
	    success: function(response) {
            scscpq_fill(response)
            if ("url" in response) window.open(response['url'], "_blank")
        },
        error: function (request, status, error) {
            jQuery("#quick_cad_status").html("Internal Error Detected")
            console.log(request)
        }
    });
}
</script>"###;

pub const CSS: &str = r###"<style>
.scscpq_spinner {
  display: inline-block;
  border: 4px solid #f3f3f3;
  border-radius: 50%;
  border-top: 4px solid #e53247;
  width: 20px;
  height: 20px;
  -webkit-animation: spin 2s linear infinite; /* Safari */
  animation: spin 2s linear infinite;
}
.scscpq_spinner_text {
  display: inline-block;
  vertical-align: top;
  margin-left: 10px;
  margin-top: 5px;
}
#quick_cad_status {
   margin-bottom: 10px;
}
.quick_cad_button {
    color: #fff;
    background: #6b1f1f;
    width: 160px !important;
    height: 80px !important;
    line-height: 33px !important;
    white-space: normal !important;
    text-align: center !important;
    cursor: pointer;
    border-radius: 4px;
    padding: 0 1em;
    font-family: 'Rajdhani' !important;
	font-weight: 600 !important;
	font-size: 26px !important;
}
.cad_download_button {
    color: #fff;
    background: #6b1f1f;
    cursor: pointer;
    border-radius: 4px;
    padding: 0 1em;
    margin-bottom: 10px;
    font-family: 'Rajdhani' !important;
	font-weight: 600 !important;
	font-size: 26px !important;
    text-decoration: none !important;
}

/* Safari */
@-webkit-keyframes spin {
  0% { -webkit-transform: rotate(0deg); }
  100% { -webkit-transform: rotate(360deg); }
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
.catalog_category_wrapper {
    position: relative;
    display: inline-block;
    width: 100%;
}
.catalog_category_overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    cursor: not-allowed;
}
.catalog_category_overlay_message {
    text-align: center;
    padding: 20px 30px;
    font-weight: 600;
    font-size: 1.1rem;
    color: #fff;
    background: rgba(0, 0, 0, 0.85);
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.catalog_category_overlay_message a {
    color: #e51c38;
    cursor: pointer;
}
.catalog_blocked_link {
    cursor: not-allowed;
}
</style>"###;
