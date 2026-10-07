<?php

namespace WPML\PB\Elementor\Media\Modules;

use WPML\FP\Obj;

class EImage extends \WPML_Elementor_Media_Node {

	public function translate( $settings, $target_lang, $source_lang ) {
		$idPath = [ 'image', 'value', 'src', 'value', 'id', 'value' ];

		$imageId = Obj::path( $idPath, $settings );
		if ( $imageId ) {
			$translatedId = $this->media_translate->translate_id( $imageId, $target_lang );
			$settings     = Obj::assocPath( $idPath, $translatedId, $settings );
		}

		return $settings;
	}
}
