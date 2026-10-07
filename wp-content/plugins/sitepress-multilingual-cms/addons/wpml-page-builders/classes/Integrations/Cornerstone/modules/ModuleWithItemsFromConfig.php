<?php

namespace WPML\PB\Cornerstone\Modules;

use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Obj;

class ModuleWithItemsFromConfig extends \WPML_Cornerstone_Module_With_Items {

	private $fieldDefinitions;

	public function __construct( array $config ) {
		$keyByField             = Fns::converge( Lst::zipObj(), [ Lst::pluck( 'field' ), Fns::identity() ] );
		$this->fieldDefinitions = $keyByField( $config );
	}

	public function get_title( $field ) {
		return Obj::path( [ $field, 'type' ], $this->fieldDefinitions );
	}

	public function get_fields() {
		return array_keys( $this->fieldDefinitions );
	}

	public function get_editor_type( $field ) {
		return Obj::path( [ $field, 'editor_type' ], $this->fieldDefinitions );
	}
}
