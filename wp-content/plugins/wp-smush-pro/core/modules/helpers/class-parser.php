<?php
/**
 * Smush page parser that is used by CDN and Lazy load modules.
 *
 * @since 3.2.2
 * @package Smush\Core\Modules\Helpers
 */

namespace Smush\Core\Modules\Helpers;

/**
 * Class Parser
 */
class Parser {
	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.2.0' );
	}
}