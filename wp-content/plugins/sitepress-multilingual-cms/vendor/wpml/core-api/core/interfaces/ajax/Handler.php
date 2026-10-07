<?php

namespace WPML\Ajax;

use WPML\Collect\Support\Collection;

interface IHandler {
	public function run( Collection $data );
}
