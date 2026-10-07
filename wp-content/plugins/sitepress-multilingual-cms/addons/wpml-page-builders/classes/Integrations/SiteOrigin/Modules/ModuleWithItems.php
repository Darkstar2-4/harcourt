<?php

namespace WPML\PB\SiteOrigin\Modules;

use WPML\FP\Obj;

abstract class ModuleWithItems implements \IWPML_Page_Builders_Module {

	abstract protected function get_title( $field );

	abstract protected function get_fields();

	abstract protected function get_editor_type( $field );

	abstract public function get_items_field();

	abstract public function get_items( $settings );

	public function get( $node_id, $settings, $strings ) {
		foreach ( $this->get_items( $settings ) as $key => $item ) {
			foreach ( $this->get_fields() as $field ) {
				$pathInFlatField = explode( self::FIELD_SEPARATOR, $field );
				$string_value    = Obj::path( $pathInFlatField, $item );
				if ( $string_value ) {
					$strings[] = new \WPML_PB_String(
						$string_value,
						$this->get_string_name( $node_id, $this->get_items_field(), $key, $field ),
						$this->get_title( $field ),
						$this->get_editor_type( $field )
					);
				}
			}
		}

		return $strings;
	}

	public function update( $node_id, $element, \WPML_PB_String $pbString ) {
		foreach ( $this->get_items( $element ) as $key => $item ) {
			foreach ( $this->get_fields() as $field ) {
				if ( $this->get_string_name( $node_id, $this->get_items_field(), $key, $field ) === $pbString->get_name() ) {
					$pathInFlatField   = explode( self::FIELD_SEPARATOR, $field );
					$stringInFlatField = Obj::path( $pathInFlatField, $item );
					if ( is_string( $stringInFlatField ) ) {
						$item = Obj::assocPath( $pathInFlatField, $pbString->get_value(), $item );
					}
					return [ $key, $item ];
				}
			}
		}

		return [ null, null ];
	}

	private function get_string_name( $node_id, $type, $key, $field ) {
		return $node_id . '-' . $type . '-' . $key . '-' . $field;
	}

	public function get_field_path( $key ) {
		$path = $this->get_items_field();
		if ( strpos( $path, self::FIELD_SEPARATOR ) ) {
			list( $parent, $path ) = explode( self::FIELD_SEPARATOR, $path, 2 );
			list( $x, $y )         = explode( self::FIELD_SEPARATOR, $key, 2 );
			return [ $parent, $x, $path, $y ];
		} else {
			return [ $path, $key ];
		}
	}
}
