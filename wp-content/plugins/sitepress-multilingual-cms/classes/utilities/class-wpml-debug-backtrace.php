<?php

class WPML_Debug_BackTrace extends WPML\Utils\DebugBackTrace {

	public function __construct(
		$php_version = null,
		$limit = 0,
		$provide_object = false,
		$ignore_args = true,
		$debug_backtrace_function = null
	) {
		parent::__construct( $limit, $provide_object, $ignore_args, $debug_backtrace_function );
	}
}
