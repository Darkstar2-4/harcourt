<?php

use WPML\Core\Component\MinimumRequirements\Domain\Value\RequirementsConfig;

class WPML_Support_Info_UI {
	protected $support_info;
	private $template_service;

	function __construct( WPML_Support_Info $support_info, IWPML_Template_Service $template_service ) {
		$this->support_info     = $support_info;
		$this->template_service = $template_service;
	}

	public function show() {
		$model = $this->get_model();

		return $this->template_service->show( $model, 'main.twig' );
	}

	protected function get_model() {


		$php_version        = $this->support_info->get_php_version();
		$php_memory_limit   = $this->support_info->get_php_memory_limit();
		$memory_usage       = $this->support_info->get_memory_usage();
		$max_execution_time = $this->support_info->get_max_execution_time();
		$max_input_vars     = $this->support_info->get_max_input_vars();

		$blocks = array(
			'php' => array(
				'strings' => array(
					'title' => __( 'PHP', 'sitepress' ),
				),
				'data'    => array(
					'version'            => array(
						'label'      => __( 'Version', 'sitepress' ),
						'value'      => $php_version,
						'url'        => 'http://php.net/supported-versions.php',
						'messages'   => array(
							sprintf( __( 'PHP %1$s and above are recommended.', 'sitepress' ), RequirementsConfig::MINIMUM_PHP_VERSION ) => 'https://wpml.org/home/minimum-requirements/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmlcore',
							__( 'Find how you can update PHP.', 'sitepress' )                                                                                                                                   => 'https://wordpress.org/support/update-php/',
						),
					),
					'memory_limit'       => array(
						'label'    => __( 'Memory limit', 'sitepress' ),
						'value'    => $php_memory_limit,
						'url'      => 'http://php.net/manual/ini.core.php#ini.memory-limit',
					),
					'memory_usage'       => array(
						'label' => __( 'Memory usage', 'sitepress' ),
						'value' => $memory_usage,
						'url'   => 'http://php.net/memory-get-usage',
					),
					'max_execution_time' => array(
						'label' => __( 'Max execution time', 'sitepress' ),
						'value' => $max_execution_time,
						'url'   => 'http://php.net/manual/info.configuration.php#ini.max-execution-time',
					),
					'max_input_vars'     => array(
						'label' => __( 'Max input vars', 'sitepress' ),
						'value' => $max_input_vars,
						'url'   => 'http://php.net/manual/info.configuration.php#ini.max-input-vars',
					),
					'utf8mb4_charset'    => array(
						'label'    => __( 'Utf8mb4 charset', 'sitepress' ),
						'value'    => $this->support_info->is_utf8mb4_charset_supported() ? __( 'Yes' ) : __( 'No' ),
						'url'      => 'https://dev.mysql.com/doc/refman/5.5/en/charset-unicode-utf8mb4.html',
						'messages' => array(
							__( 'Some WPML String Translation features may not work correctly without utf8mb4 character support.', 'sitepress' ) => 'https://wpml.org/home/minimum-requirements/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmlcore',

						),
						'is_error' => ! $this->support_info->is_utf8mb4_charset_supported(),
					),
				),
			),
			'wp'  => array(
				'strings' => array(
					'title' => __( 'WordPress', 'sitepress' ),
				),
				'data'    => array(
					'wp_version'       => array(
						'label'    => __( 'Version', 'sitepress' ),
						'value'    => $this->support_info->get_wp_version(),
						'messages' => array(
							sprintf( __( 'WordPress %s or later is required.', 'sitepress' ), RequirementsConfig::MINIMUM_WP_VERSION ) => 'https://wpml.org/home/minimum-requirements/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmlcore',
						)
					),
					'multisite'        => array(
						'label' => __( 'Multisite', 'sitepress' ),
						'value' => $this->support_info->get_wp_multisite() ? __( 'Yes' ) : __( 'No' ),
					),
					'WP_MEMORY_LIMIT'     => array(
						'label'    =>'WP_MEMORY_LIMIT',
						'value'    => $this->support_info->get_wp_memory_limit(),
						),
					'WP_MAX_MEMORY_LIMIT' => array(
						'label'    => 'WP_MAX_MEMORY_LIMIT',
						'value'    => $this->support_info->get_wp_max_memory_limit(),
					),
					'rest_enabled'     => array(
						'label'    => __( 'REST enabled', 'sitepress' ),
						'value'    => wpml_is_rest_enabled(false) ? __( 'Yes' ) : __( 'No' ),
					),
				),
			),
		);

		if ( $this->support_info->is_suhosin_active() ) {
			$blocks['php']['data']['eval_suhosin'] = array(
				'label'    => __( 'eval() availability from Suhosin', 'sitepress' ),
				'value'    => $this->support_info->eval_disabled_by_suhosin() ? __( 'Not available', 'sitepress' ) : __( 'Available', 'sitepress' ),
			);
		}
		if ( version_compare( PHP_VERSION, '8.3', '>=' ) ) {
			$max_stack = ini_get( 'zend.max_allowed_stack_size' );
			$reserved_stack = ini_get( 'zend.reserved_stack_size' );

			$max_stack_bytes      = $this->support_info->return_bytes( $max_stack );
			$reserved_stack_bytes = $this->support_info->return_bytes( $reserved_stack );

			$result = $this->calculate_stack_size_display( $max_stack_bytes, $reserved_stack_bytes );
			$available_stack_display = $result['display'];
			$available_stack_too_low = $result['too_low'];

			$messages = array();
			if ( $available_stack_too_low ) {
				$messages[__( 'WPML needs at least 208 KB of Available stack size on PHP 8.3+; please set "zend.max_allowed_stack_size" to at least 256 KB and "zend.reserved_stack_size" to at least 48 KB in your php.ini file. After these changes, restart your web server.', 'sitepress')] =
					'https://wpml.org/home/minimum-requirements/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmlcore';
			}

			$new_data = [];
			foreach ( $blocks['php']['data'] as $key => $item ) {
				$new_data[ $key ] = $item;

				if ( 'version' === $key ) {
					$new_data['available_stack_size'] = array(
						'label'      => __( 'Available Stack Size', 'sitepress' ),
						'value'      => $available_stack_display,
						'messages'   => $messages,
						'is_error'   => $available_stack_too_low,
					);
				}
			}
			$blocks['php']['data'] = $new_data;
		}

		$blocks = apply_filters( 'wpml_support_info_blocks', $blocks );

		$this->set_has_messages( $blocks, 'is_error' );
		$this->set_has_messages( $blocks, 'is_warning' );

		$model = array(
			'title'  => __( 'Info', 'sitepress' ),
			'blocks' => $blocks,
		);

		return $model;
	}

	public function calculate_stack_size_display($max_stack_bytes, $reserved_stack_bytes) {
		$min_max_stack = 262144;
		$min_reserved_stack = 49152;
		$min_available_stack = $min_max_stack - $min_reserved_stack;

		$max_stack_unlimited = $max_stack_bytes === 0 || $max_stack_bytes === -1;
		$reserved_stack_unlimited = $reserved_stack_bytes === 0;

		if ( $max_stack_unlimited ) {
			$available_stack_display = __( 'Automatic', 'sitepress' );
			$available_stack_too_low = false;
		} else {
			$available_stack_bytes = ( $reserved_stack_unlimited ) ?
				$max_stack_bytes :
				$max_stack_bytes - $reserved_stack_bytes;

			$available_stack_display = number_format_i18n( $available_stack_bytes / 1024 ) . ' KB';
			$available_stack_too_low = $available_stack_bytes < $min_available_stack;
		}

		return [
			'display' => $available_stack_display,
			'too_low' => $available_stack_too_low
		];
	}

	private function set_has_messages( array &$blocks, $type ) {
		foreach ( $blocks as $id => $content ) {
			if ( ! array_key_exists( 'has_messages', $content ) ) {
				$content['has_messages'] = false;
			}
			foreach ( (array) $content['data'] as $key => $item_data ) {
				if ( array_key_exists( $type, $item_data ) && (bool) $item_data[ $type ] ) {
					$content['has_messages'] = true;
					break;
				}
			}
			$blocks[ $id ] = $content;
		}
	}
}
