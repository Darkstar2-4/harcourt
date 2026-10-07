<?php

class WPML_Page_Builders_Media_Gutenberg_Update_Factory extends WPML_Page_Builders_Media_Update_Factory {

	private $config_option;

	private $element_factory;

	public function __construct( WPML_Gutenberg_Config_Option $config_option ) {
		$this->config_option = $config_option;
	}

	public function create( $find_usage_instead_of_translate = false ) {
		$media_gutenberg = new WPML_Page_Builders_Media_Gutenberg(
			$this->get_media_translate( $find_usage_instead_of_translate, true ),
			$this->config_option->get_media_in_blocks()
		);

		return new WPML_Page_Builders_Media_Gutenberg_Update(
			$this->get_element_factory(),
			$media_gutenberg,
			$find_usage_instead_of_translate
				? null
				: new WPML_Page_Builders_Media_Usage( $this->get_media_translate( $find_usage_instead_of_translate, true ), new WPML_Media_Usage_Factory() )
		);
	}

	private function get_element_factory() {
		global $sitepress;

		if ( ! $this->element_factory ) {
			$this->element_factory = new WPML_Translation_Element_Factory( $sitepress );
		}

		return $this->element_factory;
	}
}
