/*globals jQuery, document */

var WPML_Core = WPML_Core || {};
WPML_Core.taxonomy_sync_complete_reload = function () {
	"use strict";
	var url = new URL(window.location.href);
  // Add parameter to prevent duplicate PostHog event capture
	url.searchParams.set('event_captured', '1');
	window.location.href = url.toString();
}

WPML_Core.taxonomy_sync_complete = function ( event, xhr, settings ) {
	"use strict";

	if ( settings.data && typeof settings.data === 'string' ) {
		if ( settings.data.search( 'action=wpml_tt_sync_hierarchy_save' ) !== -1 ) {
			WPML_Core.taxonomy_sync_complete_reload();
		}
	}
}

jQuery(function () {
    "use strict";

    jQuery(document).ajaxComplete(WPML_Core.taxonomy_sync_complete);
});
