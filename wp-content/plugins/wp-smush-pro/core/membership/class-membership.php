<?php

namespace Smush\Core\Membership;

use Smush\Core\Hub_Connector;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Settings;
use Smush\Core\Smush\Smush_Optimization;
use WPMUDEV\Hub\Connector\Data;

class Membership {
	/**
	 * Static instance
	 *
	 * @var self
	 */
	private static $instance;

	protected function __construct() {
		$this->is_pro = false;
	}

	/**
	 * Static instance getter
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new Membership_Pro();
		}

		return self::$instance;
	}

	/**
	 * @var boolean
	 */
	protected $is_pro;

	public function get_apikey() {
		return false;
	}

	/**
	 * Validate the installation.
	 *
	 * @param bool $force Force revalidation.
	 *
	 * @return void
	 */
	public function validate_install( $force = false ) {
		$this->is_pro = false;
	}

	/**
	 * Check if the membership is pro.
	 *
	 * @return bool
	 */
	public function is_pro() {
		return $this->is_pro;
	}

	public function set_pro( $is_pro ) {
		$this->is_pro = $is_pro;
	}

	public function should_show_premium_status_warning( $attachment_id ) {
		return false;
	}

	/**
	 * Check if the user has access to the hub.
	 *
	 * Warning: This method do not support old free users.
	 *
	 * @return bool
	 */
	public function has_access_to_hub() {
		if ( Hub_Connector::is_wpmudev_dashboard_connected() && method_exists( \WPMUDEV_Dashboard::$api, 'get_membership_status' ) ) {
			// Possible values: full, single, free, expired, paused, unit.
			$plan = \WPMUDEV_Dashboard::$api->get_membership_status();
		} elseif ( Hub_Connector::has_access() && class_exists( '\WPMUDEV\Hub\Connector\Data' ) ) {
			$plan = Data::get()->membership_type();
		} else {
			return false;
		}

		return in_array( $plan, array( 'full', 'single', 'free', 'unit' ), true );
	}

	/**
	 * Check if access to the Hub access is required to use the API.
	 *
	 * @return bool
	 */
	public function is_api_hub_access_required() {
		if ( $this->can_use_current_compression_level() ) {
			return false;
		}

		return ! $this->has_access_to_hub();
	}

	/**
	 * Check if the user can use super compression.
	 *
	 * @return bool
	 */
	public function can_use_current_compression_level() {
		$current_lossy_level = Settings::get_instance()->get_lossy_level_setting();
		return Settings::get_level_lossless() === $current_lossy_level;
	}

	/**
	 * Get the value for guests.
	 *
	 * @param bool $value
	 * @param mixed $alt
	 */
	public function get_guest_value( $value = true, $alt = null ) {
		return $this->get_member_value( $alt, $value );
	}

	/**
	 * Get the value for members.
	 *
	 * @param bool $value
	 * @param mixed $alt
	 */
	public function get_member_value( $value = true, $alt = null ) {
		return $alt;
	}
}