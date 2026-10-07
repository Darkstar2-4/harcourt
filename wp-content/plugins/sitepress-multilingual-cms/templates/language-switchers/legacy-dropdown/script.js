/*jshint browser:true, devel:true */
/*global document */

var WPMLLanguageSwitcherDropdown = (function() {
	"use strict";

	var toggleSelector = '.js-wpml-ls-legacy-dropdown a.js-wpml-ls-item-toggle';
	var parentSelector = '.wpml-ls-item-legacy-dropdown';

	var preventDefault = function(e) {
		var evt = e ? e : window.event;

		if (evt.preventDefault) {
			evt.preventDefault();
		}

		evt.returnValue = false;
	};

	var toggleSubmenu = function(toggle, show) {
		var parent = toggle.closest(parentSelector);
		if (!parent) return;

		var submenu = parent.querySelector('.wpml-ls-sub-menu');
		if (!submenu) return;

		var isExpanded = show !== undefined ? show : toggle.getAttribute('aria-expanded') === 'true';
		
		if (isExpanded) {
			toggle.setAttribute('aria-expanded', 'false');
			parent.classList.remove('wpml-ls-keyboard-open');
		} else {
			toggle.setAttribute('aria-expanded', 'true');
			parent.classList.add('wpml-ls-keyboard-open');
		}
	};

	var handleKeydown = function(e) {
		var key = e.key || e.keyCode;
		
		if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
			preventDefault(e);
			toggleSubmenu(this);
		}
		else if (key === 'Escape' || key === 'Esc' || key === 27) {
			if (this.getAttribute('aria-expanded') === 'true') {
				preventDefault(e);
				toggleSubmenu(this, false);
				this.focus();
			}
		}
	};

	var handleBlur = function(e) {
		var toggle = this;
		var parent = toggle.closest(parentSelector);
		if (!parent) return;

		setTimeout(function() {
			var focusedElement = document.activeElement;
			var submenu = parent.querySelector('.wpml-ls-sub-menu');
			
			if (submenu && !parent.contains(focusedElement)) {
				toggleSubmenu(toggle, false);
			}
		}, 100);
	};

	var init = function() {
		var links = document.querySelectorAll(toggleSelector);
		for(var i=0; i < links.length; i++) {
			links[i].addEventListener('click', preventDefault);
			links[i].addEventListener('keydown', handleKeydown);
			links[i].addEventListener('blur', handleBlur);
		}
	};

	return {
		'init': init
	};

})();

document.addEventListener('DOMContentLoaded', function(){
	"use strict";
	WPMLLanguageSwitcherDropdown.init();
});