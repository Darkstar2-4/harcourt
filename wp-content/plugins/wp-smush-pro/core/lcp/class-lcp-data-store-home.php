<?php

namespace Smush\Core\LCP;

class LCP_Data_Store_Home extends LCP_Data_Store_Option {
	private static $type = 'home';

	public function __construct() {
		parent::__construct( self::$type );
	}

	/**
	 * Get type.
	 *
	 * @return string
	 */
	public static function get_type_key() {
		return self::$type;
	}
}