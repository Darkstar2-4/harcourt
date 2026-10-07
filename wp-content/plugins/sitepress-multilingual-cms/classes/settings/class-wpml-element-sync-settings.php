<?php

class WPML_Element_Sync_Settings {

	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function is_sync( $type ) {
		return isset( $this->settings[ $type ] ) &&
			   (
				   $this->settings[ $type ] == WPML_CONTENT_TYPE_TRANSLATE ||
				   $this->settings[ $type ] == WPML_CONTENT_TYPE_DISPLAY_AS_IF_TRANSLATED
			   );
	}

}
