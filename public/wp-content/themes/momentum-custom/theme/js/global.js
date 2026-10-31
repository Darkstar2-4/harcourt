/*!
* jPushMenu.js
* 1.1.1
* @author: takien
* http://takien.com
* Original version (pure JS) is created by Mary Lou http://tympanus.net/
*/

function isTouchDevice(){
	return typeof window.ontouchstart !== 'undefined';
}
jQuery(document).ready(function(){
	/* If mobile browser, prevent click on parent nav item from redirecting to URL */
	if(isTouchDevice()) {
		// 1st click, add "clicked" class, preventing the location change. 2nd click will go through.
		jQuery("ul.navbar-nav li.menu-item-has-children > a").click(function(event) {
			event.preventDefault();
			// Perform a reset - Remove the "clicked" class on all other menu items
			jQuery("ul.navbar-nav li.menu-item-has-children > a").not(this).removeClass("clicked");
			jQuery(this).toggleClass("clicked");
			if (jQuery(this).hasClass("clicked")) {
				event.preventDefault();
			}
		});
	}
});
(function($) {
	$.fn.jPushMenu = function(customOptions) {
		var o = $.extend({}, $.fn.jPushMenu.defaultOptions, customOptions);

		$('body').addClass(o.pushBodyClass);

		// Add class to toggler
		$(this).addClass('jPushMenuBtn');

		$(this).click(function(e) {
			e.stopPropagation();

			var target     = '',
			push_direction = '';

			// Determine menu and push direction
			if ($(this).is('.' + o.showLeftClass)) {
				target         = '.cbp-spmenu-left';
				push_direction = 'toright';
			}
			else if ($(this).is('.' + o.showRightClass)) {
				target         = '.cbp-spmenu-right';
				push_direction = 'toleft';
			}
			else if ($(this).is('.' + o.showTopClass)) {
				target = '.cbp-spmenu-top';
			}
			else if ($(this).is('.' + o.showBottomClass)) {
				target = '.cbp-spmenu-bottom';
			}

			if (target == '') {
				return;
			}

			$(this).toggleClass(o.activeClass);
			$(target).toggleClass(o.menuOpenClass);

			if ($(this).is('.' + o.pushBodyClass) && push_direction != '') {
				$('body').toggleClass(o.pushBodyClass + '-' + push_direction);
			}

			// Disable all other buttons
			$('.jPushMenuBtn').not($(this)).toggleClass('disabled');

			return;
		});

		var jPushMenu = {
			close: function (o) {
				$('.jPushMenuBtn,body,.cbp-spmenu')
				.removeClass('disabled ' + o.activeClass + ' ' + o.menuOpenClass + ' ' + o.pushBodyClass + '-toleft ' + o.pushBodyClass + '-toright');
			}
		}

		// Close menu on clicking outside menu
		if (o.closeOnClickOutside) {
			$(document).click(function() {
				jPushMenu.close(o);
				$('#nav-icon').removeClass('open');
			});
		}

		// Close menu on clicking menu link
		if (o.closeOnClickLink) {
			$('.nav-btn a').on('click',function() {
				$('#nav-icon').removeClass('open');
				jPushMenu.close(o);
			});
		}
	};

	/*
	* In case you want to customize class name,
	* do not directly edit here, use function parameter when call jPushMenu.
	*/
	$.fn.jPushMenu.defaultOptions = {
		pushBodyClass      : 'push-body',
		showLeftClass      : 'menu-left',
		showRightClass     : 'menu-right',
		showTopClass       : 'menu-top',
		showBottomClass    : 'menu-bottom',
		activeClass        : 'menu-active',
		menuOpenClass      : 'menu-open',
		closeOnClickOutside: false,
		closeOnClickLink   : false
	};

	var loadedWidth = $(window).width();

	if ( loadedWidth > 1000 ) {
		$('.navbar-nav li.dropdown').addClass('desktop');
		$('.navbar-nav li.desktop.menu-item-has-children').hover(function() {
			$(this).children().next('ul').addClass('show');
			$(this).addClass('show');
		}, function() {
			$(this).removeClass('show');
			$(this).children().next('ul').removeClass('show');
		});
	} else {
		$('.navbar-nav li.dropdown').addClass('mobile');
		$('#menu-main-menu > li.menu-item-has-children > span').click(function() {
			$('#menu-main-menu > li.menu-item-has-children').children().next('ul').hide();
			if ($(this).hasClass('show')) {
				$(this).removeClass('show');
				$(this).siblings('ul').hide();
			} else {
				$('#menu-main-menu > li.menu-item-has-children span').removeClass('show');
				$(this).addClass('show');
				$(this).siblings('ul').show();
			}
		});
		$('.depth_0 > li.menu-item-has-children > span').click(function() {
			if ($(this).hasClass('show')) {
				$(this).removeClass('show');
				$(this).siblings('ul').hide();
			} else {
				$('.depth_0 > li.menu-item-has-children span').removeClass('show');
				$('.depth_0 > li.menu-item-has-children').children().next('ul').hide();
				$(this).addClass('show');
				$(this).siblings('ul').show();
			}
		});
	}

	$(window).resize(function() {
		var currentWidth = $(window).width();
		$('#menu-main-menu > li.menu-item-has-children').children().next('ul').hide();
		$('#menu-main-menu > li.menu-item-has-children span').removeClass('show');
		if (currentWidth < 1000 ) {
			$('.navbar-nav li.dropdown').addClass('mobile');
			$('.navbar-nav li.dropdown').removeClass('desktop');
			$('.navbar-nav li').unbind("mouseenter mouseleave");
			$('#menu-main-menu > li.menu-item-has-children > span').click(function() {
				$('#menu-main-menu > li.menu-item-has-children').children().next('ul').hide();
				if ($(this).hasClass('show')) {
					$(this).removeClass('show');
					$(this).siblings('ul').hide();
					console.log('hide');
				} else {
					$(this).addClass('show');
					$(this).siblings('ul').show();
					console.log('show');
				}
			});
			$('.depth_0 > li.menu-item-has-children > span').click(function() {
				$('.depth_0 > li.menu-item-has-children').children().next('ul').hide();
				if ($(this).hasClass('show')) {
					$(this).removeClass('show');
					$(this).siblings('ul').hide();
					console.log('hide');
				} else {
					$(this).addClass('show');
					$(this).siblings('ul').show();
					console.log('show');
				}
			});
		} else {
			$('.navbar-nav li.dropdown').addClass('desktop')
			$('.navbar-nav li.desktop.menu-item-has-children').hover(function() {
				$(this).children().next('ul').addClass('show');
				$(this).children().next('ul').show();
				$(this).addClass('show');
			}, function() {
				$(this).removeClass('show');
				$(this).children().next('ul').removeClass('show');
				$(this).children().next('ul').hide();
			});
		}
	});

})(jQuery);

/**!
* Global JS
*/

(function ($) {

	'use strict';

	$(document).ready(function() {

		// Comments

		$('.commentlist li').addClass('card');
		$('.comment-reply-link').addClass('btn btn-secondary');

		// Forms

		$('select, input[type=text], input[type=email], input[type=password], textarea').addClass('form-control');
		$('input[type=submit]').addClass('standard-btn red');

		// Pagination fix for ellipsis

		if ( $('body').hasClass('page-template-template-product-single') ) {
			$( ".product-block-one .image-half img" ).ready( function() {
				var imgHeight = $('.product-block-one .image-half img').height();
				if (rowHeight === 0) {
					var rowHeight = imgHeight - 200 + "px";
				} else {
					var rowHeight = '800px';
				}
				$('.product-block-one .row').css('height', rowHeight );
			});

			$( ".product-block-three .image-half img" ).ready( function() {
				var productThreeImgHeight = $('.product-block-three .image-half img').height();
				var productThreeImgwidth = $('.product-block-three .image-half img').width();
				if (productThreeImgHeight === 0) {

				} else {
					var productThreeImgHeight = '800px';
				}
				$('.product-block-three .image-half').css('height', productThreeImgHeight );
				$('.product-block-three .image-half').css('width', productThreeImgwidth );
			});
		};

		$('.pagination .dots').addClass('page-link').parent().addClass('disabled');

		// You can put your own code in here

		$('#nav-icon').jPushMenu();
		$('.cbp-spmenu .nav-link').click(function(){
			$(this).children().next('ul').show();
		});

		$('#nav-icon').click(function(){
			$(this).toggleClass('open');
		});

		$('#close').click(function(){
			$('#formPopout').modal('hide')
		});

		$('.page-template-template-signup .menu-feature-btn.white-btn a').removeAttr('href');

		$('.page-template-template-signup .menu-feature-btn.white-btn a').click(function(){
			$('#formPopout').modal('show')
		});

		$('.mobile-overlay').click(function(){
			if ($('#nav-icon').hasClass('open')) {
				$('#nav-icon').toggleClass('open');
			}
		});

		$('#myModal').on('shown.bs.modal', function () {
			$('#myInput').trigger('focus')
		})

		$('#faqSelect').change(function() {
			var url = WPURLS.siteurl + '/faq/';
			var faqLink = $(this).val();
			console.log(url)
			$('#selectButton').attr('href', url + faqLink);
		})

	});


	$(window).bind("load", function() {
		$('.parts-solution').prepend("<iframe  id='scsps_configurator' style='height: 500px; width: 100%; frameborder: 0; padding: 0px; scrolling: auto;' src='https://harcourt-embedded.qa.partcommunity.com/3d-cad-models/?info=harcourt/alignment_pins/l_pins_and_t_pins/hlp_htp_asmtab.prj'>IFrame support required to view content</iframe>");
	});

}(jQuery));

// Hide Chat Button on on scroll down
var didScroll;
var lastScrollTop = 0;
var delta = 25;
var navbarHeight = jQuery('header').outerHeight();

jQuery(window).scroll(function(event){
	didScroll = true;
});

setInterval(function() {
	if (didScroll) {
		hasScrolled();
		didScroll = false;
	}
}, 0);

function hasScrolled() {
	var st = jQuery(this).scrollTop();

	// Make sure they scroll more than delta
	if(Math.abs(lastScrollTop - st) <= delta)
	return;

	// If they scrolled down and are past the navbar, add class .nav-up.

	// This is necessary so you never see what is "behind" the navbar.
	if (st > lastScrollTop && st > navbarHeight){
		// Scroll Down
		jQuery('header').removeClass('nav-down').addClass('nav-up');
	} else {
		// Scroll Up
		if(st + jQuery(window).height() < jQuery(document).height()) {
			jQuery('header').removeClass('nav-up').addClass('nav-down');
		}
	}

	lastScrollTop = st;
}

window.onscroll = function(ev) {
    if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 320 ) {
		const chat = document.querySelector('.olark-launch-button');
		
		chat.style.setProperty("display", "none", "important");
    } else {
		const chat = document.querySelector('.olark-launch-button');
		
		chat.style.setProperty("display", "block", "important");
	}
};

$('li.portal a').prepend('<img src="/scs_images/portal.png" title="Portal">');


// Loading spinner for News page
window.addEventListener("load", () => {
	const loader = document.querySelector(".loader");
	
	loader.classList.add("loader-hidden");
	
	loader.addEventListener("transitionend", () => {
		document.body.removeChild("loader");
	});
});