<?php

class WPML_API_Hook_Translation_Element implements IWPML_Action {
	private $flags_factory;
	private $sitepress;
	private $translation_element_factory;

	public function __construct(
		SitePress $sitepress,
		WPML_Translation_Element_Factory $translation_element_factory,
		WPML_Flags_Factory $flags_factory
	) {
		$this->sitepress                   = $sitepress;
		$this->translation_element_factory = $translation_element_factory;
		$this->flags_factory               = $flags_factory;
	}

	public function add_hooks() {
		add_filter( 'wpml_post_language_flag_url', array( $this, 'get_post_language_flag_url' ), 10, 3 );
	}

	public function get_post_language_flag_url(
		$default,
		$element_id,
		$element_type = WPML_Translation_Element_Factory::ELEMENT_TYPE_POST
	) {
		if ( ! $element_id ) {
			return $default;
		}

		$wpml_post = $this->translation_element_factory->create( $element_id, $element_type );
		$flag      = $this->flags_factory->create();

		return $flag->get_flag_url( $wpml_post->get_language_code() );
	}
}
