<?php
/**
 * Smush core class: Smush class
 *
 * @package Smush\Core\Modules
 */

namespace Smush\Core\Modules;

use Smush\Core\Api\Backoff;
use Smush\Core\Smush\Smush_Request_WP_Multiple;
use Smush\Core\Smush\Smusher_Options_Provider;
use WP_Error;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Smush
 */
class Smush extends Abstract_Module {
	private static $error_ssl_cert = 'ssl_cert_error';

	/**
	 * Meta key to save smush result to db.
	 *
	 * @var string $smushed_meta_key
	 */
	public static $smushed_meta_key = 'wp-smpro-smush-data';

	/**
	 * Images dimensions array.
	 *
	 * @var array $image_sizes
	 */
	public $image_sizes = array();

	/**
	 * Stores the headers returned by the latest API call.
	 *
	 * @var array $api_headers
	 */
	protected $api_headers = array();

	/**
	 * Prevent third party try to run another smush while it's running.
	 *
	 * @access private
	 *
	 * @var bool
	 */
	private $prevent_infinite_loop;

	/**
	 * @var Smush_Request_WP_Multiple
	 */
	private $request_multiple;
	/**
	 * @var Backoff
	 */
	private $backoff;

	/**
	 * WP_Smush constructor.
	 */
	public function init() {
		// Update the Super Smush count, after the Smush'ing.
		//add_action( 'wp_smush_image_optimised', array( $this, 'update_lists' ), '', 2 );

		// Delete backup files.
		//add_action( 'delete_attachment', array( $this, 'delete_images' ), 12 );

		// Make sure we treat scaled images as additional size.
		//add_filter( 'wp_smush_add_scaled_images_to_meta', array( $this, 'add_scaled_to_meta' ), 10, 2 );

		$smusher_options        = ( new Smusher_Options_Provider() )->get_options();
		$this->request_multiple = new Smush_Request_WP_Multiple( $smusher_options );
		$this->backoff          = new Backoff();
	}

	/**
	 * Check whether to show warning or not for Pro users, if they don't have a valid install
	 *
	 * @return bool
	 */
	public function show_warning() {
		return false;
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.1.0' );
	}
}