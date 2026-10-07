<?php

class WPML_Taxonomy_Notice_Display_Validator {

	private $taxonomy_id;

	public function __construct( $taxonomy_id ) {
		$this->taxonomy_id = $taxonomy_id;
	}

	public function __invoke() {
		return WPML_Taxonomy_Translation_Help_Notice::validate_display_for_taxonomy( $this->taxonomy_id );
	}

	public function get_taxonomy_id() {
		return $this->taxonomy_id;
	}
}

