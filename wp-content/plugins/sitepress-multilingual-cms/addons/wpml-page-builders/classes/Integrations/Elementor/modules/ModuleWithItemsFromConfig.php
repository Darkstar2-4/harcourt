<?php

namespace WPML\PB\Elementor\Modules;

use WPML\FP\Obj;

class ModuleWithItemsFromConfig extends \WPML_Elementor_Module_With_Items {

	private $fields = [];

	private $fieldDefinitions = [];

	private $itemsField;

	public function __construct( $itemsField, array $config ) {
		$this->itemsField = $itemsField;
		$this->init( $config );
	}

	private function init( array $config ) {
		foreach ( $config as $key => $fieldConfig ) {
			$field = Obj::prop( 'field', $fieldConfig );
			$keyOf = is_string( $key ) ? $key : null;

			if ( $keyOf ) {
				$this->fields[ $keyOf ] = [ $field ];
			} else {
				$this->fields[] = $field;
			}

			$this->fieldDefinitions[ $field ] = $fieldConfig;
		}
	}

	private function getFieldData( $field, $key ) {
		return Obj::path( [ $field, $key ], $this->fieldDefinitions );
	}

	public function get_title( $field ) {
		return $this->getFieldData( $field, 'type' );
	}

	public function get_fields() {
		return $this->fields;
	}

	public function get_editor_type( $field ) {
		return $this->getFieldData( $field, 'editor_type' );
	}

	public function get_items_field() {
		return $this->itemsField;
	}
}
