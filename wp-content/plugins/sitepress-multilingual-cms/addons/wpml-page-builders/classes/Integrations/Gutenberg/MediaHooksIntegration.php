<?php

namespace WPML\PB\Gutenberg;

class MediaHooksIntegration implements Integration {

	private $config;

	public function __construct( \WPML_Gutenberg_Config_Option $config ) {
		$this->config = $config;
	}

	public function add_hooks() {
		$shouldAddMediaHooks = defined( 'WPML_MEDIA_VERSION' ) && $this->config->get_media_in_blocks();

		$gutenbergMediaHooks = new \WPML_Page_Builders_Media_Hooks(
			new \WPML_Page_Builders_Media_Gutenberg_Update_Factory( $this->config ),
			'gutenberg'
		);

		$gutenbergMediaHooks->add_hooks( $shouldAddMediaHooks );
	}
}
