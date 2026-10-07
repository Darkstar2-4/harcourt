<?php

class WPML_Frontend_Redirection_Url {

	private $url;

	public function __construct( $url ) {
		$this->url = $url;
	}

	public function encode_apostrophes_in_url() {
		return str_replace( "'", rawurlencode( "'" ), $this->url );
	}
}
