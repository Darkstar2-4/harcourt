var scs_js_version=20240812;
function fn_page_item(action,page,item) {
    jQuery("#action").val(action);
    jQuery("#page").val(page);
    jQuery("#item").val(item);
    jQuery("#scs_form").submit();
}
function div_show(div, show) {
    var display="block";
	if (arguments.length==3) display=arguments[2];
    switch (true) {
      case (!document.getElementById(div)):
      	return;
	  case (!show):
    	document.getElementById(div).style.display="none";
		break;
      default:
    	document.getElementById(div).style.display=display;
	}
	scs_content_resize();
}
function scscpq_class_data(classname) {
	data=new Object();
    jQuery("." + classname).each(
	    function() {
	        switch (true) {
	          case (!this.id):
              case ( (this.id).substring(0,9) == "jquerytab"):
	            break;
	          case (jQuery("#" + this.id).attr('type') == "checkbox"):
	            data[this.id]=jQuery("#" + this.id).prop("checked")
	            break;
              case (jQuery("#" + this.id).attr('type') == "radio"):
                if (jQuery("#" + this.id).prop("checked")) data[this.name]=this.value;
                break;
	          default:
	            data[this.id]=jQuery("#" + this.id).val();
	        }
		}
    );
    return data;
}
function scscpq_fill(data, prefix="") {
    for (var field in data) {
		value=data[field];
        field="#" + prefix + field;
        switch ( jQuery(field).prop('type') ) {
          case "undefined":
            break;
          case "text":
          case "textarea":
          case "select-one":
          case "hidden":
            jQuery(field).val(value);
            break;
          case "button":
            jQuery(field).prop("value", value);
          	break;
          case "radio":
          case "checkbox":
            jQuery(field).prop("checked", (value) ? true : false);
            break;
          default:
            jQuery(field).html(value);
        }
	}
}

jQuery(document).ready(function() {
	jQuery(".tablesorter").tablesorter();
});

jQuery(function() {
    jQuery(document).tooltip();
    jQuery( ".datepicker" ).datepicker({ changeMonth: true, changeYear: true });
	jQuery( ".sortable" ).sortable();
	jQuery( ".sortable" ).disableSelection();
    jQuery( ".accordion" ).accordion({ collapsible: true, active: false, heightStyle: 'content'  } );
});