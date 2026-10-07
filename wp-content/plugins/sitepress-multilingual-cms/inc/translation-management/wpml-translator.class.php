<?php

class WPML_Translator {
	public $ID;

	public $display_name;

	public $user_login;

	public $language_pairs;

	public function __get( $property ) {
		if ( $property == 'translator_id' ) {
			return $this->ID;
		}
		return null;
	}

	public function __set( $property, $value ) {
		if ( $property == 'translator_id' ) {
			$this->ID = $value;
		}
		return null;
	}
}
