<?php

namespace WPML\CustomFieldTranslation;

use WPML_WP_API;

class TranslateIdsInPostCustomFieldsHooks {

	public function __construct( $translateIds, &$wpApi ) {

		if ( $translateIds->hasCustomFields() ) {
			$wpApi->add_filter( 'get_post_metadata', array( $translateIds, 'maybeTranslateIds' ), 10, 4 );
		}
	}

}

