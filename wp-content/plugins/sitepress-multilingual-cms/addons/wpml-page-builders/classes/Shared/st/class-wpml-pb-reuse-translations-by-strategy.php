<?php

class WPML_PB_Reuse_Translations_By_Strategy extends WPML_PB_Reuse_Translations {

	private $strategy;

	private $original_strings_by_strategy;

	public function __construct( IWPML_PB_Strategy $strategy, WPML_ST_String_Factory $string_factory ) {
		$this->strategy = $strategy;
		parent::__construct( $string_factory );
	}

	public function set_original_strings( array $strings ) {
		$this->original_strings_by_strategy = $strings;
	}

	public function find_and_reuse( $post_id, array $leftover_strings ) {
		$current_strings = $this->get_strings( $post_id );
		$this->find_and_reuse_translations( $this->original_strings_by_strategy, $current_strings, $leftover_strings );
	}

	public function get_strings( $post_id ) {
		return $this->strategy->get_package_strings( $this->strategy->get_package_key( $post_id ) );
	}
}
