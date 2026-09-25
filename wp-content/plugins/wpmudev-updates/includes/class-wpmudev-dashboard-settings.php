<?php
/**
 * Class that handles settings functionality.
 *
 * @link    https://wpmudev.com
 * @since   4.11.10
 * @author  Joel James <joel@incsub.com>
 * @package WPMUDEV_Dashboard_Settings
 */

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class WPMUDEV_Dashboard_Settings
 */
class WPMUDEV_Dashboard_Settings {

	/**
	 * Internal option keys.
	 *
	 * @var string[]
	 */
	private array $internal_options
		= array(
			'remote_access',
			'updates_data',
			'profile_data',
			'updates_available',
			'translation_updates_available',
			'notifications',
		);

	/**
	 * Set up actions for settings.
	 *
	 * @since 5.0.0
	 */
	public function __construct() {
		add_action( 'update_site_option_wdp_un_general', array( $this, 'sync_translation_update' ), 10, 3 );
	}

	/**
	 * Returns the value of a plugin option.
	 *
	 * This can handle both grouped item and single options.
	 * If group argument is omitted it will get the non-grouped option.
	 *
	 * @since 4.11.10
	 *
	 * @param string       $name          The option name.
	 * @param string|false $group         The group name (Optional for non grouped option).
	 * @param mixed        $default_value Optional. Set value to return if option not found.
	 *
	 * @return mixed The option value.
	 */
	public function get( $name, $group = false, $default_value = false ) {
		// Handle grouped options.
		if ( ! empty( $group ) ) {
			// Get option value.
			$value = $this->get_option( $group, array() );

			// Return value.
			return isset( $value[ $name ] ) ? $value[ $name ] : $default_value;
		}

		return $this->get_option( $name, $default_value );
	}

	/**
	 * Returns the value of a plugin option.
	 *
	 * The plugins option-prefix is automatically added to the option name.
	 * Use this function instead of direct access via get_site_option().
	 *
	 * @since 4.11.10
	 *
	 * @param string $name          The name of the option.
	 * @param mixed  $default_value Default value.
	 *
	 * @return mixed The option value.
	 */
	public function get_option( $name, $default_value = false ) {
		$value = get_site_option( 'wdp_un_' . $name, $default_value );

		/**
		 * Override the settings value.
		 *
		 * @since 5.0.0
		 */
		return apply_filters( 'wpmudev_dashboard_settings_get_option', $value, $name, $default_value );
	}

	/**
	 * Returns all settings in a grouped array.
	 *
	 * @since 5.0.0
	 *
	 * @param array $skip Skip some options.
	 *
	 * @return array
	 */
	public function get_all( $skip = array() ) {
		$settings = array();
		// Get the default settings.
		$defaults = $this->defaults();

		foreach ( $defaults as $group => $default_value ) {
			// Skip internal options.
			if ( ! empty( $skip ) && is_array( $skip ) && in_array( $group, $skip, true ) ) {
				continue;
			}

			$settings[ $group ] = $this->get_option( $group, $default_value );
		}

		return $settings;
	}

	/**
	 * Updates the value of a single plugin option.
	 *
	 * Can update a single item of a group or event an option without
	 * any group.
	 *
	 * @since 4.11.10
	 *
	 * @param string       $name  The option name.
	 * @param mixed        $value The new option value.
	 * @param string|false $group The group name (Optional for non grouped option).
	 *
	 * @return bool
	 */
	public function set( $name, $value, $group = false ) {
		// Handle grouped options.
		if ( ! empty( $group ) ) {
			// Get option value.
			$group_value = $this->get_option( $group, array() );
			// Merge values.
			$values = wp_parse_args(
				array( $name => $value ),
				$group_value
			);
		} else {
			$group  = $name;
			$values = $value;
		}

		$success = $this->set_option( $group, $values );

		if ( $success ) {
			/**
			 * Action hook to run after Dashboard option is updated.
			 *
			 * @since 4.11.22
			 *
			 * @param string $name  Name of the option.
			 * @param mixed  $value Value to update.
			 * @param string $group Group name.
			 */
			do_action( 'wpmudev_dashboard_settings_after_set', $name, $value, $group );
		}

		return $success;
	}

	/**
	 * Updates the value of a plugin option.
	 * The plugins option-prefix is automatically added to the option name.
	 *
	 * Use this function instead of direct access via update_site_option()
	 *
	 * @since 4.11.10
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The new option value.
	 *
	 * @return bool
	 */
	public function set_option( $name, $value ) {
		if ( empty( $name ) ) {
			return false;
		}

		// Return true early if updating value is same as old value.
		$old_value = $this->get_option( $name );
		if ( $value === $old_value ) {
			return true;
		}

		$success = update_site_option( 'wdp_un_' . $name, $value );

		if ( $success ) {
			/**
			 * Action hook to run after Dashboard option is updated.
			 *
			 * @since 4.11.22
			 *
			 * @param string $name  Name of the option.
			 * @param mixed  $value Value to update.
			 */
			do_action( 'wpmudev_dashboard_settings_after_set_option', $name, $value );
		}

		return $success;
	}

	/**
	 * Add a new plugin setting to the database.
	 *
	 * Can add a single item of a group or event an option without
	 * any group. If group name is omitted it will add as a single option.
	 *
	 * @since 4.11.10
	 *
	 * @param string       $name  The option name.
	 * @param mixed        $value The new option value.
	 * @param string|false $group The group name (Optional for non grouped option).
	 *
	 * @return bool
	 */
	public function add( $name, $value, $group = false ) {
		// Get existing value for grouped option.
		if ( ! empty( $group ) ) {
			$existing = $this->get_option( $group, array() );

			// Already exist, don't add.
			if ( isset( $existing[ $name ] ) ) {
				return false;
			}

			// Add new value.
			$existing[ $name ] = $value;

			// Update option.
			return $this->set_option( $group, $value );
		}

		return $this->add_option( $name, $value );
	}

	/**
	 * Add a new single plugin setting to the database.
	 *
	 * The plugins option-prefix is automatically added to the option name.
	 * This function will only save the value if the option does not exist yet!
	 * Use this function instead of direct access via add_site_option().
	 *
	 * @since 4.11.10
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The new option value.
	 *
	 * @return bool
	 */
	public function add_option( $name, $value ) {
		return add_site_option( 'wdp_un_' . $name, $value );
	}

	/**
	 * Returns the value of a plugin transient.
	 *
	 * The plugins option-prefix is automatically added to the transient name.
	 * Use this function instead of direct access via get_site_transient().
	 *
	 * @since 4.11.10
	 *
	 * @param string $name The transient name.
	 *
	 * @return mixed The transient value.
	 */
	public function get_transient( string $name ) {
		$key = 'wdp_un_' . $name;

		// Transient name cannot be longer than 167 characters
		// 150 is being safe.
		$key = substr( $key, 0, 150 );

		return get_site_transient( $key );
	}

	/**
	 * Updates the value of a plugin transient.
	 *
	 * The plugins option-prefix is automatically added to the transient name.
	 * Use this function instead of direct access via set_site_transient().
	 *
	 * @since 4.11.10
	 * @since 5.0.0     Returns delete transient result when value is null.
	 * @since 5.0.1     Remove $prefix parameter.
	 *
	 * @param string $name       The transient name.
	 * @param mixed  $value      The new transient value. Passing null as value will delete the transient.
	 * @param int    $expiration Time until expiration. Default: No expiration.
	 *
	 * @return bool
	 */
	public function set_transient( $name, $value, $expiration = 0 ) {
		$key = 'wdp_un_' . $name;

		// Transient name cannot be longer than 167 characters
		// 150 is being safe.
		$key = substr( $key, 0, 150 );

		// Fix to prevent WP from hashing PHP objects.
		$result = delete_site_transient( $key );

		if ( null !== $value ) {
			return set_site_transient( $key, $value, $expiration );
		}

		return $result;
	}

	/**
	 * Initialize all plugin options in the DB during activation.
	 *
	 * This function is called by the `activate_plugin` plugin in the main
	 * plugin file. Do not call this on every page load.
	 *
	 * @since 4.11.10
	 *
	 * @return void
	 */
	public function init() {
		// Get the default settings.
		$defaults = $this->defaults();

		foreach ( $defaults as $name => $value ) {
			// This is a grouped option.
			if ( is_array( $value ) ) {
				$update = false;
				// Get existing value.
				$existing = $this->get_option( $name, array() );
				// Go through each item and add if not found.
				foreach ( $value as $sub_name => $sub_value ) {
					if ( ! isset( $existing[ $sub_name ] ) ) {
						// Adding new value, so needs update.
						$update = true;
						// Set new value.
						$existing[ $sub_name ] = null === $sub_value ? '' : $sub_value;
					}
				}

				// If needs an update.
				if ( $update ) {
					$this->set_option( $name, $existing );
				}
			} else {
				$value = null === $value ? '' : $value;
				// Add only if doesn't exist.
				$this->add_option( $name, $value );
			}
		}
	}

	/**
	 * Reset all settings back to default.
	 *
	 * This will reset setting items found in the WPMUDEV_Dashboard_Settings::defaults() list.
	 * If the default value of a field is null we will skip it from reset.
	 *
	 * @since 4.11.10
	 *
	 * @return void
	 */
	public function reset() {
		// Get the default settings.
		$defaults = $this->defaults();

		foreach ( $defaults as $name => $value ) {
			// This is a grouped option.
			if ( is_array( $value ) && ! empty( $value ) ) {
				// Get existing value.
				$values = $this->get_option( $name, array() );
				// Go through each item and add if not found.
				foreach ( $value as $sub_name => $sub_value ) {
					if ( null !== $sub_value ) {
						// Set default value.
						$values[ $sub_name ] = $sub_value;
					}
				}

				// Update with new values.
				$this->set_option( $name, $values );
			} elseif ( null !== $value ) {
				$this->set_option( $name, $value );
			}
		}
	}

	/**
	 * Get the default settings values.
	 *
	 * This should be used to init and rest settings.
	 * To register new options use `wpmudev_dashboard_settings_defaults` filter.
	 * Only items registered here will be used for init and reset actions.
	 * If the value is null during reset it will be skipped.
	 *
	 * @since 4.11.10
	 *
	 * @return array
	 */
	public function defaults() {
		$settings = array(
			// Bigger options.
			// updates_data is excluded from reset, so any integration ( such as forminator addons ) can _still_ work-ish ( its stale, but its not nothing ).
			'remote_access'                 => '',
			'profile_data'                  => '',
			'updates_available'             => array(),
			'translation_updates_available' => array(),
			'notifications'                 => array(),
			// Whitelabel options.
			'whitelabel'                    => array(
				'enabled'                  => false,
				'branding_enabled'         => false,
				'branding_enabled_subsite' => false,
				'branding_type'            => 'default',
				'branding_image'           => '',
				'branding_image_id'        => 0,
				'branding_image_link'      => '',
				'footer_enabled'           => false,
				'footer_text'              => '',
				'labels_enabled'           => false,
				'labels_config'            => false,
				'labels_config_selected'   => '',
				'labels_networkwide'       => true,
				'labels_subsites'          => array(),
				'doc_links_enabled'        => false,
			),
			// Analytics options.
			'analytics'                     => array(
				'enabled'    => false,
				'tracker'    => '',
				'site_id'    => '',
				'script_url' => '',
				'metrics'    => array(
					'pageviews',
					'unique_pageviews',
					'page_time',
					'visits',
					'bounce_rate',
					'exit_rate',
				),
				'role'       => 'administrator',
			),
			// SSO.
			'sso'                           => array(
				'enabled'        => false,
				'userid'         => false,
				'previous_token' => '',
				'active_token'   => '',
				'step1_hmac'     => '',
			),
			'flags'                         => array(
				'refresh_remote'              => false,
				'refresh_profile'             => false,
				'redirected_v4'               => false,
				'highlights_dismissed'        => true,
				'first_setup'                 => false,
				'autoupdate_dashboard'        => true,
				'enable_auto_translation'     => false,
				'uninstall_preserve_settings' => true,
				'uninstall_keep_data'         => true,
			),
			// Data settings on uninstall.
			'data'                          => array(
				'preserve_settings' => true,
				'keep_data'         => true,
			),
			// Other small options.
			'general'                       => array(
				// last_run_updates is not going to reset, because we also not resetting updates_data,
				// its a pair, @see WPMUDEV_Dashboard_Api::refresh_projects_data().
				'last_run_profile'     => 0,
				'last_run_sync'        => 0,
				'last_run_translation' => 0,
				'staff_notes'          => '',
				'translation_locale'   => 'en_US',
				'version'              => WPMUDEV_Dashboard::$version,
				'limit_to_user'        => array(),
				'auth_user'            => null,
				'hub_nonce'            => 0,
				'connected_admin'      => 0,
			),
		);

		/**
		 * Filter to modify default settings.
		 *
		 * @since 4.11.10
		 *
		 * @param array $settings Default settings.
		 */
		return apply_filters( 'wpmudev_dashboard_settings_defaults', $settings );
	}

	/**
	 * Get multiple options into single array assoc.
	 *
	 * This function will match expectation structure,
	 * Array returned from this function should be predictable.
	 *
	 * @since 4.6.0
	 * @since 4.11.10 Moved to new class and renamed.
	 *
	 * @param array $options Optional array assoc with setting name as key.
	 *
	 * @return array
	 */
	public function as_array( $options = array() ) {
		$settings = array();

		foreach ( $options as $name => $item ) {
			// Make sure all required properties set.
			$item = wp_parse_args(
				$item,
				array(
					'option'  => '',
					'group'   => false,
					'type'    => false,
					'default' => false,
				)
			);

			// If option name is not given use default value.
			if ( empty( $item['option'] ) ) {
				$settings[ $name ] = $this->sanitize( $item['default'], $item['type'] );
				continue;
			}

			// Get the sanitized value.
			$settings[ $name ] = $this->sanitize(
				$this->get( $item['option'], $item['group'], $item['default'] ),
				$item['type']
			);
		}

		return $settings;
	}

	/**
	 * Get internal option keys.
	 *
	 * @return array
	 */
	public function get_internal_options() {
		return $this->internal_options;
	}

	/**
	 * Upgrade old settings to new grouped structure.
	 *
	 * A few items won't be upgraded because it is still in old
	 * structure without any group.
	 *
	 * @since 4.11.10
	 *
	 * @return void
	 */
	public function upgrade_41110() {
		$mapping = $this->deprecated_mappings();

		// Upgrade old options.
		foreach ( $mapping as $name => $item ) {
			// Get the old value.
			$value = $this->get_option( $name, null );
			// If value found.
			if ( null !== $value ) {
				// Delete old option.
				delete_site_option( 'wdp_un_' . $name );

				// Set group.
				$group = isset( $item['group'] ) ? $item['group'] : false;

				// Set new name.
				if ( isset( $item['name'] ) ) {
					$name = $item['name'];
				}

				// Set new option.
				$this->set( $name, $value, $group );
			}
		}

		// Unused options cleanup.
		$deprecated_options = array( 'farm133_themes', 'last_check_upfront' );

		foreach ( $deprecated_options as $option ) {
			delete_site_option( 'wdp_un_' . $option );
		}
	}

	/**
	 * Get the mapping for a deprecated option name.
	 *
	 * This is here only for backward compatibility.
	 *
	 * @since      4.11.10
	 *
	 * @param string $name Field name.
	 *
	 * @return array
	 * @deprecated 4.11.10 For backward compatibility only.
	 */
	public function deprecated_get_field_mapping( $name ) {
		$mapping = $this->deprecated_mappings();

		$mapped = array(
			'name'  => $name,
			'group' => false,
		);

		// If old option has new structure.
		if ( isset( $mapping[ $name ] ) ) {
			if ( isset( $mapping[ $name ]['name'] ) ) {
				$mapped['name'] = $mapping[ $name ]['name'];
			}

			if ( isset( $mapping[ $name ]['group'] ) ) {
				$mapped['group'] = $mapping[ $name ]['group'];
			}
		}

		return $mapped;
	}

	/**
	 * Make changes when translation settings are updated.
	 *
	 * @param string $option    Name of the network option.
	 * @param mixed  $value     Current value of the network option.
	 * @param mixed  $old_value Old value of the network option.
	 *
	 * @return void
	 */
	public function sync_translation_update( $option, $value, $old_value ) {
		$old_locale = $old_value['translation_locale'] ?? 'en_US';
		$new_locale = $value['translation_locale'] ?? $old_locale;

		// Force a hub-sync, since the translation setting changed.
		if ( $old_locale !== $new_locale ) {
			WPMUDEV_Dashboard::$api->calculate_translation_upgrades( true );
		}
	}

	/**
	 * Get the mapping for deprecated option names.
	 *
	 * This is here for backward compatibility. Few of our plugins
	 * still use the old option names.
	 * If some of the options are not found here, which means they are
	 * still in old form.
	 *
	 * @since      4.11.10
	 * @return array
	 * @deprecated 4.11.10 For backward compatibility only.
	 */
	private function deprecated_mappings() {
		return array(
			'limit_to_user'                       => array(
				'group' => 'general',
			),
			'last_run_updates'                    => array(
				'group' => 'general',
			),
			'last_run_profile'                    => array(
				'group' => 'general',
			),
			'last_run_sync'                       => array(
				'group' => 'general',
			),
			'staff_notes'                         => array(
				'group' => 'general',
			),
			'translation_locale'                  => array(
				'group' => 'general',
			),
			'auth_user'                           => array(
				'group' => 'general',
			),
			'version'                             => array(
				'group' => 'general',
			),
			'redirected_v4'                       => array(
				'group' => 'flags',
			),
			'refresh_remote_flag'                 => array(
				'group' => 'flags',
			),
			'refresh_profile_flag'                => array(
				'group' => 'flags',
			),
			'autoupdate_dashboard'                => array(
				'group' => 'flags',
			),
			'enable_auto_translation'             => array(
				'group' => 'flags',
			),
			'highlights_dismissed'                => array(
				'group' => 'flags',
			),
			'first_setup'                         => array(
				'group' => 'flags',
			),
			'data_preserve_settings'              => array(
				'name'  => 'uninstall_preserve_settings',
				'group' => 'flags',
			),
			'data_keep_data'                      => array(
				'name'  => 'uninstall_keep_data',
				'group' => 'flags',
			),
			'whitelabel_enabled'                  => array(
				'name'  => 'enabled',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_enabled'         => array(
				'name'  => 'branding_enabled',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_enabled_subsite' => array(
				'name'  => 'branding_enabled_subsite',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_type'            => array(
				'name'  => 'branding_type',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_image'           => array(
				'name'  => 'branding_image',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_image_id'        => array(
				'name'  => 'branding_image_id',
				'group' => 'whitelabel',
			),
			'whitelabel_branding_image_link'      => array(
				'name'  => 'branding_image_link',
				'group' => 'whitelabel',
			),
			'whitelabel_footer_enabled'           => array(
				'name'  => 'footer_enabled',
				'group' => 'whitelabel',
			),
			'whitelabel_footer_text'              => array(
				'name'  => 'footer_text',
				'group' => 'whitelabel',
			),
			'whitelabel_labels_enabled'           => array(
				'name'  => 'labels_enabled',
				'group' => 'whitelabel',
			),
			'whitelabel_labels_config'            => array(
				'name'  => 'labels_config',
				'group' => 'whitelabel',
			),
			'whitelabel_labels_config_selected'   => array(
				'name'  => 'labels_config_selected',
				'group' => 'whitelabel',
			),
			'whitelabel_labels_networkwide'       => array(
				'name'  => 'labels_networkwide',
				'group' => 'whitelabel',
			),
			'whitelabel_labels_subsites'          => array(
				'name'  => 'labels_subsites',
				'group' => 'whitelabel',
			),
			'whitelabel_doc_links_enabled'        => array(
				'name'  => 'doc_links_enabled',
				'group' => 'whitelabel',
			),
			'analytics_enabled'                   => array(
				'name'  => 'enabled',
				'group' => 'analytics',
			),
			'analytics_tracker'                   => array(
				'name'  => 'tracker',
				'group' => 'analytics',
			),
			'analytics_site_id'                   => array(
				'name'  => 'site_id',
				'group' => 'analytics',
			),
			'analytics_metrics'                   => array(
				'name'  => 'metrics',
				'group' => 'analytics',
			),
			'analytics_role'                      => array(
				'name'  => 'role',
				'group' => 'analytics',
			),
			'enable_sso'                          => array(
				'name'  => 'enabled',
				'group' => 'sso',
			),
			'sso_userid'                          => array(
				'name'  => 'userid',
				'group' => 'sso',
			),
			'previous_sso_token'                  => array(
				'name'  => 'previous_token',
				'group' => 'sso',
			),
			'active_sso_token'                    => array(
				'name'  => 'active_token',
				'group' => 'sso',
			),
		);
	}

	/**
	 * Sanitize given value to match a type.
	 *
	 * If not one of possible type, value won't be sanitized.
	 *
	 * @since 4.11.10
	 *
	 * @param mixed        $value Value to sanitize.
	 * @param string|false $type  Type of value.
	 *
	 * @return mixed
	 */
	private function sanitize( $value, $type = false ) {
		switch ( $type ) {
			case 'boolean':
				$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				break;
			case 'string':
				// String expected, when its `empty`(NULL, false, etc) or its not string lets return empty string.
				if ( empty( $value ) || ! is_string( $value ) ) {
					$value = '';
				}
				break;
			case 'numeric':
				// numeric expected, its safe to return "0.7" even the explicit type is string
				// since PHP will auto coerce the type naturally.
				if ( ! is_numeric( $value ) ) {
					$value = 0;
				}
				break;
			case 'array':
				if ( ! is_array( $value ) ) {
					// Only array please.
					$value = empty( $value ) ? array() : (array) $value;
				}
				break;
		}

		return $value;
	}

	/**
	 * Upgrade to version 5.0.0
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function upgrade_500() {
		// clean up hub_nonce from options.
		$general = $this->get_option( 'general' );
		$general = is_array( $general ) ? $general : array();

		$update = false;
		foreach ( $general as $key => $value ) {
			if ( str_starts_with( $key, 'hub_nonce_' ) ) {
				unset( $general[ $key ] );
				$update = true;
			}
		}

		if ( $update ) {
			$this->set_option( 'general', $general );
		}

		if ( did_action( 'init' ) ) {
			$this->upgrade_500_on_init();
		} else {
			add_action( 'init', array( $this, 'upgrade_500_on_init' ) );
		}
	}

	/**
	 * Upgrade to version 5.0.0 on init.
	 * The plugin upgrades executed on plugins_loaded hook,
	 * but calculate upgrades need to be done on or after init hook, because it contains user check and translations
	 * hence, this function is separated from the usual data/options update
	 *
	 * @return void
	 */
	public function upgrade_500_on_init() {
		// re-calculate upgrades, added new property `is_addon`.
		WPMUDEV_Dashboard::$api->calculate_upgrades();
	}
}