<?php

abstract class WPML_Beaver_Builder_Module_With_Items implements IWPML_Page_Builders_Module {

	abstract protected function get_title( $field );

	protected function get_fields() {
		return array( 'label', 'content' );
	}

	protected function get_editor_type( $field ) {
		switch( $field ) {
			case 'label':
				return 'LINE';

			case 'content':
				return 'VISUAL';

			default:
				return '';
		}
	}

	protected function &get_items( $settings ) {
		return $settings->items;
	}

	public function get( $node_id, $settings, $strings ) {
		foreach ( $this->get_items( $settings ) as $item ) {
			foreach( $this->get_fields() as $field ) {
				if ( is_array( $item->$field ) ) {
					foreach ( $item->$field as $key => $value ) {
						$strings[] = new WPML_PB_String(
							$value,
							$this->get_string_name( $node_id, $value, $field, $key ),
							$this->get_title( $field ),
							$this->get_editor_type( $field )
						);
					}
				} else {
					$strings[] = new WPML_PB_String(
						$item->$field,
						$this->get_string_name( $node_id, $item->$field, $field ),
						$this->get_title( $field ),
						$this->get_editor_type( $field )
					);
				}
			}
		}
		return $strings;
	}

	public function update( $node_id, $settings, WPML_PB_String $string ) {
		foreach ( $this->get_items( $settings ) as &$item ) {
			foreach( $this->get_fields() as $field ) {
				if ( is_array( $item->$field ) ) {
					foreach ( $item->$field as $key => &$value ) {
						if ( $this->get_string_name( $node_id, $value, $field, $key ) == $string->get_name() ) {
							$value = $string->get_value();
						}
					}
				} else {
					if ( $this->get_string_name( $node_id, $item->$field, $field ) == $string->get_name() ) {
						$item->$field = $string->get_value();
					}
				}
			}
		}

		return null;
	}

	private function get_string_name( $node_id, $value, $type, $key = '' ) {
		return md5( $value ) . '-' . $type . $key . '-' . $node_id;
	}

}