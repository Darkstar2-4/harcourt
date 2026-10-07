<?php

namespace WPML\CustomFieldTranslation;

use WPML_WP_API;

class TranslateIdsInTermCustomFieldsHooks {

	public function __construct( $translateIds, &$wpApi ) {

		if ( $translateIds->hasCustomFields() ) {
			$wpApi->add_filter( 'get_term_metadata', array( $translateIds, 'maybeTranslateIds' ), 10, 4 );
		}
	}

}
