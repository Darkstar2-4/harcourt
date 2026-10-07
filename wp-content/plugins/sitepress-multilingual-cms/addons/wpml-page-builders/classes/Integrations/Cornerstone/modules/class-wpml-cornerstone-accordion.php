<?php

class WPML_Cornerstone_Accordion extends WPML_Cornerstone_Module_With_Items {

	public function get_fields() {
		return array( 'accordion_item_header_content', 'accordion_item_content' );
	}

	protected function get_title( $field ) {
		if ( 'accordion_item_header_content' === $field ) {
			return esc_html__( 'Accordion: Header', 'sitepress' );
		}

		if ( 'accordion_item_content' === $field ) {
			return esc_html__( 'Accordion: Content', 'sitepress' );
		}

		return '';
	}

	protected function get_editor_type( $field ) {
		if ( 'accordion_item_header_content' === $field ) {
			return 'LINE';
		} else {
			return 'VISUAL';
		}
	}
}