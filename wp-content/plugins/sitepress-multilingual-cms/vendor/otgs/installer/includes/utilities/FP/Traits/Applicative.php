<?php

namespace OTGS\Installer\FP\Traits;

trait Applicative {
	public function ap( $otherContainer ) {
		return $otherContainer->map( $this->value );
	}
}