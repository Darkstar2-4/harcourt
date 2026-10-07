<?php

abstract class WPML_Elementor_Media_Node_With_Images_Property extends WPML_Elementor_Media_Node {

	abstract protected function get_property_name();

	public function translate( $settings, $target_lang, $source_lang ) {
		return $this->translate_images_property( $settings, $this->get_property_name(), $target_lang, $source_lang );
	}
}
