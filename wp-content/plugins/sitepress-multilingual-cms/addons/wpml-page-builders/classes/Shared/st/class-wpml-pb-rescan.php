<?php

class WPML_PB_Integration_Rescan {
	private $integrator;

	public function __construct( WPML_PB_Integration $integrator ) {
		$this->integrator = $integrator;
	}

	public function rescan( array $translation_package, $post ) {
		$string_packages = apply_filters( 'wpml_st_get_post_string_packages', false, $post->ID );
		if ( ! $string_packages ) {
			$this->integrator->register_all_strings_for_translation( $post );
		}

		return $translation_package;
	}
}