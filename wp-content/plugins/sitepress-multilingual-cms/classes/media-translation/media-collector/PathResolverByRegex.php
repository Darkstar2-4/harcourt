<?php

namespace WPML\MediaTranslation\MediaCollector;

class PathResolverByRegex implements PathResolverInterface {
	private $regex;

	public function __construct( $regex ) {
		$this->regex = $regex;
	}

	public function getValue( $data ) {
		if ( ! is_string( $data ) ) {
			return '';
		}

		if ( ! preg_match( $this->regex, $data, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	public function resolvePath( $data ) {
		if ( ! is_string( $data ) ) {
			return [];
		}

		if ( ! preg_match_all( $this->regex, $data, $matches ) ) {
			return [];
		}

		return $matches[1];
	}
}

