<?php
namespace WPML\CLI\Core\Commands;

use function WPML\Container\make;

class ClearCacheFactory implements IWPML_Core {

	public function create() {
		return make( ClearCache::class );
	}
}
