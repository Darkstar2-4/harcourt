<?php

namespace WPML\CustomFieldTranslation;

use WPML\Convert\Ids;
use WPML\FP\Obj;

class TranslateIdsInTermCustomFields extends TranslateIds {

	const TM_SETTINGS_KEY = 'custom_term_fields_translate_ids';

	protected function getTmSettingsKey() {
		return self::TM_SETTINGS_KEY;
	}

	protected function getRawValue( $metadata, $object_id, $meta_key, $single ) {
		$this->wpApi->remove_filter( 'get_term_metadata', array( $this, 'maybeTranslateIds' ), 10 );
		$metadata_raw = maybe_unserialize( $this->wpApi->get_term_meta( $object_id, $meta_key, $single ) );
		$this->wpApi->add_filter( 'get_term_metadata', array( $this, 'maybeTranslateIds' ), 10, 4 );

		return $metadata_raw;
	}

}
