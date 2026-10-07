<?php

namespace WPML\Utilities;

use function WPML\Container\make;

class Lock implements ILock {
	private static $active_locks = [];

	private $wpdb;

	protected $name;

	public function __construct( \wpdb $wpdb, $name ) {
		$this->wpdb = $wpdb;
		$this->name = 'wpml.' . $name . '.lock';
	}

	public static function whileLocked( $lockName, $releaseTimeout, callable $fn ) {
		$lock = make( Lock::class, [ ':name' => $lockName ] );
		if ( $lock->create( $releaseTimeout ) ) {
			$fn();
			$lock->release();
		}
	}

	public function create( $release_timeout = null, $retry_limit = 10 ) {
		if ( ! $release_timeout ) {
			$release_timeout = HOUR_IN_SECONDS;
		}

		if ( isset( self::$active_locks[ $this->name ] ) ) {
			return false;
		}

		$lock_result = $this->wpdb->query( $this->wpdb->prepare( "INSERT IGNORE INTO {$this->wpdb->options} ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'no') /* LOCK */", $this->name, time() ) );

		if ( ! $lock_result ) {
			$lock_result = get_option( $this->name );


			if ( ! $this->isValidLockTimeout($lock_result) ) {
				$lock_result = 1;
			}
			if ( ! $lock_result || $lock_result > ( time() - $release_timeout ) ) {
				self::$active_locks[ $this->name ] = false;
				return false;
			}

			if ( $retry_limit <= 0 || ! $this->release() ) {
				self::$active_locks[ $this->name ] = false;
				return false;
			}
			return self::create( $release_timeout, $retry_limit - 1 );
		}

		update_option( $this->name, time(), false );

		self::$active_locks[ $this->name ] = true;

		return true;
	}

	public function release() {
		unset( self::$active_locks[ $this->name ] );
		return delete_option( $this->name );
	}

	private function isValidLockTimeout( $lock_result ) {

		return is_numeric( $lock_result ) && $lock_result > 0;

	}


}
