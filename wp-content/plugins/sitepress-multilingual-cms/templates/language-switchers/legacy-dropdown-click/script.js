/*jshint browser:true, devel:true */
/*global document */

var WPMLLanguageSwitcherDropdownClick = (function() {
    "use strict";

    var wrapperSelector = '.js-wpml-ls-legacy-dropdown-click';
    var submenuSelector = '.js-wpml-ls-sub-menu';
    var isOpen = false;

    var toggle = function(event) {
        var subMenu = this.querySelectorAll(submenuSelector)[0];
        var toggleLink = this.querySelector('a.js-wpml-ls-item-toggle');

        if(subMenu.style.visibility === 'visible'){
            subMenu.style.visibility = 'hidden';
            if(toggleLink) {
                toggleLink.setAttribute('aria-expanded', 'false');
            }
            document.removeEventListener('click', close);
        }else{
            subMenu.style.visibility = 'visible';
            if(toggleLink) {
                toggleLink.setAttribute('aria-expanded', 'true');
            }
            document.addEventListener('click', close);
            isOpen = true;
        }

        return false;
    };

    var close = function(){

        if(!isOpen){
            var switchers = document.querySelectorAll(wrapperSelector);

            for(var i=0;i<switchers.length;i++){
                var altLangs = switchers[i].querySelectorAll(submenuSelector)[0];
                var toggleLink = switchers[i].querySelector('a.js-wpml-ls-item-toggle');
                altLangs.style.visibility = 'hidden';
                if(toggleLink) {
                    toggleLink.setAttribute('aria-expanded', 'false');
                }
            }
        }

        isOpen = false;
    };

    var preventDefault = function(e) {
        var evt = e ? e : window.event;

        if (evt.preventDefault) {
            evt.preventDefault();
        }

        evt.returnValue = false;
    };

    var handleKeydown = function(e) {
        var key = e.key || e.keyCode;

        if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
            preventDefault(e);
            var wrapper = this.closest(wrapperSelector);
            if (wrapper) {
                toggle.call(wrapper, e);
            }
        }
        else if (key === 'Escape' || key === 'Esc' || key === 27) {
            if (this.getAttribute('aria-expanded') === 'true') {
                preventDefault(e);
                var wrapper = this.closest(wrapperSelector);
                if (wrapper) {
                    var subMenu = wrapper.querySelector(submenuSelector);
                    if (subMenu) {
                        subMenu.style.visibility = 'hidden';
                        this.setAttribute('aria-expanded', 'false');
                        this.focus();
                    }
                }
            }
        }
    };

    var init = function() {
        var wrappers = document.querySelectorAll(wrapperSelector);
        for(var i=0; i < wrappers.length; i++ ) {
            wrappers[i].addEventListener('click', toggle );
        }

        var links = document.querySelectorAll(wrapperSelector + ' a.js-wpml-ls-item-toggle');
        for(var j=0; j < links.length; j++) {
            links[j].addEventListener('click', preventDefault );
            links[j].addEventListener('keydown', handleKeydown );
        }
    };

    return {
        'init': init
    };

})();

document.addEventListener('DOMContentLoaded', function(){
    "use strict";
    WPMLLanguageSwitcherDropdownClick.init();
});
