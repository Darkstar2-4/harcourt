<?php

use WPML\FP\Fns;
use function WPML\FP\invoke;

class WPML_TP_Translation_Collection implements IteratorAggregate {
	private $translations;

	private $source_language;

	private $target_language;

	public function __construct( array $translations, $source_language, $target_language ) {
		$this->translations    = $translations;
		$this->source_language = $source_language;
		$this->target_language = $target_language;
	}

	public function get_source_language() {
		return $this->source_language;
	}

	public function get_target_language() {
		return $this->target_language;
	}

	public function getIterator(): Traversable {
		return new ArrayIterator( $this->translations );
	}

	public function to_array() {
		return [
			'source_language' => $this->source_language,
			'target_language' => $this->target_language,
			'translations'    => Fns::map( invoke( 'to_array' ), $this->translations ),
		];
	}
}
