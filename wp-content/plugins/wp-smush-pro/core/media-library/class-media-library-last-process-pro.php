<?php

namespace Smush\Core\Media_Library;

class Media_Library_Last_Process_Pro extends Media_Library_Last_Process {
	public function __call( $name, $arguments ) {
		_deprecated_function( $name, '4.1.0' );
	}
}