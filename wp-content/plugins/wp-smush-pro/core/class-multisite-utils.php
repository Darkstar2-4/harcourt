<?php

namespace Smush\Core;

/**
 * Class Multisite_Utils
 *
 * @package Smush\Core
 */
class Multisite_Utils {
	/**
	 * Checks if the current user can manage the network.
	 *
	 * @return bool
	 */
	public static function can_manage_network() {
		return Helper::is_user_allowed( 'manage_network' );
	}
}