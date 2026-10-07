<?php

class WPML_Elementor_Price_Table extends WPML_Elementor_Module_With_Items {

	public function get_items_field() {
		return 'features_list';
	}

	public function get_fields() {
		return array( 'item_text' );
	}

	protected function get_title( $field ) {
		if ( 'item_text' === $field ) {
			return esc_html__( 'Price Table: text', 'sitepress' );
		}

		return '';
	}

	protected function get_editor_type( $field ) {
		if ( 'item_text' === $field ) {
			return 'LINE';
		}

		return '';
	}
}
