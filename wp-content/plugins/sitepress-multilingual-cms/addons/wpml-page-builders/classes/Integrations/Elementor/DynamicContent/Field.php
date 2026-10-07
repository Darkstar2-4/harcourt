<?php

namespace WPML\PB\Elementor\DynamicContent;

use WPML_PB_String;

class Field {

	public $tagValue;

	public $tagKey;

	public $nodeId;

	public $itemId;

	public function __construct( $tagValue, $tagKey, $nodeId, $itemId = '' ) {
		$this->tagValue = $tagValue;
		$this->tagKey   = $tagKey;
		$this->nodeId   = $nodeId;
		$this->itemId   = $itemId;
	}

	public function isMatchingStaticString( WPML_PB_String $string ) {
		$pattern = '/^' . $this->tagKey . '-.*-' . $this->nodeId . '$/';

		if ( $this->itemId ) {
			$pattern = '/^.*-' . $this->tagKey . '-' . $this->nodeId . '-' . $this->itemId . '$/';
		}

		return (bool) preg_match( $pattern, $string->get_name() );
	}
}
