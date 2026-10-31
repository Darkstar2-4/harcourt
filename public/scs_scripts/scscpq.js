var scscpq_api_url="/scscpq_api.php";
var scscpq_version=20240815;
var scscpq_id;
var scscpq_resize=false;
var scscpq_action;
var scscpq_data=new Object();

function scscpq_initial() {
	scscpq_id=( (arguments.length) ? arguments[0] : "scscpq_configurator_id");
    scscpq_resize=( (arguments.length > 1) ? arguments[1] : false);
    if (jQuery("#" + scscpq_id).length == 0) return;
	scscpq_message('PCOM_INFO', "sku");
}
function scscpq_message(message, action) {
	try {
	    jQuery("#" + scscpq_id)[0].contentWindow.postMessage(message, '*');
	    scscpq_action=action;
	}
	catch(error) {
        action=scscpq_action;
	}
	scscpq_action=action;
}
jQuery(window).on('message', function(e) {
	var response=e.originalEvent.data;
    console.log(response);
    switch (typeof(response)) {
      case "object":
        switch (true) {
          case ( (typeof(response.width) != "undefined") && (typeof(response.height) != "undefined") ):
			if (scscpq_action == "size") {
	            if (scscpq_resize) {
	                if (response.height) document.getElementById(scscpq_id).style.height = (response.height + 30) + "px";
	                scscpq_resize=false;
	            }
			}
			scscpq_message('PCOM_INFO', "sku");
            break;
          case (typeof(response.standardName) != "undefined"):
          	switch (scscpq_action) {
              case "sku":
	            if (JSON.stringify(response) == scscpq_data) break;
	            scscpq_data=JSON.stringify(response);
                spinner_message("Loading ...", "products_cad_html");
	        	jQuery('#products_cad_html').html("Loading ...");
	        	scscpq_api("sku",response);
	        	scscpq_action="";
	            break;
              case "cad":
	            scscpq_api("cad", response);
	            scscpq_action="";
	            break;
            }
          	break;
        }
      	break;
      case "string":
		switch (response) {
          case "PCOM_PART_CHANGED":
	    	jQuery('#products_cad_html').html("Loading ...");
			scscpq_message('PCOM_INFO', "sku");
            break;
		  case "PCOM_PART_GENERATED":
			scscpq_message('PCOM_INFO', "cad");
      		break;
		}
    }
});
function spinner_message(text, id) {
    jQuery("#" + id).html("<span class=scscpq_spinner></span><span class=scscpq_spinner_text>" + text + "</span>");
}
function scscpq_api(action, data) {
	jQuery.ajax({
    	url: scscpq_api_url,
		data: { action: action, data: data },
        type: 'post',
	    dataType: 'json',
	    success: function(data) {
			jQuery('#products_cad_html').html(data['html']);
        },
        error: function(xhr,status,error) {
			jQuery('#products_cad_html').html("Error detected");
        }
	});
}