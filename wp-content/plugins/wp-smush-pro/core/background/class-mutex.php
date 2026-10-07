<?php

namespace Smush\Core\Background;

class Mutex {
	private static $required_mysql_version = '5.7';

	/**
	 * @var string
	 */
	private $key;
	/**
	 * TRUE: Don't perform the operation if lock couldn't be acquired
	 * FALSE: Even if lock is not acquired, still perform the operation
	 *
	 * @var bool
	 */
	private $break_on_timeout = false;
	/**
	 * @var int
	 */
	private $timeout = 10;
	/**
	 * @var string|null
	 */
	private $mysql_version;

	public function __construct( $key ) {
		$this->key = $key;
	}

	public function execute( $operation ) {
		if ( $this->is_supported() ) {
			$acquired = $this->acquire_lock();
			if ( $acquired || ! $this->break_on_timeout() ) {
				try {
					call_user_func( $operation );
				} finally {
					if ( $acquired ) {
						$this->release_lock();
					}
				}
			}
		} else {
			call_user_func( $operation );
		}
	}

	private function acquire_lock() {
		global $wpdb;

		$lock = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s,%d) as lock_set',
				array(
					$this->get_key(),
					$this->get_timeout(),
				)
			)
		);

		return 1 === intval( $lock->lock_set );
	}

	private function release_lock() {
		global $wpdb;

		$wpdb->get_row(
			$wpdb->prepare(
				'SELECT RELEASE_LOCK(%s) as lock_released',
				array( $this->get_key() )
			)
		);
	}

	/**
	 * @return bool
	 */
	public function break_on_timeout() {
		return $this->break_on_timeout;
	}

	/**
	 * @param bool $break_on_timeout
	 */
	public function set_break_on_timeout( $break_on_timeout ) {
		$this->break_on_timeout = $break_on_timeout;

		return $this;
	}

	/**
	 * @return int
	 */
	public function get_timeout() {
		return $this->timeout;
	}

	/**
	 * @param int $timeout
	 */
	public function set_timeout( $timeout ) {
		$this->timeout = max( 0, (int) $timeout );

		return $this;
	}

	/**
	 * @return string
	 */
	public function get_key() {
		return $this->key;
	}

	/**
	 * @param string $key
	 */
	public function set_key( $key ) {
		$this->key = $key;

		return $this;
	}

	private function is_supported() {
		return $this->is_mysql_requirement_met();
	}

	private function get_actual_mysql_version() {
		if ( ! $this->mysql_version ) {
			global $wpdb;

			$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );

			/*
			 * Some MariaDB servers report a compatibility prefix such as
			 * 5.5.5-10.11.6-MariaDB. Compare the actual MariaDB version.
			 */
			$this->mysql_version = preg_replace(
				'/^5\.5\.5-/',
				'',
				(string) $mysql_version
			);
		}
		return $this->mysql_version;
	}

	private function is_mysql_requirement_met() {
		return version_compare( $this->get_actual_mysql_version(), $this->get_required_mysql_version(), '>=' );
	}

	private function get_required_mysql_version() {
		return self::$required_mysql_version;
	}

}