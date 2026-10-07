<?php

namespace WPML\FP\Functor;


trait Functor {
	protected $value;

	public function __construct( $value ) {
		$this->value = $value;
	}

	public function get() {
		return $this->value;
	}

	abstract public function map( callable $callback );

}
