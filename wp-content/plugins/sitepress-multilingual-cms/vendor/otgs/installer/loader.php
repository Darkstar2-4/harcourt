<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'otgs_is_rest_request' ) ) {
	function otgs_is_rest_request() {
		$rest_url_prefix = 'wp-json';

		if ( function_exists( 'rest_get_url_prefix' ) ) {
			$rest_url_prefix = rest_get_url_prefix();
		}

		return array_key_exists( 'rest_route', $_REQUEST ) || false !== strpos( $_SERVER['REQUEST_URI'], $rest_url_prefix ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}
}

if ( file_exists( __DIR__ . '/../../otgs/icons/loader.php' ) ) {
	$vendor_root_url = plugins_url( '/', __FILE__ )  . '../..';
	require_once __DIR__ . '/../../otgs/icons/loader.php';
}

global $otgs_installer_version;
$otgs_installer_version = '3.1.14';

if ( ! function_exists( 'wpml_installer_force_load' ) ) {
	function wpml_installer_force_load() {
		if ( function_exists( 'OTGS_Installer' ) ) {
			return \OTGS_Installer();
		}

		global $otgs_installer_version;
		$delegate = [ 'version' => $otgs_installer_version, 'bootfile' => dirname( __FILE__ ) . '/installer.php' ];

		include_once $delegate['bootfile'];
		include_once dirname( __FILE__ ) . '/includes/functions-core.php';

		return \OTGS_Installer();
	}
}

$is_cron_request   = defined( 'DOING_CRON' ) && DOING_CRON;
$is_wp_cli_request = defined( 'WP_CLI' ) && WP_CLI;

if ( ! $is_cron_request && ! $is_wp_cli_request && ! is_admin() && ! otgs_is_rest_request() ) {
	if ( ! function_exists( 'WP_Installer_Setup' ) ) {
		function WP_Installer_Setup( $wp_installer_instance, $args = [] ) {
			if ( isset( $args['site_key_nags'][0]['repository_id'] ) ) {
				require_once __DIR__ . '/includes/class-otgs-installer-settings.php';
				require_once __DIR__ . '/includes/class-otgs-installer-subscription.php';

				$settings = OTGS\Installer\Settings::load_subscriptions();

				$repository_id = $args['site_key_nags'][0]['repository_id'];

				$getSiteKey    = function () use ( $repository_id, $settings ) {
					if ( in_array( $repository_id, [ 'wpml', 'toolset' ] )
					     && isset( $settings['repositories'][ $repository_id ]['subscription']['key'] )
					) {
						return $settings['repositories'][ $repository_id ]['subscription']['key'];
					}

					return null;
				};

				add_filter( 'otgs_installer_get_sitekey_'.$repository_id, $getSiteKey );

				if ( in_array( $repository_id, [ 'wpml', 'toolset' ] )
				     && isset( $settings['repositories'][ $repository_id ]['subscription']['key_type'] )
				     && $settings['repositories'][ $repository_id ]['subscription']['key_type'] === OTGS_Installer_Subscription::SITE_KEY_TYPE_DEVELOPMENT
				) {

					$showFrontendBanner = function () use ( $repository_id ) {
						$removeFrontendBannerLink = 'https://wpml.org/faq/how-to-remove-the-this-site-is-registered-on-wpml-org-as-a-development-site-notice/?utm_source=plugin&utm_medium=gui&utm_campaign=wpml-core&utm_term=footer-notice';
						$wpmlText = sprintf(
							__( 'This site is registered on %s as a development site. Switch to a production site key to %s.', 'installer' ),
							'<a href="https://wpml.org">wpml.org</a>', '<a href="' . $removeFrontendBannerLink . '">remove this banner</a>'
						);
						$message = $repository_id === 'wpml'
							? $wpmlText
							: __( 'This site is registered on Toolset.com as a development site.', 'installer' );

						?>
						<style>
                            .otgs-development-site-front-end a { color: white; }
                            .otgs-development-site-front-end .icon {
                                background: url(<?php echo plugins_url( '/', __FILE__ ) . '/res/img/icon-wpml-info-white.svg'; ?>) no-repeat;
                                width: 20px;
                                height: 20px;
                                display: inline-block;
                                position: absolute;
                                margin-left: -23px;
                            }
                            .otgs-development-site-front-end {
                                background-size: 32px;
                                padding: 22px 0px;
                                font-size: 12px;
                                font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
                                line-height: 18px;
                                text-align: center;
                                color: white;
                                background-color: #0369a1;
                            }
						</style>
						<?php
						echo '<div class="otgs-development-site-front-end"><span class="icon"></span>' . $message . '</div >';
					};

					add_action( 'wp_footer', $showFrontendBanner, 999 );
				}
			}
		}
	}
	$wp_installer_instance = null;

	return;
}

$wp_installer_instance = dirname( __FILE__ ) . '/installer.php';

global $wp_installer_instances;
$wp_installer_instances[ $wp_installer_instance ] = [
	'bootfile' => $wp_installer_instance,
	'version'  => $otgs_installer_version,
];

if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '<' ) ) {
	foreach ( $wp_installer_instances as $key => $instance ) {
		if ( isset( $instance['args']['site_key_nags'] ) ) {
			$wp_installer_instances[ $key ]['version'] = '9.9';
		} else {
			$wp_installer_instances[ $key ]['version'] = '0';
		}
	}
}

if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.3.1', '<' ) ) {

	$installer_171_plus_on = false;
	foreach ( $wp_installer_instances as $key => $instance ) {
		if ( version_compare( $instance['version'], '1.7.1', '>=' ) ) {
			$installer_171_plus_on = true;
			break;
		}
	}

	if ( $installer_171_plus_on ) {
		foreach ( $wp_installer_instances as $key => $instance ) {

			if ( version_compare( $instance['version'], '1.7.0', '<' ) ) {
				unset( $wp_installer_instances[ $key ] );
			}
		}
	}
}

if ( isset( $_POST['installer_instance'] ) && isset( $wp_installer_instances[ $_POST['installer_instance'] ] ) ) {
	$wp_installer_instances[ $_POST['installer_instance'] ]['version'] = '999';
}

remove_action( 'after_setup_theme', 'wpml_installer_instance_delegator', 1 );
add_action( 'after_setup_theme', 'wpml_installer_instance_delegator', 1 );

if ( ! function_exists( 'wpml_installer_instance_delegator' ) ) {
	function wpml_installer_instance_delegator() {
		global $wp_installer_instances;

		$delegated_instance_key = null;
		foreach ( $wp_installer_instances as $instance_key => $instance ) {
			$wp_installer_instances[ $instance_key ]['delegated'] = false;

			if ( ! isset( $delegate ) || version_compare( $instance['version'], $delegate['version'], '>' ) ) {
				$delegate               = $instance;
				$delegated_instance_key = $instance_key;
			}
		}

		$wp_installer_instances[ $delegated_instance_key ]['delegated'] = true;

		$highest_priority = null;
		foreach ( $wp_installer_instances as $instance ) {
			if ( isset( $instance['args']['high_priority'] ) ) {
				if ( is_null( $highest_priority ) || $instance['args']['high_priority'] <= $highest_priority ) {
					$highest_priority = $instance['args']['high_priority'];
					$delegate         = $instance;
				}
			}
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && version_compare( ICL_SITEPRESS_VERSION, '3.2', '<' ) ) {
			foreach ( $wp_installer_instances as $key => $instance ) {
				if ( isset( $instance['args']['site_key_nags'] ) ) {
					$delegate               = $instance;
					$wp_installer_instances = array( $key => $delegate );
					break;
				}
			}
		}

		include_once $delegate['bootfile'];

		require_once dirname( __FILE__ ) . '/includes/loader/Config.php';
		$delegate = OTGS\Installer\Loader\Config::merge( $delegate, $wp_installer_instances );

		$template_path = realpath( get_template_directory() );
		if ( $template_path && strpos( (string) realpath( $delegate['bootfile'] ), (string) $template_path ) === 0 ) {
			$filepath = str_replace( (string) realpath( get_template_directory() ), '', (string) realpath( $delegate['bootfile'] ) );
			$delegate['args']['in_theme_folder'] = dirname( ltrim( $filepath, '\\/' ) );
		}

		if ( isset( $delegate['args'] ) && is_array( $delegate['args'] ) ) {
			foreach ( $delegate['args'] as $key => $value ) {
				WP_Installer()->set_config( $key, $value );
			}
		}

	}
}

if ( ! function_exists( 'WP_Installer_Setup' ) ) {

	function WP_Installer_Setup( $wp_installer_instance, $args = array() ) {
		global $wp_installer_instances;

		if ( $wp_installer_instance ) {
			$wp_installer_instances[ $wp_installer_instance ]['args'] = $args;
		}
	}
}
