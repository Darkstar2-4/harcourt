<?php

namespace WPML\PB\Elementor\Hooks;

use WPML\LIB\WP\Hooks;

use function WPML\FP\spreadArgs;

class SavePostActions implements \IWPML_REST_Action, \IWPML_DIC_Action {

	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function add_hooks() {
		if ( wpml_is_rest_request() ) {
			Hooks::onAction( 'elementor/document/after_save' )
				->then( spreadArgs( [ $this, 'setLanguageInformation' ] ) );
		}
	}

	public function setLanguageInformation( $document ) {
		$post = $document->get_post();
		$type = 'post_' . get_post_type( $post );
		$trid = $this->sitepress->get_element_trid( $post->ID, $type );
		if ( $trid ) {
			return;
		}

		$this->sitepress->set_element_language_details(
			$post->ID,
			$type,
			null,
			$this->sitepress->get_current_language()
		);
	}
}
