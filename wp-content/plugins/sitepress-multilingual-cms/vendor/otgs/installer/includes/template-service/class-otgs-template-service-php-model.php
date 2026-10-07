<?php

class OTGS_Template_Service_Php_Model {
	private $attributes = [];

	public function __construct( $data = [] ) {
		foreach ( $data as $key => $value ) {
			$this->__set( $key, $value );
		}
	}

	public function __get( $name ) {
		if ( ! array_key_exists( $name, $this->attributes ) ) {
			$this->attributes[ $name ] = new OTGS_Template_Service_Php_Model();
		}

		return $this->attributes[ $name ];
	}

	public function __set( $name, $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			if( $this->isAssoc( $value ) ) {
				$value = new OTGS_Template_Service_Php_Model( $value );
			} else {
				foreach ($value as $id => $element) {
					$value[$id] = $this->isAssoc( $element ) ? new OTGS_Template_Service_Php_Model( $element ) : $element;
				}
			}
		}
		$this->attributes[ $name ] = $value;
	}

	private function isAssoc( $value ) {
		return is_array( $value ) && count( array_filter( array_keys( $value ), 'is_string' ) ) > 0;
	}

	public function hasValue( $name ) {
		return ! $this->isNull( $name ) && ! $this->isEmpty( $name );
	}

	public function isNull( $name ) {
		return $this->__get( $name ) === null;
	}

	public function isEmpty( $name ) {
		return $this->__get( $name ) === ''
		       || ( ( $this->__get( $name ) instanceof OTGS_Template_Service_Php_Model )
		            && ! $this->__get( $name )->getAttributes()
		       );
	}

	public function getAttributes() {
		return $this->attributes;
	}

	public function __toString() {
		if ( count( $this->attributes ) === 0 ) {
			return '';
		}
		if ( count( $this->attributes ) === 1 ) {
			return array_values( $this->attributes )[0];
		}

		return wp_json_encode( $this->attributes );
	}

}