<?php

class WPML_Resolve_Absolute_Url_Cached implements IWPML_Resolve_Object_Url {

	private $url_persisted;

	private $resolve_url;

	public function __construct( WPML_Absolute_Url_Persisted $url_persisted, WPML_Resolve_Absolute_Url $resolve_url ) {
		$this->url_persisted = $url_persisted;
		$this->resolve_url   = $resolve_url;
	}

	public function resolve_object_url( $url, $lang ) {
		$resolved_url = $this->url_persisted->get( $url, $lang );

		if ( null === $resolved_url ) {
			$resolved_url = $this->resolve_url->resolve_object_url( $url, $lang );
			$this->url_persisted->set( $url, $lang, $resolved_url );
		}

		return $resolved_url;
	}
}
