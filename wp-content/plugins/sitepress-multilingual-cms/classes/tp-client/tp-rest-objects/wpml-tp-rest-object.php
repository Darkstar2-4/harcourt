<?php

abstract class WPML_TP_REST_Object {

	public function __construct( ?stdClass $obj = null ) {
		$this->populate_properties_from_object( $obj );
	}

	abstract protected function get_properties();

	protected function populate_properties_from_object( ?stdClass $obj ) {
		if ( $obj ) {
			$properties = $this->get_properties();

			foreach ( $properties as $object_property => $new_property ) {
				if ( isset( $obj->{$object_property} ) ) {
					$this->{"set_$new_property"}( $obj->{$object_property} );
				}
			}
		}
	}
}
