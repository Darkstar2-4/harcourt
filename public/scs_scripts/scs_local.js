var scs_local_version=20250506;
var cart_url=window.location.origin + "/cart.php";
jQuery(document).ready(function() {
	if (window.self !== window.top) scscpq_ipc("scscpq_wp_iframe", "resize", 0);
});
function fn_page_item(action,page,item) {
	if(document.scs_form.action) document.scs_form.action.value=action;
	if(document.scs_form.page) document.scs_form.page.value=page;
	if(document.scs_form.item) document.scs_form.item.value=item;
	document.scs_form.submit();
}
function div_show(div,show) {
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
function scs_content_resize() {
	div=document.getElementById('scs_content_div');
	if (!div) return;
	iframe=parent.document.getElementById('scs_content_iframe');
	if (iframe) iframe.height=div.offsetHeight + 30;
}
function image_rollover(obj,image) {
	obj.src=image;
}
function scscpq_ipc(id, action, data) {
	window.parent.postMessage({'scscpq': action, 'token': sessionStorage['scscpq_token'], 'id': id, 'data': data},'*');
}

function cart_update(obj,code,code2) {
	quantity=jQuery("#" + code).val();
	jQuery.ajax({
	    url: cart_url,
	    data: {action: 'json', json_code: code, json_quantity: quantity },
	    dataType: 'json',
	    success:function(data){
			jQuery("#" + code).val('');
			if ( jQuery("#menu_cart_size" ).length ) {
            	if (quantity) cart_flying(code);
        		jQuery('#menu_cart_size').html(data.cart_size);
		      } else {
            	if (quantity) scscpq_ipc("menu_cart_size","cart_add",code);
				scscpq_ipc("menu_cart_size","cart_size",data.cart_size);
			}
	   }
	});
}
function cart_flying(position) {
    var cart = jQuery('#menu_cart_img');
    var field=jQuery("#" + position);
    var thumbnail=jQuery('#cart_thumbnail');
    var imgclone = thumbnail.clone()
        .offset({
	        top: field.offset().top,
	        left: field.offset().left
    	})
        .css({
            'opacity': '0.5',
            'position': 'absolute',
            'height': '80px',
            'width': '80px',
            'z-index': '100'
        })
        .appendTo(jQuery('body'))
        .animate({
            'top': cart.offset().top + 10,
            'left': cart.offset().left + 10,
            'width': 80,
            'height': 80
        }, 1000, 'easeInOutExpo');
        imgclone.animate({
            'width': 0,
            'height': 0
        }, function () {
            jQuery(this).detach()
    });
}
function cart_delete(sku,code) {
	if (!confirm('Delete ' + sku)) return;
	jQuery.ajax({
	    url: cart_url,
	    data: {action: 'json', json_code: code, json_quantity: '0' },
	    dataType: 'json',
	    success:function(data){
			scscpq_ipc("","cart_size",data.cart_size);
        	jQuery("#menu_cart_size").html(data.cart_size);
        	jQuery("#cart_extended").html(data.cart_extended);
			jQuery("#" + code).remove();
	   }
	});
}

// Ajax functions
jQuery(function() {
    jQuery('#ajax_user_id').autocomplete({
        source: '/scs_ajax.php?table=user',
        minLength: 2,
        select: function(event, ui) {
			scscpq_fill(ui.item, "ajax_user_")
        },
        html: true,
        open: function(event, ui) {
            jQuery('.ui-autocomplete').css('z-index', 1000);
        }
    });
    jQuery('#ajax_inventory_sku').autocomplete({
        source: '/scs_ajax.php?table=inventory',
        minLength: 2,
        select: function(event, ui) {
            scscpq_fill(ui.item, "ajax_inventory_")
        },
        html: true,
        open: function(event, ui) {
            jQuery('.ui-autocomplete').css('z-index', 1000);
        }
    });

    jQuery('.remote').autocomplete({
        source: '/scs_ajax.php?table=remote',
        minLength: 2,
        html: true,
        dataType: "json",
        open: function(event, ui) { jQuery('.ui-autocomplete').css('z-index', 1000) },
        select: function(event, ui) { scscpq_fill(ui.item, "remote_") }
    });

});

function ajax_user_fill(id) {
	jQuery.ajax({
	    url: "/scs_ajax.php",
	    data: {table: 'user', action: 'fill', key_0: id },
	    dataType: 'json',
	    success:function(data){
			scscpq_fill(data, "ajax_user_")
	   }
	});
}
function ajax_inventory_fill(id) {
	jQuery.ajax({
	    url: "/scs_ajax.php",
	    data: {table: 'inventory', action: 'fill', key_0: id },
	    dataType: 'json',
	    success:function(data){
			scscpq_fill(data, "ajax_inventory_")
	   }
	});
}
