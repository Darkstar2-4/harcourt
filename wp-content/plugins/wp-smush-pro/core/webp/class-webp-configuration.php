<?php

namespace Smush\Core\Webp;

use Smush\Core\Settings;
use Smush\Core\Next_Gen\Next_Gen_Configuration_Interface;

class Webp_Configuration implements Next_Gen_Configuration_Interface {
	private static $format_key = 'webp';
	private static $direct_conversion_method_key = 'direct_conversion';
	private static $hide_wizard_option_key = 'wp-smush-webp_hide_wizard';

	/**
	 * @var self
	 */
	private static $instance;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Webp_Server_Configuration
	 */
	private $server_configuration;

	/**
	 * @var Webp_Direct_Conversion
	 */
	private $direct_conversion;

	/**
	 * @var Webp_Helper
	 */
	private $helper;

	public function __construct() {
		$this->server_configuration = new Webp_Server_Configuration();
		$this->direct_conversion    = new Webp_Direct_Conversion();
		$this->settings             = Settings::get_instance();
		$this->helper               = new Webp_Helper();
	}

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function get_format_name() {
		return __( 'WebP', 'wp-smushit' );
	}

	public static function get_format_key() {
		return self::$format_key;
	}

	public function is_activated() {
		return $this->settings->is_webp_module_active();
	}

	public function is_fallback_activated() {
		return $this->settings->is_webp_fallback_active();
	}

	public function is_configured() {
		if ( ! $this->is_activated() ) {
			return false;
		}

		return $this->direct_conversion_enabled() || $this->is_server_configured();
	}

	public function direct_conversion_enabled() {
		return $this->direct_conversion->is_enabled();
	}

	public function is_server_configured() {
		return $this->server_configuration->is_configured();
	}

	public function switch_method( $webp_method ) {
		return $this->set_next_gen_method( $webp_method );
	}

	public function set_next_gen_method( $webp_method ) {
		$enable_direct_conversion = self::$direct_conversion_method_key === $webp_method;
		if ( $enable_direct_conversion === $this->direct_conversion_enabled() ) {
			return;
		}

		if ( $enable_direct_conversion ) {
			$this->server_configuration->disable();
			$this->direct_conversion->enable();
		} else {
			$this->direct_conversion->disable();
			$this->server_configuration->enable();
			if ( ! $this->should_show_wizard() ) {
				$this->show_wizard();
			}
		}

		do_action( 'wp_smush_webp_method_changed' );
	}

	public function set_next_gen_fallback( $fallback_activated ) {
		$this->settings->set( 'webp_fallback', $fallback_activated );
	}

	public function toggle_module( $enable_webp ) {
		if ( $enable_webp ) {
			$this->activate();
		} else {
			$this->deactivate();
		}

		do_action( 'wp_smush_webp_status_changed' );
	}

	private function activate() {
		$this->settings->set( 'webp_mod', true );

		if ( $this->direct_conversion_enabled() ) {
			return;
		}

		// Try server configuation method first since it has proven to be faster.
		$this->server_configuration()->enable();

		if ( ! $this->is_configured() ) {
			$this->direct_conversion->enable();
			$this->server_configuration()->disable();
		}
	}

	private function deactivate() {
		$this->settings->set( 'webp_mod', false );
		// Required to add lock file for sever configuration.
		$this->server_configuration->disable();
	}

	public function server_configuration() {
		return $this->server_configuration;
	}

	public function support_server_configuration() {
		return true;
	}

	public function toggle_wizard() {
		$is_hidden = get_site_option( self::$hide_wizard_option_key );
		update_site_option( self::$hide_wizard_option_key, ! $is_hidden );
	}

	public function show_wizard() {
		update_site_option( self::$hide_wizard_option_key, false );
	}

	public function hide_wizard() {
		update_site_option( self::$hide_wizard_option_key, true );
	}

	public function should_show_wizard() {
		if ( ! $this->is_activated() || $this->is_configured() ) {
			return false;
		}

		return true;

		// $hide_wizard = get_site_option( self::$hide_wizard_option_key );
		// return ! $hide_wizard;
	}

	public function delete_all_next_gen_files() {
		$this->helper->delete_all_webp_files();
	}

	/**
	 * Get direct_conversion_method.
	 *
	 * @return string
	 */
	public static function get_direct_conversion_method_key() {
		return self::$direct_conversion_method_key;
	}

}