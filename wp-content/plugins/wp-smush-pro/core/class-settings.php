<?php
/**
 * Smush Settings class: Settings
 *
 * @since 3.0  Migrated from old settings class.
 * @package Smush\Core
 */

namespace Smush\Core;

use Smush\Core\CDN\CDN_Helper;
use Smush\Core\LCP\LCP_Helper;
use Smush\Core\Membership\Membership;
use Smush\Core\Next_Gen\Next_Gen_Manager;
use Smush\Core\Stats\Global_Stats;
use WP_Smush;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Settings
 *
 * @since 3.0
 */
class Settings {

	private static $subsite_controls_option_id = 'wp-smush-networkwide';
	private static $lazy_preload_module_name = 'lazy_load';
	protected static $settings_option_id = 'wp-smush-settings';
	private static $next_gen_cdn_key = 'webp';
	private static $level_lossless = 0;
	protected static $level_super_lossy = 1;
	protected static $level_ultra_lossy = 2;
	private static $none_cdn_mode = 0;
	private static $webp_cdn_mode = 1;
	private static $avif_cdn_mode = 2;
	protected static $dir_settings_option_id = 'wp-smush-dir-settings';

	/**
	 * Plugin instance.
	 *
	 * @since 3.0
	 *
	 * @var null|Settings
	 */
	private static $instance = null;

	/**
	 * Settings array.
	 *
	 * @since 3.2.2
	 * @var array $settings
	 */
	private $settings = array();

	/**
	 * Default settings array.
	 *
	 * We don't want it to be edited directly, so we use public get_*, set_* and delete_* methods.
	 *
	 * @since 3.0    Improved structure.
	 * @since 3.2.2  Changed to be a default array.
	 * @since 3.8.0  Added webp_mod.
	 *
	 * @var array
	 */
	public function get_defaults() {
		return array(
			'auto'                   => true,    // works with CDN.
			'lossy'                  => 0,   // works with CDN.
			'strip_exif'             => true,    // works with CDN.
			'resize'                 => false,
			'original'               => true,
			'backup'                 => true,
			'no_scale'               => false,
			'png_to_jpg'             => false,   // works with CDN.
			'nextgen'                => false,
			's3'                     => false,
			'gutenberg'              => false,
			'js_builder'             => false,
			'gform'                  => false,
			'cdn'                    => false,
			'auto_resizing'          => false,
			'cdn_dynamic_sizes'      => false,
			self::$next_gen_cdn_key  => self::$webp_cdn_mode,
			'usage'                  => false,
			'accessible_colors'      => false,
			'keep_data'              => true,
			'lazy_load'              => false,
			'background_images'      => true,
			'rest_api_support'       => false,   // CDN option.
			'webp_mod'               => false,   // WebP module.
			'background_email'       => true,
			'webp_direct_conversion' => false,
			'webp_fallback'          => false,
			'disable_streams'        => false,
			'avif_mod'               => false,
			'avif_fallback'          => false,
			'image_dimensions'       => false,
			'preload_images'         => false,
		);
	}

	/**
	 * Available modules.
	 *
	 * @since 3.2.2
	 * @since 3.8.0  Added webp.
	 * @var array $modules
	 */
	private function get_modules() {
		return array( 'bulk', 'integrations', self::$lazy_preload_module_name, 'cdn', 'next_gen', 'settings' );
	}

	/**
	 * List of features/settings that are free.
	 *
	 * @var array $basic_features
	 */
	public static $basic_features = array( 'bulk', 'auto', 'strip_exif', 'resize', 'original', 'directory_smush', 'gutenberg', 'js_builder', 'gform', 'lazy_load', 'lossy', 'png_to_jpg' );

	/**
	 * List of fields in bulk smush form.
	 *
	 * @used-by save_settings()
	 *
	 * @var array
	 */
	private $bulk_fields = array( 'lossy', 'bulk', 'auto', 'strip_exif', 'resize', 'original', 'backup', 'png_to_jpg', 'no_scale', 'background_email' );

	/**
	 * @since 3.12.6
	 *
	 * Upsell fields.
	 */
	private $upsell_fields = array( 'background_email' );

	/**
	 * List of fields in integration form.
	 *
	 * @used-by save_settings()
	 *
	 * @var array
	 */
	private $integrations_fields = array( 'gutenberg', 'gform', 'js_builder', 's3', 'nextgen' );

	/**
	 * List of fields in CDN form.
	 *
	 * @used-by save_settings()
	 *
	 * @var array
	 */
	public function get_cdn_fields() {
		return array( 'cdn', 'background_images', 'cdn_dynamic_sizes', self::$next_gen_cdn_key, 'rest_api_support' );
	}

	/**
	 * List of fields in CDN form.
	 *
	 * @used-by save_settings()
	 *
	 * @since 3.8.0
	 *
	 * @var array
	 */
	private $webp_fields = array( 'webp_mod', 'webp_direct_conversion', 'webp_fallback' );

	/**
	 * @var array
	 */
	private $avif_fields = array( 'avif_mod', 'avif_fallback' );

	/**
	 * List of fields in Settings form.
	 *
	 * @used-by save_settings()
	 *
	 * @var array
	 */
	private $settings_fields = array( 'accessible_colors', 'usage', 'keep_data', 'api_auth', 'disable_streams' );

	/**
	 * List of fields in lazy loading form.
	 *
	 * @used-by save_settings()
	 *
	 * @var array
	 */
	private $lazy_load_fields = array( 'lazy_load', 'auto_resizing', 'image_dimensions' );

	/**
	 * @var array
	 */
	private $preload_fields = array( 'preload_images' );

	/**
	 * @var array
	 */
	private $activated_subsite_modules;

	/**
	 * @var bool
	 */
	private $is_switching_subsite = false;

	/**
	 * Return the plugin instance.
	 *
	 * @since 3.0
	 *
	 * @return Settings
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			$pro_file = __DIR__ . '/class-settings-pro.php';
			if ( ! class_exists( '\\Smush\\Core\\Settings_Pro' ) && file_exists( $pro_file ) ) {
				require_once $pro_file;
			}
			if ( class_exists( '\\Smush\\Core\\Settings_Pro' ) ) {
			self::$instance = new Settings_Pro();
			} else {
				self::$instance = new self();
		}
		}
		return self::$instance;
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '3.24.0' );
	}

	/**
	 * WP_Smush_Settings constructor.
	 *
	 * WARNING: Any new class added to this constructor must be loaded before use.
	 * This constructor is called when the plugin is activated.
	 */
	protected function __construct() {
		// Handle settings cache and subsite switching when switching between sites in a multisite network.
		add_action( 'switch_blog', array( $this, 'maybe_reset_cache_site_settings' ), 10, 2 );
		add_action( 'switch_blog', array( $this, 'toggle_switching_subsite' ) );

		// Do not initialize if not in admin area
		// wp_head runs specifically in the frontend, good check to make sure we're accidentally not loading settings on required pages.
		if ( ! is_admin() && ! wp_doing_ajax() && did_action( 'wp_head' ) ) {
			return;
		}

		// Reset Settings.
		add_action( 'wp_ajax_reset_settings', array( $this, 'reset' ) );

		add_filter( 'wp_smush_settings', array( $this, 'remove_unavailable' ) );

		$this->init();
	}

	public function toggle_switching_subsite() {
		$this->is_switching_subsite = ! $this->is_switching_subsite;
	}

	/**
	 * Remove settings that are not available on a specific version of WordPress.
	 *
	 * @since 3.9.1
	 *
	 * @param array $settings Current settings.
	 *
	 * @return array
	 */
	public function remove_unavailable( $settings ) {
		global $wp_version;

		if ( version_compare( $wp_version, '5.3', '<' ) ) {
			if ( isset( $this->bulk_fields['no_scale'] ) ) {
				unset( $this->bulk_fields['no_scale'] );
			}

			if ( isset( $settings['no_scale'] ) ) {
				unset( $settings['no_scale'] );
			}
		}

		return $settings;
	}

	/**
	 * Get descriptions for all settings.
	 *
	 * @since 3.8.6 Moved from Core
	 *
	 * @param string $id Setting ID to get data for.
	 * @param string $type What value to get. Accepts: label, short_label or desc.
	 *
	 * @return string
	 */
	public static function get_setting_data( $id, $type = '' ) {
		$s3_plugin_url  = esc_url( 'https://wordpress.org/plugins/amazon-s3-and-cloudfront/' );
		$mail_recipient = get_option( 'admin_email' );
		$bg_email_desc  = sprintf(
			/* translators: %s Email address */
				esc_html__( "Be notified via email about the bulk smush status when the process has completed. You'll receive an email at %s.", 'wp-smushit' ),
			'<strong>' . $mail_recipient . '</strong>'
			);
		$settings = array(
			'background_email'  => array(
				'label'       => esc_html__( 'Enable email notification', 'wp-smushit' ),
				'short_label' => esc_html__( 'Email Notification', 'wp-smushit' ),
				'desc'        => $bg_email_desc,
			),
			'bulk'              => array(
				'short_label' => esc_html__( 'Image Sizes', 'wp-smushit' ),
				'desc'        => esc_html__( 'WordPress creates multiple thumbnails for each uploaded image. Select which sizes to include in bulk smushing.', 'wp-smushit' ),
			),
			'auto'              => array(
				'label'       => esc_html__( 'Automatically compress my images on upload', 'wp-smushit' ),
				'short_label' => esc_html__( 'Automatic compression', 'wp-smushit' ),
				'desc'        => esc_html__( 'When you upload images to your site, we will automatically optimize and compress them for you.', 'wp-smushit' ),
			),
			'lossy'             => array(
				'label'       => esc_html__( 'Choose Compression Level', 'wp-smushit' ),
				'short_label' => esc_html__( 'Smush Mode', 'wp-smushit' ),
				'desc'        => sprintf(
				/* translators: 1: Opening <strong> 2: Closing </strong> */
					esc_html__( 'Choose the level of compression that suits your needs. We recommend %1$sUltra%2$s for faster sites and impressive image quality.', 'wp-smushit' ),
					'<strong>',
					'</strong>'
				),
			),
			'strip_exif'        => array(
				'label'       => esc_html__( 'Remove image metadata', 'wp-smushit' ),
				'short_label' => esc_html__( 'Metadata', 'wp-smushit' ),
				'desc'        => esc_html__( 'Photos can include camera settings, date or location. Removing this EXIF data reduces the file size.', 'wp-smushit' ),
			),
			'resize'            => array(
				'label'       => esc_html__( 'Resize large images', 'wp-smushit' ),
				'short_label' => esc_html__( 'Large Image Resizing', 'wp-smushit' ),
				'desc'        => esc_html__( 'WordPress scales large images (over 2560px) and keeps the originals as a backup. You can adjust the size limit or turn scaling off entirely.', 'wp-smushit' ),
			),
			'no_scale'          => array(
				'label'       => esc_html__( 'Disable scaled images', 'wp-smushit' ),
				'short_label' => esc_html__( 'Disable Scaled Images', 'wp-smushit' ),
				'desc'        => esc_html__( 'When enabled, WordPress won’t create scaled versions of large images; only your original upload is kept.', 'wp-smushit' ),
			),
			'original'          => array(
				'label'       => esc_html__( 'Optimize original images', 'wp-smushit' ),
				'short_label' => esc_html__( 'Original Images', 'wp-smushit' ),
				'desc'        => esc_html__( 'Control how Smush processes your original image files when running bulk smush.', 'wp-smushit' ),
			),
			'backup'            => array(
				'label'       => esc_html__( 'Backup original images', 'wp-smushit' ),
				'short_label' => esc_html__( 'Backup Original Images', 'wp-smushit' ),
				'desc'        => esc_html__( 'Keep a backup of your original images so you can restore them anytime. Be aware this may increase the size of your uploads folder.', 'wp-smushit' ),
			),
			'png_to_jpg'        => array(
				'label'       => esc_html__( 'Auto-convert PNGs to JPEGs (lossy)', 'wp-smushit' ),
				'short_label' => esc_html__( 'PNG to JPEG Conversion', 'wp-smushit' ),
				'desc'        => esc_html__( 'When you compress a PNG, Smush will check if converting it to JPEG could further reduce its size.', 'wp-smushit' ),
			),
			'accessible_colors' => array(
				'label'       => esc_html__( 'Enable high contrast mode', 'wp-smushit' ),
				'short_label' => esc_html__( 'Color Accessibility', 'wp-smushit' ),
				'desc'        => esc_html__( 'Increase the visibility and accessibility of elements and components to meet WCAG AAA requirements.', 'wp-smushit' ),
			),
			'usage'             => array(
				'label'       => esc_html__( 'Allow usage tracking', 'wp-smushit' ),
				'short_label' => esc_html__( 'Usage Tracking', 'wp-smushit' ),
				'desc'        => esc_html__( 'Help make Smush better by letting our designers learn how you’re using the plugin.', 'wp-smushit' ),
			),
			'image_dimensions'  => array(
				'label'       => esc_html__( 'Automatically add missing image dimensions', 'wp-smushit' ),
				'short_label' => esc_html__( 'Add Missing Image Dimensions', 'wp-smushit' ),
				'desc'        => esc_html__( 'Automatically add width and height attributes to images missing dimensions for better layout stability and performance.', 'wp-smushit' ),
			),
			'nextgen'           => array(
				'label'       => esc_html__( 'Enable NextGen Gallery integration', 'wp-smushit' ),
				'short_label' => esc_html__( 'NextGen Gallery', 'wp-smushit' ),
				'desc'        => esc_html__( 'Allow smushing images directly through NextGen Gallery settings.', 'wp-smushit' ),
			),
			's3'                => array(
				'label'       => __( 'Enable Amazon S3 support', 'wp-smushit' ),
				'short_label' => __( 'Amazon S3', 'wp-smushit' ),
				'desc'        => sprintf( /* translators: %1$s - <a>, %2$s - </a> */
					esc_html__(
						"Storing your image on S3 buckets using %1\$sWP Offload Media%2\$s? Smush can detect and smush those assets for you, including when you're removing files from your host server.",
						'wp-smushit'
					),
					"<a href='$s3_plugin_url' target = '_blank'>",
					'</a>'
				),
			),
			'gform' => array(
				'label'       => esc_html__( 'Enable Gravity Forms integration', 'wp-smushit' ),
				'short_label' => esc_html__( 'Gravity Forms', 'wp-smushit' ),
				'desc'        => esc_html__( 'Allow compressing images uploaded with Gravity Forms.', 'wp-smushit' ),
			),
			'js_builder' => array(
				'label'       => esc_html__( 'Enable WPBakery Page Builder integration', 'wp-smushit' ),
				'short_label' => esc_html__( 'WPBakery Page Builder', 'wp-smushit' ),
				'desc'        => esc_html__( 'Allow smushing images resized in WPBakery Page Builder editor.', 'wp-smushit' ),
			),
			'gutenberg' => array(
				'label'       => esc_html__( 'Show Smush stats in Gutenberg blocks', 'wp-smushit' ),
				'short_label' => esc_html__( 'Gutenberg Support', 'wp-smushit' ),
				'desc'        => esc_html__(
					'Add statistics and the manual smush button to Gutenberg blocks that display images.',
					'wp-smushit'
				),
			),
		);

		$settings = apply_filters( 'wp_smush_settings', $settings );

		if ( ! isset( $settings[ $id ] ) ) {
			return '';
		}

		if ( 'short-label' === $type ) {
			return ! empty( $settings[ $id ]['short_label'] ) ? $settings[ $id ]['short_label'] : $settings[ $id ]['label'];
		}

		if ( 'label' === $type ) {
			return ! empty( $settings[ $id ]['label'] ) ? $settings[ $id ]['label'] : $settings[ $id ]['short_label'];
		}

		if ( 'desc' === $type ) {
			return $settings[ $id ]['desc'];
		}

		return $settings[ $id ];
	}

	/**
	 * Getter method for bulk settings fields.
	 *
	 * @since 3.2.2
	 * @return array
	 */
	public function get_bulk_fields() {
		return $this->bulk_fields;
	}

	/**
	 * Getter method for integration fields.
	 *
	 * @since 3.2.2
	 * @return array
	 */
	public function get_integrations_fields() {
		return $this->integrations_fields;
	}

	public function is_upsell_field( $field ) {
		return in_array( $field, $this->upsell_fields, true );
	}

	public function is_pro_field( $field ) {
		return ! in_array( $field, self::$basic_features, true );
	}

	public function can_access_pro_field( $field ) {
		return false;
	}

	public function should_enforce_bulk_limit() {
		return true;
	}

	public function get_api_key() {
		return '';
	}

	/**
	 * Getter method for settings fields.
	 *
	 * @since 3.2.2
	 * @return array
	 */
	public function get_settings_fields() {
		return $this->settings_fields;
	}

	/**
	 * Getter method for lazy loading fields.
	 *
	 * @since 3.3.0
	 * @return array
	 */
	public function get_lazy_load_fields() {
		return $this->lazy_load_fields;
	}

	public function get_preload_fields() {
		return $this->preload_fields;
	}

	public function get_webp_fields() {
		return $this->webp_fields;
	}

	public function get_avif_fields() {
		return $this->avif_fields;
	}

	public function get_next_gen_fields() {
		return array_merge( $this->get_webp_fields(), $this->get_avif_fields() );
	}

	/**
	 * Init settings.
	 *
	 * If there are no settings in the database, populate it with the defaults, if settings are present
	 */
	public function init() {
	}

	/**
	 * Checks whether the settings are applicable for the whole network/site or sitewise (multisite).
	 */
	public function is_network_enabled() {
		return $this->is_network_setting( self::$settings_option_id );
	}

	public function is_network_setting( $option_id ) {
		if ( ! is_multisite() ) {
			return false;
		}

		$global_setting_keys = array(
			'wp_smush_api_auth',
			self::$subsite_controls_option_id,
		);

		if ( in_array( $option_id, $global_setting_keys, true ) ) {
			return true;
		}

		$subsite_modules = $this->get_activated_subsite_modules();
		if ( empty( $subsite_modules ) ) {
			return true;
		}

		$module_option_keys = array(
			'wp-smush-image_sizes'  => 'bulk',
			'wp-smush-resize_sizes' => 'bulk',
			'wp-smush-lazy_load'    => self::$lazy_preload_module_name,
			'wp-smush-preload'      => self::$lazy_preload_module_name,
			'wp-smush-cdn_status'   => 'cdn',
		);

		if ( ! isset( $module_option_keys[ $option_id ] ) ) {
			if ( $this->is_switching_subsite ) {
				return false;
			}

			return self::is_ajax_network_admin() || is_network_admin();
		}

		$module = $module_option_keys[ $option_id ];

		return ! in_array( $module, $subsite_modules, true );
	}

	/**
	 * Check if user is able to access the page.
	 *
	 * @since 3.2.2
	 *
	 * @param string|bool $module Check if a specific module is allowed.
	 * @param bool $top_menu Is this a top level menu point? Defaults to a Smush sub page.
	 *
	 * @return bool|array  Can access page or not. If custom access rules defined - return custom rules array.
	 */
	public static function can_access( $module = false, $top_menu = false ) {
		// Allow all access on single site installs.
		if ( ! is_multisite() ) {
			return true;
		}

		$access = get_site_option( self::$subsite_controls_option_id );

		// Check to if the settings update is network-wide or not ( only if in network admin ).
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS );

		$is_network_admin = is_network_admin() || 'save_settings' === $action;

		if ( self::is_ajax_network_admin() ) {
			$is_network_admin = true;
		}

		if ( $is_network_admin && ! $access && $top_menu ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) && ( '1' === $access || 'custom' === $access && $top_menu ) ) {
			return true;
		}

		if ( is_array( $access ) && current_user_can( 'manage_options' ) ) {
			if ( ! $module ) {
				return $access;
			}

			if ( $is_network_admin && ! in_array( $module, $access, true ) ) {
				return true;
			} elseif ( ! $is_network_admin && in_array( $module, $access, true ) ) {
				return true;
			}

			return false;
		}

		return false;
	}

	public function maybe_reset_cache_site_settings( $new_blog_id, $prev_blog_id ) {
		$this->reset_cache_site_settings();
	}

	public function reset_cache_site_settings() {
		$this->settings = array();// Reset settings, leave force update the settings for get_site_settings.
	}

	private function update_site_settings( $new_settings ) {
		$new_settings  = (array) $new_settings;
		$site_settings = $this->get_site_settings();

		foreach ( $new_settings as $setting => $value ) {
			if ( isset( $site_settings[ $setting ], $value ) ) {
				$site_settings[ $setting ] = $value;
			}
		}

		$this->update_site_option( self::$settings_option_id, $site_settings );
		$this->reset_cache_site_settings();
	}

	public function get_site_settings() {
		if ( empty( $this->settings ) ) {
			$this->settings = $this->prepare_site_settings();
		}

		return $this->settings;
	}

	private function prepare_site_settings() {
		$is_multisite = is_multisite();
		if ( ! $is_multisite ) {
			// Make sure the new default settings are included into the old configs.
			$site_settings = get_option( self::$settings_option_id, array() );
			return wp_parse_args( $this->ensure_array( $site_settings ), $this->get_defaults() );
		}

		$network_settings = get_site_option( self::$settings_option_id, array() );
		$network_settings = $this->ensure_array( $network_settings );
		$network_settings = wp_parse_args( $network_settings, $this->get_defaults() );
		if ( $this->is_network_enabled() ) {
			return $network_settings;
		}

		$subsite_modules = $this->get_activated_subsite_modules();
		$network_modules = array_diff( $this->get_modules(), $subsite_modules );
		if ( in_array( self::$lazy_preload_module_name, $network_modules, true ) ) {
			// Lazy & preload modules include 2 modules: lazy_load and preload.
			$network_modules[] = 'preload';
		}
		$subsite_settings = get_option( self::$settings_option_id, array() );
		$subsite_settings = $this->ensure_array( $subsite_settings );

		foreach ( $network_modules as $key ) {
			// Remove values that are network wide from subsite settings.
			$get_module_fields = "get_{$key}_fields";
			if ( method_exists( $this, $get_module_fields ) ) {
				$subsite_settings = array_diff_key( $subsite_settings, array_flip( $this->$get_module_fields() ) );
			}
		}

		// And append subsite settings to the site settings.
		$network_settings = array_merge( $network_settings, $subsite_settings );

		return $network_settings;
	}

	/**
	 * Ensure the input is an array.
	 *
	 * @param mixed $array_value Array value.
	 * @return array
	 */
	private function ensure_array( $array_value ) {
		return empty( $array_value ) || ! is_array( $array_value )
			? array()
			: $array_value;
	}

	/**
	 * Getter method for $settings.
	 *
	 * @since 3.0
	 *
	 * @param string $setting Setting to get. Default: get all settings.
	 *
	 * @return array|bool  Return either a setting value or array of settings.
	 */
	public function get( $setting = '' ) {
		$settings = $this->get_site_settings();

		if ( 'lossy' === $setting && isset( $settings['lossy'] ) ) {
			return $this->sanitize_lossy_level( $settings['lossy'] );
		}

		if ( ! empty( $setting ) ) {
			return isset( $settings[ $setting ] ) ? $settings[ $setting ] : false;
		}

		return $settings;
	}

	/**
	 * Setter method for $settings.
	 *
	 * @since 3.0
	 *
	 * @param string $setting Setting to update.
	 * @param bool $value Value to set. Default: false.
	 */
	public function set( $setting = '', $value = false ) {
		if ( empty( $setting ) ) {
			return;
		}

		$this->update_site_settings( array( $setting => $value ) );
	}

	public function delete( $setting ) {
		if ( empty( $setting ) ) {
			return;
		}

		$settings = $this->get_site_settings();
		if ( isset( $settings[ $setting ] ) ) {
			unset( $settings[ $setting ] );
			$this->update_site_settings( $settings );
		}
	}

	/**
	 * Get all Smush settings, based on if network settings are enabled or not.
	 *
	 * @param string $name Setting to fetch.
	 * @param mixed $default Default value.
	 *
	 * @return bool|mixed
	 */
	public function get_setting( $name = '', $default = false ) {
		if ( empty( $name ) ) {
			return false;
		}

		if ( ! is_multisite() ) {
			return get_option( $name, $default );
		}

		$global          = $this->is_network_setting( $name );
		$global_settings = get_site_option( $name, $default );
		if ( $global ) {
			return $global_settings;
		}

		$subsite_settings = get_option( $name, $default );
		$subsite_settings = false !== $subsite_settings ? $subsite_settings : $global_settings;

		return $subsite_settings;
	}

	/**
	 * Update value for given setting key
	 *
	 * @param string $name Key.
	 * @param mixed $value Value.
	 *
	 * @return bool If the setting was updated or not
	 */
	public function set_setting( $name = '', $value = '' ) {
		if ( empty( $name ) ) {
			return false;
		}

		if ( self::$settings_option_id === $name ) {
			return $this->update_site_settings( $value );
		}

		return $this->update_site_option( $name, $value );
	}

	private function update_site_option( $name, $value ) {
		$global = $this->is_network_setting( $name );

		return $global ? update_site_option( $name, $value ) : update_option( $name, $value );
	}

	/**
	 * Delete the given key name.
	 *
	 * @param string $name Key.
	 *
	 * @return bool If the setting was updated or not
	 */
	public function delete_setting( $name = '' ) {
		if ( empty( $name ) ) {
			return false;
		}

		$global = $this->is_network_setting( $name );

		return $global ? delete_site_option( $name ) : delete_option( $name );
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @since 3.2.0
	 */
	public function reset() {
		check_ajax_referer( 'wp_smush_reset' );

		$capability = is_multisite() ? 'manage_network' : 'manage_options';

		// Check capability.
		if ( ! Helper::is_user_allowed( $capability ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wp-smushit' ), 403 );
		}

		delete_site_option( self::$subsite_controls_option_id );
		delete_site_option( 'wp-smush-webp_hide_wizard' );
		delete_site_option( 'wp-smush-preset_configs' );

		// Reset rating notification flags.
		$this->delete_setting( 'wp-smush-rating-status' );

		$this->delete_setting( 'wp-smush-image_sizes' );
		$this->delete_setting( 'wp-smush-resize_sizes' );
		$this->delete_setting( 'wp-smush-cdn_status' );
		$this->delete_setting( 'wp-smush-lazy_load' );
		$this->delete_setting( 'wp-smush-preload' );
		$this->delete_setting( 'wp-smush-cdn-advanced-settings' );
		$this->delete_setting( 'wp-smush-hide-tutorials' );
		$this->delete_setting( self::$dir_settings_option_id );
		delete_option( 'wp-smush-png2jpg-rewrite-rules-flushed' );
		delete_option( 'wp_smush_scan_slice_size' );

		LCP_Helper::delete_all_lcp_data();

		// Delete activity log notifications.
		delete_option( 'wp_smush_notifications' );

		// Delete dismissed notices.
		delete_option( 'wp-smush-dismissed-notices' );

		// We used update_option for skip-smush-setup,
		// so let's reset it with delete_option instead of delete_site_option for MU site.
		delete_option( 'skip-smush-setup' );

		// Reset site settings.
		$this->reset_site_settings();

		// Reset sub-sites.
		$this->reset_sub_sites();

		wp_send_json_success();
	}

	private function reset_site_settings() {
		$this->delete_setting( self::$settings_option_id );
		$this->reset_cache_site_settings();
		// The action wp_smush_settings_updated only triggers after option is updated, does not trigger on add_(site_)option.
		// So to support this, we need to add the default option first.
		$this->add_default_site_settings();
	}

	private function add_default_site_settings() {
		$this->update_site_settings( $this->get_defaults() );
	}

	public function initial_default_site_settings() {
		if ( false === $this->get_setting( self::$settings_option_id, false ) ) {
			$this->add_default_site_settings();
		}
	}

	private function reset_sub_sites() {
		if ( ! is_multisite() ) {
			return;
		}

		$site_args = array(
			'fields' => 'ids',
			'public' => 1,
			'number' => 250, // Limit to 250 sites to avoid performance issues.
		);

		$site_ids = get_sites( $site_args );
		if ( empty( $site_ids ) ) {
			return;
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->reset_sub_site_settings();
			restore_current_blog();
		}
	}

	private function reset_sub_site_settings() {
		delete_option( self::$settings_option_id );
		delete_option( 'wp-smush-image_sizes' );
		delete_option( 'wp-smush-resize_sizes' );
		delete_option( 'wp-smush-cdn_status' );
		delete_option( 'wp-smush-lazy_load' );
		delete_option( 'wp-smush-preload' );
		delete_option( 'wp-smush-cdn-advanced-settings' );
		delete_option( 'wp-smush-hide-tutorials' );
		delete_option( 'skip-smush-setup' );
		delete_option( 'wp_smush_scan_slice_size' );

		LCP_Helper::delete_all_lcp_data();
	}

	/**
	 * Apply a default configuration to lazy loading on first activation.
	 *
	 * @since 3.2.0
	 */
	public function init_lazy_load_defaults() {
		$defaults = $this->get_lazy_load_defaults();

		$this->set_setting( 'wp-smush-lazy_load', $defaults );
	}

	/**
	 * Check if in network admin.
	 *
	 * The is_network_admin() check does not work in ajax calls.
	 *
	 * @since 3.10.3
	 *
	 * @return bool
	 */
	public static function is_ajax_network_admin() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_SERVER['HTTP_REFERER'] ) && preg_match( '#^' . network_admin_url() . '#i', wp_unslash( $_SERVER['HTTP_REFERER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	public function is_optimize_original_images_active() {
		return ! empty( self::get_instance()->get( 'original' ) );
	}

	public function is_png2jpg_module_active() {
		return $this->is_module_active( 'png_to_jpg' );
	}

	public function is_webp_module_active() {
		return $this->is_module_active( 'webp_mod' );
	}

	public function is_avif_module_active() {
		return $this->is_module_active( 'avif_mod' );
	}

	public function is_avif_fallback_active() {
		return $this->is_avif_module_active()
		       && ! empty( self::get_instance()->get( 'avif_fallback' ) );
	}

	public function is_resize_module_active() {
		return $this->is_module_active( 'resize' );
	}

	public function is_backup_active() {
		return $this->is_module_active( 'backup' );
	}

	public function is_s3_active() {
		return $this->is_module_active( 's3' );
	}

	public function is_cdn_webp_conversion_active() {
		return $this->is_cdn_active()
		       && self::$webp_cdn_mode === $this->get_cdn_next_gen_conversion_mode();
	}

	public function is_cdn_avif_conversion_active() {
		return $this->is_cdn_active()
		       && self::$avif_cdn_mode === $this->get_cdn_next_gen_conversion_mode();
	}

	public function is_cdn_next_gen_conversion_active() {
		return $this->is_cdn_active()
		       && ! empty( $this->get_cdn_next_gen_conversion_mode() );
	}

	public function get_cdn_next_gen_conversion_mode() {
		$cdn_next_gen_mode = (int) self::get_instance()->get( self::$next_gen_cdn_key );

		return $this->sanitize_cdn_next_gen_conversion_mode( $cdn_next_gen_mode );
	}

	public function get_cdn_next_gen_conversion_label( $cdn_next_gen_mode ) {
		$cdn_next_gen_mode  = $this->sanitize_cdn_next_gen_conversion_mode( $cdn_next_gen_mode );
		$cdn_next_gen_modes = $this->get_cdn_next_gen_modes();

		return $cdn_next_gen_modes[ $cdn_next_gen_mode ];
	}

	public function sanitize_cdn_next_gen_conversion_mode( $cdn_next_gen_mode ) {
		$cdn_next_gen_mode  = (int) $cdn_next_gen_mode;
		$cdn_next_gen_modes = $this->get_cdn_next_gen_modes();

		if ( ! isset( $cdn_next_gen_modes[ $cdn_next_gen_mode ] ) ) {
			$cdn_next_gen_mode = self::$none_cdn_mode;
		}

		return $cdn_next_gen_mode;
	}

	private function get_cdn_next_gen_modes() {
		return array(
			self::$none_cdn_mode => __( 'None', 'wp-smushit' ),
			self::$webp_cdn_mode => __( 'WebP', 'wp-smushit' ),
			self::$avif_cdn_mode => __( 'AVIF', 'wp-smushit' ),
		);
	}

	public function is_webp_direct_conversion_active() {
		return $this->is_webp_module_active()
		       && ! empty( self::get_instance()->get( 'webp_direct_conversion' ) );
	}

	public function is_automatic_compression_active() {
		return self::get_instance()->get( 'auto' );
	}

	public function is_cdn_active() {
		return $this->is_module_active( 'cdn' );
	}

	public function is_webp_fallback_active() {
		return $this->is_webp_module_active()
		       && ! empty( self::get_instance()->get( 'webp_fallback' ) );
	}

	public function is_lazyload_active() {
		return self::get_instance()->get( 'lazy_load' );
	}

	public function is_auto_resizing_active() {
		return $this->is_module_active( 'auto_resizing' );
	}

	public function should_add_missing_dimensions() {
		return self::get_instance()->get( 'image_dimensions' );
	}

	protected function get_placeholder_modules() {
		return array(
			'cdn',
			'webp_mod',
			'avif_mod',
			's3',
			'nextgen',
			'ultra',
			'preload_images',
			'auto_resizing',
			'image_dimensions',
		);
	}

	public function is_module_active( $module ) {
		$advanced_modules = $this->get_placeholder_modules();

		if ( in_array( $module, $advanced_modules, true ) ) {
			return false;
		}

		return self::get_instance()->get( $module );
	}

	public function get_lossy_level_setting() {
		$current_level = self::get_instance()->get( 'lossy' );
		return $this->sanitize_lossy_level( $current_level );
	}

	public function update_dir_settings( $settings ) {
		$this->set_setting( self::$dir_settings_option_id, $settings );
	}

	public function get_dir_lossy_level_setting() {
		$dir_settings = $this->get_setting( self::$dir_settings_option_id, array() );
		if ( isset( $dir_settings['dir_lossy'] ) ) {
			return $this->sanitize_lossy_level( $dir_settings['dir_lossy'] );
		}
		// Fallback to global lossy setting
		return $this->get_lossy_level_setting();
	}

	public function get_dir_strip_exif_setting() {
		$dir_settings = $this->get_setting( self::$dir_settings_option_id, array() );
		if ( isset( $dir_settings['dir_strip_exif'] ) ) {
			return (bool) $dir_settings['dir_strip_exif'];
		}
		// Fallback to global strip_exif setting
		return (bool) $this->get( 'strip_exif' );
	}

	public function sanitize_lossy_level( $lossy_level ) {
		$highest_level = $this->get_highest_lossy_level();

		if ( $lossy_level > $highest_level ) {
			return $highest_level;
		}

		if ( $lossy_level > self::$level_lossless ) {
			return (int) $lossy_level;
		}

		return self::$level_lossless;
	}

	public function get_highest_lossy_level() {
		if ( is_multisite() && ! Membership::get_instance()->has_access_to_hub() ) {
			return self::$level_lossless;
		}
		return self::$level_super_lossy;
	}

	public function get_current_lossy_level_label() {
		$current_level = $this->get_lossy_level_setting();
		return $this->get_lossy_level_label( $current_level );
	}

	public function get_lossy_level_label( $lossy_level ) {
		$smush_modes = array(
			self::$level_lossless    => __( 'Basic', 'wp-smushit' ),
			self::$level_super_lossy => __( 'Super', 'wp-smushit' ),
			self::$level_ultra_lossy => __( 'Ultra', 'wp-smushit' ),
		);
		if ( ! isset( $smush_modes[ $lossy_level ] ) ) {
			$lossy_level = self::$level_lossless;
		}

		return $smush_modes[ $lossy_level ];
	}

	public function get_large_file_cutoff() {
		return apply_filters( 'wp_smush_large_file_cut_off', 32 * 1024 * 1024 );
	}

	public function has_bulk_smush_page() {
		return $this->can_access_page( 'bulk' );
	}

	public function has_cdn_page() {
		return $this->can_access_page( 'cdn' );
	}

	public function has_webp_page() {
		_deprecated_function( __METHOD__, '3.8.0', 'Settings::has_next_gen_page()' );
		return $this->has_next_gen_page();
	}

	public function has_next_gen_page() {
		return $this->can_access_page( 'next-gen' );
	}

	public function has_lazy_preload_page() {
		return $this->can_access_page( 'lazy-preload' );
	}

	public function streaming_enabled() {
		if ( defined( 'WP_SMUSH_USE_STREAMS' ) ) {
			return (bool) WP_SMUSH_USE_STREAMS;
		}

		return self::get_instance()->get( 'disable_streams' ) != WP_SMUSH_VERSION;
	}

	public function is_lcp_preload_enabled() {
		return $this->is_module_active( 'preload_images' );
	}

	private function can_access_page( $page_slug ) {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( is_network_admin() || self::is_ajax_network_admin() ) {
			return Helper::is_user_allowed( 'manage_network' );
		}

		$module                 = $this->slug_to_module( $page_slug );
		$is_enabled_for_subsite = in_array( $module, $this->get_activated_subsite_modules(), true );

		return $is_enabled_for_subsite;
	}

	/**
	 * Check if the directory smush module is active.
	 *
	 * @return bool
	 */
	public function is_directory_smush_active() {
		return $this->can_access_page( 'directory' );
	}

	/**
	 * @return array
	 */
	private function get_activated_subsite_modules() {
		if ( ! is_array( $this->activated_subsite_modules ) ) {
			$this->activated_subsite_modules = $this->get_activated_subsite_modules_list();
		}

		return $this->activated_subsite_modules;
	}

	/**
	 * @return array
	 */
	public function get_activated_subsite_modules_list() {
		$subsite_controls = get_site_option( self::$subsite_controls_option_id );
		// None:false|All:1|Custom:array list page modules.
		if ( empty( $subsite_controls ) ) {
			return array();
		}

		$subsite_modules = $this->get_subsite_modules();
		if ( is_array( $subsite_controls ) ) {
			$subsite_modules = $subsite_controls;
		}

		return $subsite_modules;
	}

	private function get_subsite_modules() {
		return array(
			'bulk',
			'directory_smush',
			'integrations',
			self::$lazy_preload_module_name,
			'cdn',
		);
	}

	private function slug_to_module( $page_slug ) {
		$page_to_module_map = array(
			'bulk'         => 'bulk',
			'cdn'          => 'cdn',
			'next-gen'     => 'nextgen',
			'directory'    => 'directory_smush',
			'lazy-preload' => self::$lazy_preload_module_name,
		);

		return isset( $page_to_module_map[ $page_slug ] ) ? $page_to_module_map[ $page_slug ] : str_replace( '-', '_', $page_slug );
	}

	/**
	 * // TODO: [WPMUDEV SMUSH UI] there is another method above that does the same thing. Merge the two methods.
	 */
	public function is_auto_smush_enabled() {
		$auto_smush = $this->get( 'auto' );

		// Keep the auto smush on by default.
		if ( ! isset( $auto_smush ) ) {
			$auto_smush = 1;
		}

		return $auto_smush;
	}

	/**
	 * Get the maximum content width for images.
	 *
	 * @return int
	 */
	public function max_content_width() {
		// Get global content width (if content width is empty, set 2560).
		$content_width = isset( $GLOBALS['content_width'] ) ? (int) $GLOBALS['content_width'] : $this->get_default_size_threshold();

		// Avoid situations, when themes misuse the global.
		if ( 0 === $content_width ) {
			$content_width = $this->get_default_size_threshold();
		}

		$resize_module_active = $this->is_resize_module_active();
		if ( ! $resize_module_active ) {
			return $content_width;
		}

		// Check to see if we are resizing the images (can not go over that value).
		$resize_sizes = $this->get_setting( 'wp-smush-resize_sizes' );

		if ( isset( $resize_sizes['width'] ) && $resize_sizes['width'] < $content_width ) {
			return $resize_sizes['width'];
		}

		return $content_width;
	}

	/**
	 * Get the default size threshold for images.
	 *
	 * WordPress sets the default threshold value to 2560 pixels.
	 *
	 * @return int
	 */
	public function get_default_size_threshold() {
		return apply_filters( 'wp_smush_default_size_threshold', 2560 );
	}

	/**
	 * Get avif_cdn_mode.
	 *
	 * @return int
	 */
	public static function get_avif_cdn_mode() {
		return self::$avif_cdn_mode;
	}


	/**
	 * Get lazy_preload_module_name.
	 *
	 * @return string
	 */
	public static function get_lazy_preload_module_name() {
		return self::$lazy_preload_module_name;
	}


	/**
	 * Get level_lossless.
	 *
	 * @return int
	 */
	public static function get_level_lossless() {
		return self::$level_lossless;
	}

	/**
	 * Mark the current setting as level lossless.
	 */
	public static function set_lossless_level() {
		return self::get_instance()->set( 'lossy', self::get_level_lossless() );
	}


	/**
	 * Get level_super_lossy.
	 *
	 * @return int
	 */
	public static function get_level_super_lossy() {
		return self::$level_super_lossy;
	}


	/**
	 * Get level_ultra_lossy.
	 *
	 * @return int
	 */
	public static function get_level_ultra_lossy() {
		return self::$level_ultra_lossy;
	}


	/**
	 * Get next_gen_cdn_key.
	 *
	 * @return string
	 */
	public static function get_next_gen_cdn_key() {
		return self::$next_gen_cdn_key;
	}


	/**
	 * Get none_cdn_mode.
	 *
	 * @return int
	 */
	public static function get_none_cdn_mode() {
		return self::$none_cdn_mode;
	}


	/**
	 * Get settings_key.
	 *
	 * @return string
	 */
	public static function get_settings_option_id() {
		return self::$settings_option_id;
	}


	/**
	 * Get subsite_controls_option_key.
	 *
	 * @return string
	 */
	public static function get_subsite_controls_option_id() {
		return self::$subsite_controls_option_id;
	}


	/**
	 * Get webp_cdn_mode.
	 *
	 * @return int
	 */
	public static function get_webp_cdn_mode() {
		return self::$webp_cdn_mode;
	}

	/**
	 * @return array
	 */
	public function get_lazy_load_defaults() {
		$defaults = array(
			'format'            => array(
				'jpeg'        => true,
				'png'         => true,
				'webp'        => true,
				'gif'         => true,
				'svg'         => true,
				'iframe'      => true,
				'embed_video' => false,
			),
			'output'            => array(
				'content'    => true,
				'widgets'    => true,
				'thumbnails' => true,
				'gravatars'  => true,
			),
			'animation'         => array(
				'selected'    => 'fadein', // Accepts: fadein, spinner, placeholder, false.
				'fadein'      => array(
					'duration' => 400,
					'delay'    => 0,
				),
				'spinner'     => array(
					'selected' => 1,
					'custom'   => array(),
				),
				'placeholder' => array(
					'selected' => 1,
					'custom'   => array(),
					'color'    => '#F3F3F3',
				),
			),
			'include'           => array(
				'frontpage' => true,
				'home'      => true,
				'page'      => true,
				'single'    => true,
				'archive'   => true,
				'category'  => true,
				'tag'       => true,
			),
			'exclude-pages'     => array(),
			'exclude-classes'   => array(),
			'footer'            => true,
			'native'            => false,
			'noscript_fallback' => false,
		);
		return $defaults;
	}

	/**
	 * Get the maximum file size (in bytes) that can be optimized.
	 *
	 * @return mixed
	 */
	public function get_file_size_limit() {
		$file_size_limit = 5 * 1024 * 1024; // 5 MB
		if ( defined( 'WP_SMUSH_MAX_BYTES' ) && WP_SMUSH_MAX_BYTES > 0 ) {
			$file_size_limit = min( $file_size_limit, WP_SMUSH_MAX_BYTES );
		}
		return $file_size_limit;
	}
}
