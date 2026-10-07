<?php

namespace WPML\Element\API\Entity;

use WPML\FP\Lst;

class LanguageMapping {
	private $sourceCode;
	private $sourceName;
	private $targetId;
	private $targetCode;

	public function __construct( $sourceCode = null, $sourceName = null, $targetId = null, $targetCode = null ) {
		$this->sourceCode = $sourceCode;
		$this->sourceName = $sourceName;
		$this->targetId   = (int) $targetId;
		$this->targetCode = $targetCode;
	}

	public function toATEFormat () {
		return [
			'source_language' => [ 'code' => $this->sourceCode, 'name' => $this->sourceName ],
			'target_language' => [ 'id' => $this->targetId, 'code' => $this->targetCode ],
		];
	}

	public function __get( $name ) {
		return isset( $this->$name ) ? $this->$name : null;
	}

	public function __isset( $name ) {
		return Lst::includes( $name, array_keys( get_object_vars( $this ) ) );
	}

	public function matches( $languageCode ) {
		return strtolower( $this->sourceCode ) === strtolower( $languageCode );
	}
}