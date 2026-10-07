<?php

namespace WPML\FP\Invoker;

use function WPML\FP\pipe;

class BeforeAfter {

	private $fn;

	private $before;

	private $after;

	private function __construct( callable $before, callable $after ) {
		$this->before = $before;
		$this->after = $after;
	}

	public function invoke( callable $fn ) {
		$this->fn = $fn;
		return $this;
	}

	public function then( callable $before, callable $after ) {
		return new BeforeAfter(
			pipe( $this->before, $before ),
			pipe( $after, $this->after )
		);
	}

	public function runWith( ...$args ) {
		call_user_func( $this->before );
		$result = call_user_func_array( $this->fn, $args );
		call_user_func( $this->after );
		return $result;
	}

	public static function of( callable $before, callable $after ) {
		return new BeforeAfter( $before, $after );
	}
}

