<?php
/**
 * Configs class.
 *
 * @since 3.8.5
 * @package Smush\Core
 */

namespace Smush\Core;

use Exception;
use WP_Error;
use WP_REST_Request;
use Smush\Core\Modules\Helpers\WhiteLabel;

/**
 * Class Configs
 *
 * @since 3.8.5
 */
class Configs {
	private static $instance;

	/**
	 * List of pro features.
	 *
	 * @since 3.8.5
	 *
	 * @var array
	 */
	protected $placeholder_features = array( 's3', 'cdn', 'webp', 'webp_mod', 'avif_mod', 'preload_images', 'auto_resizing', 'image_dimensions' );

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var @var WhiteLabel
	 */
	private $whitelabel;

	public function __construct() {
		$this->settings   = Settings::get_instance();
		$this->whitelabel = new WhiteLabel();
	}

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new Configs_Pro();
		}

		return self::$instance;
	}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '3.24.0' );
	}

	/**
	 * Gets the local list of configs via Smush endpoint.
	 *
	 * @since 3.8.6
	 *
	 * @return bool
	 */
	public function get_callback() {
		$stored_configs = get_site_option( 'wp-smush-preset_configs', false );

		if ( false === $stored_configs ) {
			$stored_configs = array( $this->get_basic_config() );
			update_site_option( 'wp-smush-preset_configs', $stored_configs );
		} else {
			foreach ( $stored_configs as $id => $config ) {
				$is_basic_config = ! empty( $config['default'] );
				if ( $is_basic_config ) {
					$stored_configs[ $id ] = $this->get_basic_config();
					break;
				}
			}
		}
		return $stored_configs;
	}

	/**
	 * Updates the local list of configs via Smush endpoint.
	 *
	 * @since 3.8.6
	 *
	 * @param WP_REST_Request $request Class containing the request data.
	 *
	 * @return array|WP_Error
	 */
	public function post_callback( $request ) {
		$data = json_decode( $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( '400', esc_html__( 'Missing configs data', 'wp-smushit' ), array( 'status' => 400 ) );
		}

		$sanitized_data = $this->sanitize_configs_list( $data );
		update_site_option( 'wp-smush-preset_configs', $sanitized_data );

		return $sanitized_data;
	}

	/**
	 * Checks whether the current user can perform requests to Smush's endpoint.
	 *
	 * @since 3.8.6
	 *
	 * @return bool
	 */
	public function permission_callback() {
		$capability = is_multisite() ? 'manage_network' : 'manage_options';
		return current_user_can( $capability );
	}

	/**
	 * Adds the default configuration to the local configs.
	 *
	 * @since 3.8.6
	 *
	 * TODO: Add get_defaults for Settings class and use it here.
	 */
	private function get_basic_config() {
		$basic_config = array(
			'id'          => 1,
			'name'        => $this->whitelabel->replace_branding_terms( __( 'Smush', 'wp-smushit' ) ),
			'description' => $this->whitelabel->is_whitelabel_enabled() ? __( 'Recommended config', 'wp-smushit' ) : __( 'Recommended Smush config by WPMU DEV developers', 'wp-smushit' ),
			'default'     => true,
			'config'      => array(
				'configs' => array(
					'settings' => array(
						'auto'              => true,
						'lossy'             => Settings::get_level_super_lossy(),
						'strip_exif'        => true,
						'resize'            => false,
						'original'          => true,
						'backup'            => true,
						'png_to_jpg'        => true,
						'background_email'  => true,
						's3'                => false,
						'gutenberg'         => false,
						'js_builder'        => false,
						'cdn'               => false,
						'auto_resizing'     => false,
						'cdn_dynamic_sizes' => false,
						'image_dimensions'  => false,
						'webp'              => true,
						'usage'             => false,
						'accessible_colors' => false,
						'keep_data'         => true,
						'lazy_load'         => false,
						'background_images' => true,
						'rest_api_support'  => false,
						'webp_mod'          => false,
						'avif_mod'          => false,
						'preload_images'    => false,
						'lcp_fetchpriority' => false,
					),
				),
			),
		);

		$basic_config['config']['strings'] = $this->format_config_to_display( $basic_config['config']['configs'] );

		return $basic_config;
	}

	/**
	 * Sanitizes the full list of configs.
	 *
	 * @since 3.8.6
	 *
	 * @param array $configs_list Configs list to sanitize.
	 * @return array
	 */
	private function sanitize_configs_list( $configs_list ) {
		$sanitized_list = array();

		foreach ( $configs_list as $config_data ) {
			if ( isset( $config_data['name'] ) ) {
				$name = sanitize_text_field( $config_data['name'] );
			}

			if ( isset( $config_data['description'] ) ) {
				$description = sanitize_text_field( $config_data['description'] );
			}

			$configs        = isset( $config_data['config']['configs'] ) ? $config_data['config']['configs'] : array();
			$sanitized_data = array(
				'id'          => filter_var( $config_data['id'], FILTER_VALIDATE_INT ),
				'name'        => empty( $name ) ? __( 'Undefined', 'wp-smushit' ) : $name,
				'description' => empty( $description ) ? '' : $description,
				'config'      => $this->sanitize_and_format_configs( $configs ),
			);

			if ( isset( $config_data['note'] ) ) {
				$sanitized_data['description'] = sanitize_text_field( $config_data['note'] );
			}
			if ( isset( $config_data['note_added_time'] ) ) {
				$sanitized_data['note_added_time'] = sanitize_text_field( $config_data['note_added_time'] );
			}
			if ( isset( $config_data['date'] ) ) {
				$sanitized_data['date'] = sanitize_text_field( $config_data['date'] );
			}

			if ( ! empty( $config_data['hub_id'] ) ) {
				$sanitized_data['hub_id'] = filter_var( $config_data['hub_id'], FILTER_VALIDATE_INT );
			}
			if ( isset( $config_data['default'] ) ) {
				$sanitized_data['default'] = filter_var( $config_data['default'], FILTER_VALIDATE_BOOLEAN );
			}

			$sanitized_list[] = $sanitized_data;
		}

		return $sanitized_list;
	}

	/**
	 * Tries to save the uploaded config.
	 *
	 * @since 3.8.5
	 *
	 * @param array $file The uploaded file.
	 *
	 * @return array|WP_Error
	 */
	public function save_uploaded_config( $file ) {
		try {
			return $this->decode_and_validate_config_file( $file );
		} catch ( Exception $e ) {
			return new WP_Error( 'error_saving', $e->getMessage() );
		}
	}

	/**
	 * Tries to decode and validate the uploaded config file.
	 *
	 * @since 3.8.5
	 *
	 * @param array $file The uploaded file.
	 *
	 * @return array
	 *
	 * @throws Exception When there's an error with the uploaded file.
	 */
	private function decode_and_validate_config_file( $file ) {
		if ( ! $file ) {
			throw new Exception( __( 'The configs file is required', 'wp-smushit' ) );
		} elseif ( ! empty( $file['error'] ) ) {
			/* translators: error message */
			throw new Exception( sprintf( __( 'Error: %s.', 'wp-smushit' ), $file['error'] ) );
		} elseif ( 'application/json' !== $file['type'] ) {
			throw new Exception( __( 'The file must be a JSON.', 'wp-smushit' ) );
		}

		$json_file = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $json_file ) {
			throw new Exception( __( 'There was an error getting the contents of the file.', 'wp-smushit' ) );
		}

		$configs = json_decode( $json_file, true );
		if ( empty( $configs ) || ! is_array( $configs ) ) {
			throw new Exception( __( 'There was an error decoding the file.', 'wp-smushit' ) );
		}

		// Make sure the config has a name and configs.
		if ( empty( $configs['name'] ) || empty( $configs['config'] ) ) {
			throw new Exception( __( 'The uploaded config must have a name and a set of settings. Please make sure the uploaded file is the correct one.', 'wp-smushit' ) );
		}

		// Sanitize.
		$plugin  = isset( $configs['plugin'] ) ? $configs['plugin'] : 0;
		$configs = $this->sanitize_configs_list( array( $configs ) );
		$configs = $configs[0];

		// Restore back plugin ID.
		$configs['plugin'] = $plugin;

		// Let's re-create this to avoid differences between imported settings coming from other versions.
		$configs['config']['strings'] = $this->format_config_to_display( $configs['config']['configs'] );

		if ( empty( $configs['config']['configs'] ) ) {
			throw new Exception( __( 'The provided configs list isn’t correct. Please make sure the uploaded file is the correct one.', 'wp-smushit' ) );
		}

		// Don't keep these if they exist.
		if ( isset( $configs['hub_id'] ) ) {
			unset( $configs['hub_id'] );
		}
		if ( isset( $configs['default'] ) ) {
			unset( $configs['default'] );
		}

		return $configs;
	}

	/**
	 * Applies a config given its ID.
	 *
	 * @since 3.8.6
	 *
	 * @param string $id The ID of the config to apply.
	 *
	 * @return void|WP_Error
	 */
	public function apply_config_by_id( $id ) {
		$stored_configs = get_site_option( 'wp-smush-preset_configs' );

		$config = false;
		foreach ( $stored_configs as $config_data ) {
			if ( (int) $config_data['id'] === (int) $id ) {
				$config = $config_data;
				break;
			}
		}

		// The config with the given ID doesn't exist.
		if ( ! $config ) {
			return new WP_Error( '404', __( 'The given config ID does not exist', 'wp-smushit' ) );
		}

		$this->apply_config( $config['config']['configs'], $config['name'] );
	}

	/**
	 * Applies the given config.
	 *
	 * @since 3.8.6
	 *
	 * @param array $config The config to apply.
	 */
	public function apply_config( $config, $config_name = '' ) {
		$sanitized_config = $this->sanitize_config( $config );

		// Update 'networkwide' options in multisites.
		if ( is_multisite() && isset( $sanitized_config['networkwide'] ) ) {
			update_site_option( 'wp-smush-networkwide', $sanitized_config['networkwide'] );
		}

		$settings_handler = Settings::get_instance();

		// Update image sizes.
		if ( isset( $sanitized_config['resize_sizes'] ) ) {
			$settings_handler->set_setting( 'wp-smush-resize_sizes', $sanitized_config['resize_sizes'] );
		}

		// Update image sizes include/exclude list.
		if ( array_key_exists( 'image_sizes', $sanitized_config ) ) {
			if ( is_array( $sanitized_config['image_sizes'] ) ) {
				$settings_handler->set_setting( 'wp-smush-image_sizes', $sanitized_config['image_sizes'] );
			} else {
				// Non-array values represent "all selected".
				$settings_handler->delete_setting( 'wp-smush-image_sizes' );
			}
		}

		// Update settings. We could reuse the `save` method from settings to handle this instead.
		if ( ! empty( $sanitized_config['settings'] ) ) {
			$stored_settings = $settings_handler->get_site_settings();
			$incoming_lcp_fetchpriority = isset( $sanitized_config['settings']['lcp_fetchpriority'] )
				? (bool) $sanitized_config['settings']['lcp_fetchpriority']
				: null;


			// Keep the keys that are in use in this version.
			$new_settings = array_intersect_key( $sanitized_config['settings'], $stored_settings );

			if ( $new_settings ) {
				foreach ( $this->get_placeholder_features() as $name ) {
					$new_settings[ $name ] = false;
				}

				// Keep the stored settings that aren't present in the incoming one.
				$new_settings = array_merge( $stored_settings, $new_settings );
				$settings_handler->set_setting( 'wp-smush-settings', $new_settings );
			}

			if ( null !== $incoming_lcp_fetchpriority ) {
				$stored_preload = $settings_handler->get_setting( 'wp-smush-preload', array() );
				if ( ! is_array( $stored_preload ) ) {
					$stored_preload = array();
				}

				$stored_preload['lcp_fetchpriority'] = $incoming_lcp_fetchpriority;
				$settings_handler->set_setting( 'wp-smush-preload', $stored_preload );
			}
		}

		// Update lazy load.
		if ( ! empty( $sanitized_config['lazy_load'] ) ) {
			$stored_lazy_load = $settings_handler->get_setting( 'wp-smush-lazy_load' );

			// Save the defaults before applying the config if the current settings aren't set.
			if ( empty( $stored_lazy_load ) ) {
				$settings_handler->init_lazy_load_defaults();
				$stored_lazy_load = $settings_handler->get_setting( 'wp-smush-lazy_load' );
			}

			// Keep the settings that are in use in this version.
			foreach ( $sanitized_config['lazy_load'] as $key => $value ) {
				if ( is_array( $value ) && is_array( $stored_lazy_load[ $key ] ) ) {
					$sanitized_config['lazy_load'][ $key ] = array_intersect_key( $value, $stored_lazy_load[ $key ] );
				}
			}

			// Keep the stored settings that aren't present in the incoming one.
			$new_lazy_load = array_replace_recursive( $stored_lazy_load, $sanitized_config['lazy_load'] );
			$settings_handler->set_setting( 'wp-smush-lazy_load', $new_lazy_load );
		}

		do_action( 'wp_smush_config_applied', $config_name );

		// Skip onboarding if applying a config.
		update_option( 'skip-smush-setup', true );
	}

	/**
	 * Gets a new config array based on the current settings.
	 *
	 * @since 3.8.5
	 *
	 * @return array
	 */
	public function get_config_from_current() {
		$settings = Settings::get_instance();

		$stored_settings = $settings->get_setting( 'wp-smush-settings' );
		if ( ! is_array( $stored_settings ) ) {
			$stored_settings = array();
		}
		$stored_preload_settings = $settings->get_setting( 'wp-smush-preload', array() );
		$stored_settings['lcp_fetchpriority'] =
			is_array( $stored_preload_settings ) && ! empty( $stored_preload_settings['lcp_fetchpriority'] );

		$configs = array( 'settings' => $stored_settings );

		if ( ! empty( $stored_settings['resize'] ) ) {
			$configs['resize_sizes'] = $settings->get_setting( 'wp-smush-resize_sizes' );
		}

		// Keep image sizes in the config payload even though they are stored in a separate option.
		$stored_image_sizes                   = $settings->get_setting( 'wp-smush-image_sizes' );
		$configs['settings']['image_sizes'] = is_array( $stored_image_sizes ) ? $stored_image_sizes : false;

		// Let's store this only for multisites.
		if ( is_multisite() ) {
			$configs['networkwide'] = get_site_option( 'wp-smush-networkwide' );
		}

		// There's a site_option that handles this.
		unset( $configs['settings']['networkwide'] );

		// Looks like unused.
		unset( $configs['settings']['api_auth'] );

		// These are unique per site. They shouldn't be used.
		unset( $configs['settings']['bulk'] );

		// Include the lazy load settings only when lazy load is enabled.
		if ( ! empty( $configs['settings']['lazy_load'] ) ) {
			$lazy_load_settings = $settings->get_setting( 'wp-smush-lazy_load' );

			if ( ! empty( $lazy_load_settings ) ) {
				// Exclude unique settings.
				unset( $lazy_load_settings['animation']['placeholder'] );
				unset( $lazy_load_settings['animation']['spinner'] );
				unset( $lazy_load_settings['exclude-pages'] );
				unset( $lazy_load_settings['exclude-classes'] );

				if ( 'fadein' !== $lazy_load_settings['animation']['selected'] ) {
					unset( $lazy_load_settings['animation']['fadein'] );
				}

				$configs['lazy_load'] = $lazy_load_settings;
			}
		}

		// Exclude CDN fields if CDN is disabled.
		if ( empty( $configs['settings']['cdn'] ) ) {
			foreach ( $settings->get_cdn_fields() as $field ) {
				if ( 'cdn' !== $field ) {
					unset( $configs['settings'][ $field ] );
				}
			}
		}

		return array(
			'config' => array(
				'configs' => $configs,
				'strings' => $this->format_config_to_display( $configs ),
			),
		);
	}

	/**
	 * Sanitizes the given config.
	 *
	 * @since 3.8.5
	 *
	 * @param array $config Config array to sanitize.
	 *
	 * @return array
	 */
	protected function sanitize_config( $config ) {
		$sanitized = array();
		$raw_image_sizes = null;

		if ( array_key_exists( 'image_sizes', $config ) ) {
			$raw_image_sizes = $config['image_sizes'];
		} elseif ( isset( $config['settings'] ) && array_key_exists( 'image_sizes', $config['settings'] ) ) {
			$raw_image_sizes = $config['settings']['image_sizes'];
		}

		if ( isset( $config['networkwide'] ) ) {
			if ( ! is_array( $config['networkwide'] ) ) {
				$sanitized['networkwide'] = sanitize_text_field( $config['networkwide'] );
			} else {
				$sanitized['networkwide'] = filter_var(
					$config['networkwide'],
					FILTER_CALLBACK,
					array(
						'options' => 'sanitize_text_field',
					)
				);
			}
		}

		if ( ! empty( $config['settings'] ) ) {
			$sanitized['settings'] = filter_var( $config['settings'], FILTER_VALIDATE_BOOLEAN, FILTER_REQUIRE_ARRAY );
			if ( isset( $config['settings']['lossy'] ) ) {
				$sanitized['settings']['lossy'] = $this->settings->sanitize_lossy_level( $config['settings']['lossy'] );
			}

			if ( isset( $config['settings']['lcp_fetchpriority'] ) ) {
				$sanitized['settings']['lcp_fetchpriority'] = filter_var( $config['settings']['lcp_fetchpriority'], FILTER_VALIDATE_BOOLEAN );
			}

			if ( isset( $config['settings'][ Settings::get_next_gen_cdn_key() ] ) ) {
				$sanitized['settings'][ Settings::get_next_gen_cdn_key() ] = $this->settings->sanitize_cdn_next_gen_conversion_mode( $config['settings'][ Settings::get_next_gen_cdn_key() ] );
			}

			if ( null !== $raw_image_sizes ) {
				$sanitized['settings']['image_sizes'] = is_array( $raw_image_sizes )
					? array_values( array_filter( array_map( 'sanitize_text_field', $raw_image_sizes ) ) )
					: false;
			}
		}

		if ( null !== $raw_image_sizes ) {
			$sanitized['image_sizes'] = is_array( $raw_image_sizes )
				? array_values( array_filter( array_map( 'sanitize_text_field', $raw_image_sizes ) ) )
				: false;
		}

		if ( isset( $config['resize_sizes'] ) ) {
			if ( is_bool( $config['resize_sizes'] ) ) {
				$sanitized['resize_sizes'] = $config['resize_sizes'];
			} else {
				$sanitized['resize_sizes'] = array(
					'width'  => (int) $config['resize_sizes']['width'],
					'height' => (int) $config['resize_sizes']['height'],
				);
			}
		}

		if ( ! empty( $config['lazy_load'] ) ) {
			$args = array(
				'format'            => array(
					'filter' => FILTER_VALIDATE_BOOLEAN,
					'flags'  => FILTER_REQUIRE_ARRAY + FILTER_NULL_ON_FAILURE,
				),
				'output'            => array(
					'filter' => FILTER_VALIDATE_BOOLEAN,
					'flags'  => FILTER_REQUIRE_ARRAY,
				),
				'animation'         => array(
					'filter'  => FILTER_CALLBACK,
					'options' => 'sanitize_text_field',
					'flags'   => FILTER_REQUIRE_ARRAY,
				),
				'include'           => array(
					'filter' => FILTER_VALIDATE_BOOLEAN,
					'flags'  => FILTER_REQUIRE_ARRAY,
				),
				'exclude-pages'     => array(
					'filter' => FILTER_SANITIZE_URL,
					'flags'  => FILTER_REQUIRE_ARRAY,
				),
				'exclude-classes'   => array(
					'filter'  => FILTER_CALLBACK,
					'options' => 'sanitize_text_field',
					'flags'   => FILTER_REQUIRE_ARRAY,
				),
				'footer'            => FILTER_VALIDATE_BOOLEAN,
				'native'            => FILTER_VALIDATE_BOOLEAN,
				'noscript_fallback' => FILTER_VALIDATE_BOOLEAN,
			);

			$sanitized['lazy_load'] = filter_var_array( $config['lazy_load'], $args, false );
		}

		return $sanitized;
	}

	/**
	 * Formatting methods.
	 */

	/**
	 * Formats the given config to be displayed.
	 * Used when displaying the list of configs and when sending a config to the Hub.
	 *
	 * @since 3.8.5
	 *
	 * @param array $config The config to format.
	 *
	 * @return array Contains an array for each setting. Each with a 'label' and 'value' keys.
	 */
	protected function format_config_to_display( $config ) {
		$settings  = ! empty( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();
		$lazy_load = ! empty( $config['lazy_load'] ) && is_array( $config['lazy_load'] ) ? $config['lazy_load'] : array();

		$to_bool = static function ( $value ) {
			return (bool) $value;
		};

		$to_status = static function ( $enabled ) {
			return $enabled ? __( 'Active', 'wp-smushit' ) : __( 'Inactive', 'wp-smushit' );
		};

		$get_compression_level = static function ( $lossy ) {
			$value = strtolower( (string) $lossy );

			if ( false !== strpos( $value, 'ultra' ) || '2' === $value ) {
				return 'ultra';
			}

			if ( false !== strpos( $value, 'super' ) || '1' === $value ) {
				return 'super';
			}

			return 'basic';
		};

		$has_any_enabled_value = static function ( $value, $excluded_keys = array(), $fallback_if_empty = false ) use ( $to_bool ) {
			if ( is_array( $value ) ) {
				$entries = array();
				foreach ( $value as $key => $item ) {
					if ( in_array( (string) $key, $excluded_keys, true ) ) {
						continue;
					}
					$entries[] = $item;
				}

				if ( empty( $entries ) ) {
					return $to_bool( $fallback_if_empty );
				}

				foreach ( $entries as $item ) {
					if ( $to_bool( $item ) ) {
						return true;
					}
				}
				return false;
			}

			if ( null === $value || '' === $value ) {
				return $to_bool( $fallback_if_empty );
			}

			return $to_bool( $value );
		};

		$compression_level = $get_compression_level( isset( $settings['lossy'] ) ? $settings['lossy'] : '' );
		$cdn_enabled       = $to_bool( isset( $settings['cdn'] ) ? $settings['cdn'] : false );
		$cdn_file_format   = $cdn_enabled && $to_bool( isset( $settings['webp'] ) ? $settings['webp'] : false );
		$preload_enabled   = $to_bool( isset( $settings['preload_images'] ) ? $settings['preload_images'] : false );
		$fetchpriority     = $preload_enabled && $to_bool( isset( $settings['lcp_fetchpriority'] ) ? $settings['lcp_fetchpriority'] : false );

		$has_lazy_load_config = array_key_exists( 'lazy_load', $config );
		$is_lazy_load_empty   = $has_lazy_load_config && empty( $lazy_load );
		$is_lazy_load_enabled = $to_bool( isset( $settings['lazy_load'] ) ? $settings['lazy_load'] : false ) || $is_lazy_load_empty;
		$lazy_default_on      = $is_lazy_load_enabled && ( ! $has_lazy_load_config || $is_lazy_load_empty );

		$has_lazy_format = is_array( $lazy_load ) && array_key_exists( 'format', $lazy_load );
		$has_lazy_output = is_array( $lazy_load ) && array_key_exists( 'output', $lazy_load );

		$lazy_media_types_enabled = $is_lazy_load_enabled && $has_any_enabled_value(
			isset( $lazy_load['format'] ) ? $lazy_load['format'] : null,
			array( 'embed_video' ),
			$lazy_default_on || ! $has_lazy_format
		);

		$lazy_locations_enabled = $is_lazy_load_enabled && $has_any_enabled_value(
			isset( $lazy_load['output'] ) ? $lazy_load['output'] : null,
			array(),
			$lazy_default_on || ! $has_lazy_output
		);

		$lazy_excluded_content_enabled = false;
		$has_lazy_load_include_setting = is_array( $lazy_load ) && array_key_exists( 'include', $lazy_load );
		if ( $is_lazy_load_enabled && $has_lazy_load_include_setting && is_array( $lazy_load['include'] ) ) {
			foreach ( $lazy_load['include'] as $is_included ) {
				if ( ! $to_bool( $is_included ) ) {
					$lazy_excluded_content_enabled = true;
					break;
				}
			}
		} elseif ( $is_lazy_load_enabled && ! $has_lazy_load_include_setting ) {
			$lazy_excluded_content_enabled = $this->has_public_custom_post_types();
		}

		$image_sizes_setting = array_key_exists( 'image_sizes', $settings ) ? $settings['image_sizes'] : null;
		$image_sizes_enabled = true;
		if ( is_array( $image_sizes_setting ) ) {
			if ( empty( $image_sizes_setting ) ) {
				$image_sizes_enabled = false;
			} else {
				$normalized_sizes = array_filter(
					array_map(
						static function ( $item ) {
							return trim( (string) $item );
						},
						$image_sizes_setting
					)
				);
				$image_sizes_enabled = ! empty( $normalized_sizes );
			}
		} elseif ( array_key_exists( 'image_sizes', $settings ) && null !== $image_sizes_setting ) {
			$image_sizes_enabled = false === $image_sizes_setting ? true : $to_bool( $image_sizes_setting );
		}

		$display_array = array(
			'compression_level' => array(
				__( 'Basic 1X', 'wp-smushit' ) . ' - ' . $to_status( 'basic' === $compression_level ),
				__( 'Super 2X', 'wp-smushit' ) . ' - ' . $to_status( 'super' === $compression_level ),
				__( 'Ultra 5X', 'wp-smushit' ) . ' - ' . $to_status( 'ultra' === $compression_level ),
			),
			'image_format_type' => array(
				__( 'Original', 'wp-smushit' ) . ' - ' . $to_status( ! $to_bool( isset( $settings['webp_mod'] ) ? $settings['webp_mod'] : false ) && ! $to_bool( isset( $settings['avif_mod'] ) ? $settings['avif_mod'] : false ) ),
				__( 'WebP', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'webp_mod', $to_bool( isset( $settings['webp_mod'] ) ? $settings['webp_mod'] : false ) ),
				__( 'AVIF', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'avif_mod', $to_bool( isset( $settings['avif_mod'] ) ? $settings['avif_mod'] : false ) ),
			),
			'lazy_load' => array(
				__( 'Lazyload offscreen images', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled ),
				__( 'Preload critical images', 'wp-smushit' ) . ' - ' . $to_status( $preload_enabled ),
				__( 'Fetchpriority', 'wp-smushit' ) . ' - ' . $to_status( $fetchpriority ),
				__( 'Lazy loading appearance', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled ),
				__( 'Lazy loaded media types', 'wp-smushit' ) . ' - ' . $to_status( $lazy_media_types_enabled ),
				__( 'Lazy loaded locations', 'wp-smushit' ) . ' - ' . $to_status( $lazy_locations_enabled ),
				__( 'Youtube/Vimeo preview images', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled && $to_bool( isset( $lazy_load['format']['embed_video'] ) ? $lazy_load['format']['embed_video'] : false ) ),
				__( 'Excluded content', 'wp-smushit' ) . ' - ' . $to_status( $lazy_excluded_content_enabled ),
				__( 'Scripts', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled && $to_bool( isset( $lazy_load['footer'] ) ? $lazy_load['footer'] : false ) ),
				__( 'Native lazy load', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled && $to_bool( isset( $lazy_load['native'] ) ? $lazy_load['native'] : false ) ),
				__( 'Noscript fallback', 'wp-smushit' ) . ' - ' . $to_status( $is_lazy_load_enabled && $to_bool( isset( $lazy_load['noscript_fallback'] ) ? $lazy_load['noscript_fallback'] : false ) ),
			),
			'cdn' => array(
				__( 'Single location', 'wp-smushit' ) . ' - ' . $to_status( ! $cdn_enabled ),
				__( 'CDN network', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'cdn', $cdn_enabled ),
				sprintf( __( 'Supported Media Types: %s', 'wp-smushit' ), 'JPG | PNG | WEBP | GIF | AVIF' ) . ' - ' . $to_status( $cdn_enabled ),
				__( 'Background Images', 'wp-smushit' ) . ' - ' . $to_status( $cdn_enabled && $to_bool( isset( $settings['background_images'] ) ? $settings['background_images'] : false ) ),
				__( 'Dynamic resizing', 'wp-smushit' ) . ' - ' . $to_status( $cdn_enabled && $to_bool( isset( $settings['cdn_dynamic_sizes'] ) ? $settings['cdn_dynamic_sizes'] : false ) ),
				__( 'CDN file format', 'wp-smushit' ) . ' - ' . $to_status( $cdn_file_format ),
				__( 'REST API', 'wp-smushit' ) . ' - ' . $to_status( $cdn_enabled && $to_bool( isset( $settings['rest_api_support'] ) ? $settings['rest_api_support'] : false ) ),
			),
			'bulk_smush' => array(
				__( 'Automatic compression', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['auto'] ) ? $settings['auto'] : false ) ),
				__( 'Metadata', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['strip_exif'] ) ? $settings['strip_exif'] : false ) ),
				__( 'Optimize original images', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['original'] ) ? $settings['original'] : false ) ),
				__( 'Backup original images', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['backup'] ) ? $settings['backup'] : false ) ),
				__( 'Automatic resizing', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'auto_resizing', $to_bool( isset( $settings['auto_resizing'] ) ? $settings['auto_resizing'] : false ) ),
				__( 'Set image dimensions', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'image_dimensions', $to_bool( isset( $settings['image_dimensions'] ) ? $settings['image_dimensions'] : false ) ),
				__( 'Image sizes', 'wp-smushit' ) . ' - ' . $to_status( $image_sizes_enabled ),
				__( 'Resize large images', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['resize'] ) ? $settings['resize'] : false ) ),
				__( 'Disable scaled images', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['no_scale'] ) ? $settings['no_scale'] : false ) ),
				__( 'PNG to JPEG Conversion', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['png_to_jpg'] ) ? $settings['png_to_jpg'] : false ) ),
				__( 'Email Notification', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['background_email'] ) ? $settings['background_email'] : false ) ),
			),
			'integrations' => array(
				__( 'Gutenberg Support', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['gutenberg'] ) ? $settings['gutenberg'] : false ) ),
				__( 'WPBakery Page Builder', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['js_builder'] ) ? $settings['js_builder'] : false ) ),
				__( 'Gravity Forms', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['gform'] ) ? $settings['gform'] : false ) ),
				__( 'Amazon S3', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 's3', $to_bool( isset( $settings['s3'] ) ? $settings['s3'] : false ) ),
			),
			'settings' => array(
				__( 'Usage tracking', 'wp-smushit' ) . ' - ' . $to_status( $to_bool( isset( $settings['usage'] ) ? $settings['usage'] : false ) ),
			),
		);

		foreach ( $display_array as $name => $rows ) {
			$display_array[ $name ] = array_values( array_filter( $rows ) );
		}

		// Display only for multisites, if the setting exists.
		if ( is_multisite() && isset( $config['networkwide'] ) ) {
			$display_array['networkwide'] = $this->get_networkwide_settings_to_display( $config );
		}

		// Format the values to what's expected in front. A string within an array.
		array_walk(
			$display_array,
			function ( &$value ) {
				if ( ! is_string( $value ) ) {
					$value = implode( PHP_EOL, $value );
				}
				$value = array( $value );
			}
		);
		return $display_array;
	}

	protected function get_next_gen_settings_display_value( $config ) {
		return __( 'Inactive', 'wp-smushit' );
	}

	protected function format_config_description( $field_name, $field_description ) {
		return "{$field_name} - {$field_description}";
	}

	/**
	 * Formats the given fields that belong to the "settings" option.
	 *
	 * @since 3.8.5
	 *
	 * @param array $config The config to format.
	 * @param array $fields The fields to look for.
	 *
	 * @return array
	 */
	protected function get_settings_display_value( $config, $fields ) {
		$formatted_rows = array();

		$extra_labels = array(
			's3'        => __( 'Amazon S3', 'wp-smushit' ),
			'cdn'       => __( 'CDN', 'wp-smushit' ),
			'keep_data' => __( 'Keep Data On Uninstall', 'wp-smushit' ),
		);

		foreach ( $fields as $name ) {
			if ( isset( $config['settings'][ $name ] ) ) {
				$label = Settings::get_instance()->get_setting_data( $name, 'short-label' );

				if ( empty( $label ) ) {
					$label = ! empty( $extra_labels[ $name ] ) ? $extra_labels[ $name ] : $name;
				}

				if ( 'lossy' === $name ) {
					$formatted_rows[] = $label . ' - ' . $this->settings->get_lossy_level_label( $config['settings'][ $name ] );
					continue;
				}

				if ( Settings::get_next_gen_cdn_key() === $name ) {
					$formatted_rows[] = $label . ' - ' . $this->settings->get_cdn_next_gen_conversion_label( $config['settings'][ $name ] );
					continue;
				}

				$formatted_rows[] = $label . ' - ' . $this->format_boolean_setting_value( $name, $config['settings'][ $name ] );
			}
		}
		return $formatted_rows;
	}

	/**
	 * Formats the boolean settings that are either 'active' or 'inactive'.
	 * If the setting belongs to a pro feature and
	 * this isn't a pro install, we display it as 'inactive'.
	 *
	 * @since 3.8.5
	 *
	 * @param string  $name The setting's name.
	 * @param boolean $value The setting's value.
	 * @return string
	 */
	protected function format_boolean_setting_value( $name, $value ) {
		// Display the pro features as 'inactive' for free installs.
		if ( in_array( $name, $this->get_placeholder_features(), true ) ) {
			$value = false;
		}
		return $value ? __( 'Active', 'wp-smushit' ) : __( 'Inactive', 'wp-smushit' );
	}

	protected function get_lazy_preload_settings_to_display( $config ) {
		$is_lazy_load_active = ! empty( $config['settings']['lazy_load'] );
		if ( ! $is_lazy_load_active ) {
			return __( 'Inactive', 'wp-smushit' );
		}

		$formatted_rows = array();

		$formatted_rows[] = __( 'Lazy Load', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'lazy_load', $is_lazy_load_active );
		if ( $is_lazy_load_active ) {
			$formatted_rows = array_merge( $formatted_rows, $this->get_lazy_load_settings_to_display( $config ) );
		}

		$formatted_rows[] = __( 'Preload Critical Images', 'wp-smushit' ) . ' - ' . $this->format_boolean_setting_value( 'preload_images', false );

		return $formatted_rows;
	}

	/**
	 * Formats the given lazy_load settings to be displayed.
	 *
	 * @since 3.8.5
	 *
	 * @param array $config The config to format.
	 *
	 * @return array
	 */
	protected function get_lazy_load_settings_to_display( $config ) {
		$formatted_rows = array();

		// List of the available lazy load settings for this version and their labels.
		$settings_labels = array(
			'format'            => __( 'Media Types', 'wp-smushit' ),
			'output'            => __( 'Output Locations', 'wp-smushit' ),
			'auto_resizing'     => __( 'Auto Resizing', 'wp-smushit' ),
			'image_dimensions'  => __( 'Add Missing Image Dimensions', 'wp-smushit' ),
			'include'           => __( 'Included Post Types', 'wp-smushit' ),
			'animation'         => __( 'Display And Animation', 'wp-smushit' ),
			'footer'            => __( 'Load Scripts In Footer', 'wp-smushit' ),
			'native'            => __( 'Native Lazy Load Enabled', 'wp-smushit' ),
			'noscript_fallback' => __( 'Noscript Tag', 'wp-smushit' ),
		);

		foreach ( $settings_labels as $key => $label ) {
			// Skip if the setting doesn't exist.
			if ( isset( $config['lazy_load'][ $key ] ) ) {
				$value = $config['lazy_load'][ $key ];
			} elseif ( isset( $config['settings'][ $key ] ) ) {
				$value = $config['settings'][ $key ];
			} else {
				continue;
			}

			if ( 'format' === $key ) {
				$enabled_media_types = array_keys( array_filter( $value ) );
				$formatted_rows[]    = $this->get_lazy_load_media_types_to_display( $enabled_media_types );
				$formatted_rows[]    = $this->get_lazy_load_embedded_content_to_display( $enabled_media_types );
				continue;
			}

			$formatted_value = $label . ' - ';

			$setting_keys = array(
				'auto_resizing',
				'image_dimensions',
			);
			if ( in_array( $key, $setting_keys, true ) ) {
				$formatted_value .= $this->format_boolean_setting_value( $key, ! empty( $value ) );
			} elseif ( 'animation' === $key ) {
				// The special kid.
				$formatted_value .= __( 'Selected: ', 'wp-smushit' ) . $value['selected'];
				if ( ! empty( $value['fadein'] ) ) {
					$formatted_value .= __( '. Fade in duration: ', 'wp-smushit' ) . $value['fadein']['duration'];
					$formatted_value .= __( '. Fade in delay: ', 'wp-smushit' ) . $value['fadein']['delay'];
				}
			} elseif ( in_array( $key, array( 'footer', 'native', 'noscript_fallback' ), true ) ) {
				// Enabled/disabled settings.
				$formatted_value .= ! empty( $value ) ? __( 'Yes', 'wp-smushit' ) : __( 'No', 'wp-smushit' );

			} else {
				// Arrays.
				if ( in_array( $key, array( 'output', 'include' ), true ) ) {
					$value = array_keys( array_filter( $value ) );
				}

				if ( ! empty( $value ) ) {
					$formatted_value .= implode( ', ', $value );
				} else {
					$formatted_value .= __( 'none', 'wp-smushit' );
				}
			}

			$formatted_rows[] = $formatted_value;
		}

		return $formatted_rows;
	}

	private function get_lazy_load_media_types_to_display( $enabled_media_types ) {
		$formatted_value       = __( 'Media Types', 'wp-smushit' ) . ' - ';
		$embed_content_formats = array( 'iframe', 'embed_video' );
		$enabled_media_types   = array_diff( $enabled_media_types, $embed_content_formats );

		if ( empty( $enabled_media_types ) ) {
			$formatted_value .= __( 'none', 'wp-smushit' );
		} else {
			$formatted_value .= implode( ', ', $enabled_media_types );
		}

		return $formatted_value;
	}

	private function get_lazy_load_embedded_content_to_display( $enabled_media_types ) {
		$formatted_value = __( 'Embedded Content', 'wp-smushit' ) . ' - ';
		if ( ! in_array( 'iframe', $enabled_media_types, true ) ) {
			return $formatted_value . __( 'No', 'wp-smushit' );
		}

		if ( ! in_array( 'embed_video', $enabled_media_types, true ) ) {
			return $formatted_value .= __( 'Yes', 'wp-smushit' );
		}

		return $formatted_value . __( 'Replace Video Embed with preview images', 'wp-smushit' );
	}

	/**
	 * Formats the 'networkwide' setting to display.
	 *
	 * @since 3.8.5
	 *
	 * @param array $config The config to format.
	 *
	 * @return string
	 */
	private function get_networkwide_settings_to_display( $config ) {
		if ( is_array( $config['networkwide'] ) ) {
			return implode( ', ', $config['networkwide'] );
		}
		return '1' === (string) $config['networkwide'] ? __( 'All', 'wp-smushit' ) : __( 'None', 'wp-smushit' );
	}


	public function sanitize_and_format_configs( $configs ) {
		$configs = $this->normalize_configs( $configs );

		return array(
			'configs' => $this->sanitize_config( $configs ),
			'strings' => $this->format_config_to_display( $configs ),
		);
	}

	private function normalize_configs( $configs ) {
		if ( ! isset( $configs['settings'] ) ) {
			return $configs;
		}

		$settings = $configs['settings'];
		$settings = $this->maybe_migrate_auto_resize_to_new_settings( $settings );

		// Update settings.
		$configs['settings'] = $settings;

		return $configs;
	}

	/**
	 * Migrates the CDN auto_resize setting to the new auto_resizing
	 * and cdn_dynamic_sizes settings.
	 *
	 * @since 3.21.0
	 */
	private function maybe_migrate_auto_resize_to_new_settings( $settings ) {
		if ( isset( $settings['auto_resizing'] ) || isset( $settings['cdn_dynamic_sizes'] ) ) {
			return $settings;
		}

		$is_auto_resizing_active       = ! empty( $settings['auto_resize'] );
		$settings['auto_resizing']     = $is_auto_resizing_active;
		$settings['cdn_dynamic_sizes'] = $is_auto_resizing_active;

		return $settings;
	}

	/**
	 * Get the list of placeholder features.
	 *
	 * @return array
	 */
	protected function get_placeholder_features() {
		return $this->placeholder_features;
	}

	/**
	 * Whether the site has at least one public custom post type.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	private function has_public_custom_post_types() {
		$custom_post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'names'
		);

		return ! empty( $custom_post_types );
	}
}