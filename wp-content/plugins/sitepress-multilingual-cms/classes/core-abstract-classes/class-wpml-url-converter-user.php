<?php

abstract class WPML_URL_Converter_User {

	protected $url_converter;

	public function __construct( &$url_converter ) {
		$this->url_converter = &$url_converter;
	}

}
