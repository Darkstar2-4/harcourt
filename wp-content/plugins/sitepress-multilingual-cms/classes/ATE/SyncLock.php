<?php

namespace WPML\TM\ATE;

use WPML\Utilities\KeyedLock;
use function WPML\Container\make;

class SyncLock {
	private $keyedLock;

	public function __construct() {
		$this->keyedLock = make( KeyedLock::class, [ ':name' => 'ate_sync' ] );
	}

	public function create( $key = null ) {
		return $this->keyedLock->create( $key, 30 );
	}

	public function release() {
		return $this->keyedLock->release();
	}
}