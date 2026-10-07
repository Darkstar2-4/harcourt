<?php
class WPML_LS_Shortcodes extends WPML_LS_Public_API {
	const LS                 = 'wpml_language_switcher';
	const LS_WIDGET          = 'wpml_language_selector_widget';
	const LS_FOOTER          = 'wpml_language_selector_footer';

	public function init_hooks() {
		if ( $this->sitepress->get_setting( 'setup_complete' ) ) {
			add_shortcode( self::LS, array( $this, 'callback' ) );

			add_shortcode( self::LS_WIDGET, array( $this, 'callback' ) );
			add_shortcode( self::LS_FOOTER, array( $this, 'callback' ) );
		}
	}

	public function callback( $args, $content = null, $tag = '' ) {
		$args = (array) $args;
		$args = $this->parse_legacy_shortcodes( $args, $tag );
		$args = $this->convert_shortcode_args_aliases( $args );

		return $this->render( $args );
	}


	private function parse_legacy_shortcodes( $args, $tag ) {
		if ( 'wpml_language_selector_widget' === $tag ) {
			$args['type'] = 'custom';
		} elseif ( 'wpml_language_selector_footer' === $tag ) {
			$args['type'] = 'footer';
		}

		return $args;
	}

}
