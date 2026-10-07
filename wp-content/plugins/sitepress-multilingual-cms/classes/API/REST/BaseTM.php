<?php

namespace WPML\TM\REST;

abstract class Base extends \WPML\Rest\Base {

	public function get_namespace() {
		return 'wpml/tm/v1';
	}
}
