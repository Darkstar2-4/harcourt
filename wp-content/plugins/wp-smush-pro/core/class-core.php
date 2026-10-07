<?php
/**
 * Core class: Core class.
 *
 * @since 2.9.0
 * @package Smush\Core
 */

namespace Smush\Core;

use WP_Smush;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Core
 */
class Core extends Stats {

	/**
	 * Animated status.
	 *
	 * @var int
	 */
	private static $status_animated = 2;

	/**
	 * Modules array.
	 *
	 * @var Modules
	 */
	public $mod;

	/**
	 * Allowed mime types of image.
	 *
	 * @var array $mime_types
	 */
	public static $mime_types = array(
		'image/jpg',
		'image/jpeg',
		'image/x-citrix-jpeg',
		'image/gif',
		'image/png',
		'image/x-png',
	);

	/**
	 * List of external pages where smush needs to be loaded.
	 *
	 * @var array $pages
	 */
	public static $external_pages = array(
		'nggallery-manage-images',
		'gallery_page_nggallery-manage-gallery',
		'gallery_page_wp-smush-nextgen-bulk',
		'nextgen-gallery_page_nggallery-manage-gallery', // Different since NextGen 3.3.6.
		'nextgen-gallery_page_wp-smush-nextgen-bulk', // Different since NextGen 3.3.6.
		'post',
		'post-new',
		'page',
		'edit-page',
		'upload',
	);

	/**
	 * Attachment IDs which are smushed.
	 *
	 * @var array $smushed_attachments
	 */
	public $smushed_attachments = array();

	/**
	 * Unsmushed image IDs.
	 *
	 * @var array $unsmushed_attachments
	 */
	public $unsmushed_attachments = array();

	/**
	 * Skipped attachment IDs.
	 *
	 * @since 3.0
	 *
	 * @var array $skipped_attachments
	 */
	public $skipped_attachments = array();

	/**
	 * Smushed attachments out of total attachments.
	 *
	 * @var int $smushed_count
	 */
	public $smushed_count = 0;

	/**
	 * Smushed attachments out of total attachments.
	 *
	 * @var int $remaining_count
	 */
	public $remaining_count = 0;

	/**
	 * Images with errors that have been skipped from bulk smushing.
	 *
	 * @since 3.0
	 * @var int $skipped_count
	 */
	public $skipped_count = 0;

	/**
	 * Super Smushed attachments count.
	 *
	 * @var int $super_smushed
	 */
	public $super_smushed = 0;

	/**
	 * Total count of attachments for smushing.
	 *
	 * @var int $total_count
	 */
	public $total_count = 0;

	/**
	 * Initialize modules.
	 *
	 * @since 2.9.0
	 */
	protected function init() {
		$this->mod = Modules::get_instance();

		// Enqueue scripts and initialize variables.
		add_action( 'admin_init', array( $this, 'init_settings' ) );

		// Load integrations.
		add_action( 'init', array( $this, 'load_integrations' ) );

		// Big image size threshold (WordPress 5.3+).
		add_filter( 'big_image_size_threshold', array( $this, 'big_image_size_threshold' ), 10 );

		/**
		 * Load NextGen Gallery, instantiate the Async class. if hooked too late or early, auto Smush doesn't
		 * work, also load after settings have been saved on init action.
		 */
		add_action( 'plugins_loaded', array( $this, 'load_libs' ), 90 );

		/**
		 * Maybe need to load some modules in REST API mode.
		 * E.g. S3.
		 */
		add_action( 'rest_api_init', array( $this, 'load_libs_for_rest_api' ), 99 );
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.0' );
	}

	/**
	 * Load integrations class.
	 *
	 * @since 2.8.0
	 */
	public function load_integrations() {
		new Integrations\Common();
	}

	/**
	 * Load plugin modules.
	 */
	public function load_libs() {
		$this->wp_smush_async();


		new Integrations\Gutenberg();
		new Integrations\Composer();
		new Integrations\Gravity_Forms();
		$avada = new Integrations\Avada();
		$avada->init();
		$divi = new Integrations\Divi();
		$divi->init();
		$envira = new Integrations\Envira();
		$envira->init();
		$hummingbird = new Integrations\Hummingbird_Integration();
		$hummingbird->init();

		$woo = new Integrations\WooCommerce();
		$woo->init();

		$amp = new Integrations\AMP_Integration();
		$amp->init();

		$essential_grid = new Integrations\Essential_Grid_Integration();
		$essential_grid->init();

		$elementor = new Integrations\Elementor_Integration();
		$elementor->init();

		$wp_rocket_integration = new Integrations\WP_Rocket_Integration();
		$wp_rocket_integration->init();

		$w3tc_integration = new Integrations\W3_Total_Cache_Integration();
		$w3tc_integration->init();

		$litespeed_integration = new Integrations\Litespeed_Cache_Integration();
		$litespeed_integration->init();

		$wp_fastest_cache_integration = new Integrations\WP_Fastest_Cache_Integration();
		$wp_fastest_cache_integration->init();

		$wp_optimize_integration = new Integrations\WP_Optimize_Integration();
		$wp_optimize_integration->init();

		$wp_super_cache_integration = new Integrations\WP_Super_Cache_Integration();
		$wp_super_cache_integration->init();

		$oxygen_builder = new Integrations\Oxygen_Builder_Integration();
		$oxygen_builder->init();

		// Register logger to schedule cronjob.
		Helper::logger();
	}

	/**
	 * Load lib for REST API.
	 */
	public function load_libs_for_rest_api() {
	}

	/**
	 * Initialize the Smush Async class.
	 */
	private function wp_smush_async() {
		// Check if Async is disabled.
		if ( defined( 'WP_SMUSH_ASYNC' ) && ! WP_SMUSH_ASYNC ) {
			return;
		}

		// Instantiate class.
		new Modules\Async\Async();

		// Load the Editor Async task only if user logged in or in backend.
		if ( is_admin() && is_user_logged_in() ) {
			new Modules\Async\Editor();
		}
	}

	/**
	 * Init settings.
	 */
	public function init_settings() {
		// Initialize Image dimensions.
		$this->mod->smush->image_sizes = $this->image_dimensions();
	}

	public function get_localize_strings() {
		$upgrade_url = add_query_arg(
			array(
				'utm_source'   => 'smush',
				'utm_medium'   => 'plugin',
				'utm_campaign' => 'smush_bulksmush_inline_filesizelimit',
			),
			'https://wpmudev.com/project/wp-smush-pro/'
		);

		$wp_smush_msgs = array(
			'nonce'                  => wp_create_nonce( 'wp-smush-ajax' ),
			'webp_nonce'             => wp_create_nonce( 'wp-smush-webp-nonce' ),
			'resmush'                => esc_html__( 'Super-Smush', 'wp-smushit' ),
			'error_in_bulk'          => esc_html__( '{{smushed}}/{{total}} images optimized successfully, {{errors}} images were not optimized, find out why and how to resolve the issue(s) below.', 'wp-smushit' ),
			'all_failed'             => esc_html__( 'All of your images failed to optimize. Find out why and how to resolve the issue(s) below.', 'wp-smushit' ),
			'all_resmushed'          => esc_html__( 'All images are fully optimized.', 'wp-smushit' ),
			'all_smushed'            => esc_html__( 'All attachments have been optimized. Awesome!', 'wp-smushit' ),
			'restore'                => esc_html__( 'Restoring image...', 'wp-smushit' ),
			'smushing'               => esc_html__( 'Smushing...', 'wp-smushit' ),
			'btn_ignore'             => esc_html__( 'Ignore', 'wp-smushit' ),
			'view_detail'            => esc_html__( 'View Details', 'wp-smushit' ),
			'failed_item_smushed'    => esc_html__( 'Images optimized successfully. No further action required.', 'wp-smushit' ),
			// Used by Directory Smush.
			'generic_ajax_error'     => esc_html__( 'Something went wrong with the request. Please reload the page and try again.', 'wp-smushit' ),
			// Errors.
			'error_ignore'           => esc_html__( 'Ignore this image from bulk smushing', 'wp-smushit' ),
			// Ignore text.
			'ignored'                => esc_html__( 'Ignored', 'wp-smushit' ),
			'not_processed'          => esc_html__( 'Not processed', 'wp-smushit' ),
			// Notices.
			'noticeDismiss'          => esc_html__( 'Dismiss', 'wp-smushit' ),
			'noticeDismissTooltip'   => esc_html__( 'Dismiss notice', 'wp-smushit' ),
			// URLs.
			'smush_url'              => network_admin_url( 'admin.php?page=smush' ),
			'bulk_smush_url'         => Helper::get_page_url( 'smush-bulk' ),
			'nextGenURL'             => network_admin_url( 'admin.php?page=smush-next-gen' ),
			'edit_link'              => Helper::get_image_media_link( '{{id}}', null, true ),
			'debug_mode'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'cancel'                 => esc_html__( 'Cancel', 'wp-smushit' ),
			'cancelling'             => esc_html__( 'Cancelling ...', 'wp-smushit' ),
		);

		return apply_filters( 'wp_smush_localize_script_messages', $wp_smush_msgs );
	}

	/**
	 * Get registered image sizes with dimension
	 *
	 * @return array
	 */
	public function image_dimensions() {
		return Helper::get_image_sizes();
	}

	/**
	 * Get the Maximum Width and Height settings for WrodPress
	 *
	 * @return array, Array of Max. Width and Height for image.
	 */
	public function get_max_image_dimensions() {
		global $_wp_additional_image_sizes;

		$width  = 0;
		$height = 0;
		$limit  = 9999; // Post-thumbnail.

		$image_sizes = get_intermediate_image_sizes();

		// If image sizes are filtered and no image size list is returned.
		if ( empty( $image_sizes ) ) {
			return array(
				'width'  => $width,
				'height' => $height,
			);
		}

		// Create the full array with sizes and crop info.
		foreach ( $image_sizes as $size ) {
			if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
				$size_width  = get_option( "{$size}_size_w" );
				$size_height = get_option( "{$size}_size_h" );
			} elseif ( isset( $_wp_additional_image_sizes[ $size ] ) ) {
				$size_width  = $_wp_additional_image_sizes[ $size ]['width'];
				$size_height = $_wp_additional_image_sizes[ $size ]['height'];
			}

			// Skip if no width and height.
			if ( ! isset( $size_width, $size_height ) ) {
				continue;
			}

			// If within te limit, check for a max value.
			if ( $size_width <= $limit ) {
				$width = max( $width, $size_width );
			}

			if ( $size_height <= $limit ) {
				$height = max( $height, $size_height );
			}
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Set the big image threshold.
	 *
	 * @param int $threshold The threshold value in pixels. Default 2560.
	 *
	 * @return int|bool  New threshold. False if scaling is disabled.
	 * @since 3.3.2
	 */
	public function big_image_size_threshold( $threshold ) {
		if ( Settings::get_instance()->get( 'no_scale' ) ) {
			return false;
		}

		if ( ! Settings::get_instance()->is_resize_module_active() ) {
			return $threshold;
		}

		$resize_sizes = Settings::get_instance()->get_setting( 'wp-smush-resize_sizes' );
		if ( ! $resize_sizes || ! is_array( $resize_sizes ) ) {
			return $threshold;
		}

		return $resize_sizes['width'] > $resize_sizes['height'] ? $resize_sizes['width'] : $resize_sizes['height'];
	}


	/**
	 * Get status_animated.
	 *
	 * @return int
	 */
	public static function get_status_animated() {
		return self::$status_animated;
	}
}