<?php

namespace WPML\MediaTranslation\MediaCollector;

class PathResolverByString implements PathResolverInterface {
	private $string;

	public function __construct( $string ) {
		$this->string = $string;
	}

	public function getValue( $data ) {
		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return '';
		}

		$data = (object) $data;

		if ( ! isset( $data->{$this->string} ) ) {
			return '';
		}

		$data = $data->{$this->string};

		return is_string( $data ) || is_numeric( $data ) ? $data : '';
	}

	public function resolvePath( $data ) {
		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return [];
		}

		$data = (array) $data;
		if ( ! isset( $data[ $this->string ] ) ) {
			return [];
		}

		$data = $data[ $this->string ];

		return $data;
	}
}
