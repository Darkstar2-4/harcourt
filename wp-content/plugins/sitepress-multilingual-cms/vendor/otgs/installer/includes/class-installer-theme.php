<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class Installer_Theme_Class {

    private $theme_repo;

    private $repository_api;

    private $repository_theme_products;

    private $installer_site_url;

    private $installer_site_key;

    protected $installer_themes_option;

    protected $installer_themes_available_updates;

    protected $installer_themes = array();

    protected $installer_repo_with_themes;

    protected $installer_theme_active_tab;

    protected $theme_user_registration;

    protected $installer_theme_subscription_type;

    public function __construct() {


        $installer_repositories = WP_Installer()->get_repositories();

        $repos_with_themes = $this->installer_theme_reposities_that_has_themes( $installer_repositories );

        if ( is_array( $repos_with_themes ) ) {
            $this->installer_repo_with_themes = $repos_with_themes;

            foreach ( $repos_with_themes as $k => $repo ) {

                $this->theme_repo[] = $repo;

	            if ( isset( $installer_repositories[ $repo ]['api-url'] ) ) {
		            $products_manager = WP_Installer()->get_products_manager();
                    $this->repository_api[$repo] = $installer_repositories[$repo]['api-url'];
		            $this->installer_site_url[$repo] = WP_Installer()->get_installer_site_url( $repo );
		            $this->installer_site_key[$repo] = WP_Installer()->get_site_key( $repo );
		            $this->repository_theme_products[ $repo ] = $products_manager->get_products_url(
			            $repo,
			            $this->installer_site_key[ $repo ],
			            $this->installer_site_url[ $repo ],
			            false
		            );


		            $this->theme_user_registration[$repo] = false;

                    if ( WP_Installer()->repository_has_valid_subscription( $repo ) ) {

                        $this->installer_theme_subscription_type = WP_Installer()->get_subscription_type_for_repository( $repo );
                        $this->installer_themes_option[$repo] = 'wp_installer_' . $repo . '_themes';
                        $this->installer_themes_available_updates[$repo] = 'wp_installer_' . $repo . '_updated_themes';
                        $this->installer_theme_active_tab = '';

                        $this->installer_theme_available( $repo, $this->installer_theme_subscription_type );

                        add_action( 'installer_themes_support_set_up', array($this, 'installer_theme_sets_active_tab_on_init'), 10 );
                        $this->theme_user_registration[$repo] = true;
                    }

                    $this->init();
                }
            }
            add_action( 'installer_themes_support_set_up', array($this, 'installer_theme_loaded_hooks') );
        }
    }

    public function init() {
        add_action( 'admin_enqueue_scripts', array($this, 'installer_theme_enqueue_scripts') );
        add_filter( 'themes_api', array($this, 'installer_theme_api_override'), 10, 3 );
        add_filter( 'themes_api_result', array($this, 'installer_theme_api_override_response'), 10, 3 );
        add_filter( 'site_transient_update_themes', array($this, 'installer_theme_upgrade_check'), 10, 1 );
        add_action( 'http_api_debug', array($this, 'installer_theme_sync_native_wp_api'), 10, 5 );
        add_filter( 'installer_theme_hook_response_theme', array($this, 'installer_theme_add_num_ratings'), 10, 1 );
        add_filter( 'themes_update_check_locales', array($this, 'installer_theme_sync_call_wp_theme_api'), 10, 1 );
        add_filter( 'admin_url', array($this, 'installer_theme_add_query_arg_tab'), 10, 3 );
        add_filter( 'network_admin_url', array($this, 'installer_theme_add_query_arg_tab'), 10, 2 );
        add_action( 'wp_ajax_installer_theme_frontend_selected_tab', array($this, 'installer_theme_frontend_selected_tab'), 0 );
        add_action( 'wp_loaded', array($this, 'installer_themes_support_set_up_func') );
    }

    public function installer_theme_enqueue_scripts() {
        $current_screen = $this->installer_theme_current_screen();
        $commercial_plugin_screen = $this->installer_theme_is_commercial_plugin_screen( $current_screen );
        if ( ('theme-install' == $current_screen) || ($commercial_plugin_screen) || ('theme-install-network' == $current_screen) ) {
            $repo_with_themes = $this->installer_repo_with_themes;
            $js_array = array();
            if ( is_array( $repo_with_themes ) ) {
                foreach ( $repo_with_themes as $k => $v ) {

                    $theme_repo_name = $this->installer_theme_get_repo_product_name( $v );
                    $the_hyperlink_text = esc_js( $theme_repo_name );

                    if ( is_multisite() ) {
                        $admin_url_passed = network_admin_url();
                    } else {
                        $admin_url_passed = admin_url();
                    }

                    $js_array[$v] = array(
                        'the_hyperlink_text' => $the_hyperlink_text,
                        'registration_status' => $this->theme_user_registration[$v],
                        'is_commercial_plugin_tab' => $commercial_plugin_screen,
                        'registration_url' => $admin_url_passed . 'plugin-install.php?tab=commercial#installer_repo_' . $v
                    );

                }
            }

            if ( !(empty($js_array)) ) {
	            wp_register_script( 'otgs-purify', WP_Installer()->res_url() . '/dist/js/domPurify/app.js', [], WP_Installer()->version() );
	            wp_enqueue_script( 'installer-theme-install', WP_Installer()->res_url() . '/res/js/installer_theme_install.js', [
		            'jquery',
		            'otgs-purify'
	            ], WP_Installer()->version() );
                $installer_ajax_url = admin_url( 'admin-ajax.php' );

                if ( is_ssl() ) {
                    $installer_ajax_url = str_replace( 'http://', 'https://', $installer_ajax_url );
                } else {
                    $installer_ajax_url = str_replace( 'https://', 'http://', $installer_ajax_url );
                }

                $subscription_js_check = $this->installer_theme_subscription_does_not_have_theme( $js_array );

                wp_localize_script( 'installer-theme-install', 'installer_theme_install_localize',
                    array(
                        'js_array_installer' => $js_array,
                        'ajaxurl' => $installer_ajax_url,
                        'no_associated_themes' => $subscription_js_check,
                        'installer_theme_frontend_selected_tab_nonce' => wp_create_nonce( 'installer_theme_frontend_selected_tab' )
                    )
                );
            }
        }
    }

    protected function installer_theme_subscription_does_not_have_theme( $js_array ) {

        $any_subscription_has_theme = array();
        $number_of_registrations = array();

        foreach ( $js_array as $repo_slug => $js_details ) {

            if ( isset($this->theme_user_registration[$repo_slug]) ) {
                $registration_status = $this->theme_user_registration[$repo_slug];
                if ( $registration_status ) {

                    $number_of_registrations[] = $repo_slug;

                    $themes_available = false;
                    if ( isset($this->installer_themes[$repo_slug]) ) {
                        $themes_available = $this->installer_themes[$repo_slug];
                        if ( !(empty($themes_available)) ) {
                            $themes_available = true;
                        }
                    }

                    if ( $themes_available ) {
                        $any_subscription_has_theme[] = $repo_slug;
                    }
                }
            }

        }

        if ( empty( $registration_status ) ) {
            return false;
        }

		if ( empty($any_subscription_has_theme) ) {
			return true;
		}

		return false;
    }

    private function installer_theme_is_commercial_plugin_screen( $current_screen ) {
        $commercial = false;
        if ( ('plugin-install' == $current_screen) || ('plugin-install-network' == $current_screen) ) {
            if ( isset($_GET['tab']) ) {
                $tab = sanitize_text_field( $_GET['tab'] );
                if ( 'commercial' == $tab ) {
                    $commercial = true;
                }
            }
        }
        return $commercial;
    }

    private function installer_theme_current_screen() {

        $current_screen_loaded = false;

        if ( function_exists( 'get_current_screen' ) ) {

            $screen_output = get_current_screen();
            $current_screen_loaded = $screen_output->id;

        }

        return $current_screen_loaded;

    }

    public function installer_theme_api_override( $api_boolean, $action, $args ) {

        if ( isset($args->browse) ) {
            $browse = $args->browse;
            if ( in_array( $browse, $this->theme_repo ) ) {
                if ( 'query_themes' == $action ) {
                    $api_boolean = true;
                }
            }
        } elseif ( isset($args->slug) ) {
            $theme_to_install = $args->slug;

            $validate_check = $this->installer_themes_belong_to_us( $theme_to_install );
            if ( $validate_check ) {
                if ( !(empty($theme_to_install)) ) {
                    $api_boolean = true;
                }
            }
        }

        return $api_boolean;
    }

    public function installer_theme_api_override_response( $res, $action, $args ) {
        if ( true === $res ) {
            if ( isset($args->browse) ) {
                $browse = $args->browse;
                if ( in_array( $browse, $this->theme_repo ) ) {
                    if ( 'query_themes' == $action ) {
                        if ( isset($this->theme_user_registration[$browse]) ) {
                            if ( !($this->theme_user_registration[$browse]) ) {
                                $res = new stdClass();
                                $res->info = array();
                                $res->themes = array();
                                return $res;
                            } else {
                                $themes = $this->installer_theme_get_themes( '', $browse );
                                $res = $this->installer_theme_format_response( $themes, $action );
                            }
                        }
                    }
                }
            } elseif ( isset($args->slug) ) {
                $theme_to_install = $args->slug;

                $validate_check = $this->installer_themes_belong_to_us( $theme_to_install );
                if ( $validate_check ) {
                    if ( ($res) && ('theme_information' == $action) ) {
                        $themes = $this->installer_theme_get_themes( '', $this->installer_theme_active_tab );
                        $res = $this->installer_theme_format_response( $themes, $action, $args->slug );
                    }
                }
            }
        }

	    return $res;
    }

    private function installer_theme_get_themes( $product_url = '', $repository_id  = '' ) {

        if ( empty($product_url) ) {
            if ( isset($this->repository_theme_products[$this->installer_theme_active_tab]) ) {
                $query_remote_url = $this->repository_theme_products[$this->installer_theme_active_tab];
            }

        } else {
            $query_remote_url = $product_url;
        }

        $current_installer_settings = WP_Installer()->get_settings();

        $themes = false;

        if ( (is_array( $current_installer_settings )) && (!(empty($current_installer_settings))) ) {

            if ( isset($current_installer_settings['repositories'][$repository_id]['data']) ) {
                $products = $current_installer_settings['repositories'][$repository_id]['data'];
                if ( isset($products['downloads']['themes']) ) {
                    $themes = $products['downloads']['themes'];
                }
            }

        } else {

            $response = wp_remote_get( $query_remote_url );

            if ( is_wp_error( $response ) ) {
                $query_remote_url = preg_replace( "@^https://@", 'http://', $query_remote_url );
                $response = wp_remote_get( $query_remote_url );
            }

            if ( !(is_wp_error( $response )) ) {
                if ( $response && isset($response['response']['code']) && $response['response']['code'] == 200 ) {
                    $body = wp_remote_retrieve_body( $response );
                    if ( $body ) {
                        $products = json_decode( $body, true );
                        if ( isset($products['downloads']['themes']) ) {
                            $themes = $products['downloads']['themes'];
                        }
                    }

                }
            }
        }

        return apply_filters( 'installer_theme_get_themes', $themes, $this->installer_theme_active_tab );
    }

    private function installer_theme_format_response( $themes, $action, $slug = '' ) {

        if ( ('theme_information' == $action) && (!(empty($slug))) ) {

            foreach ( $themes as $k => $theme ) {
                if ( $slug == $theme['basename'] ) {
                    $theme['download_link'] = WP_Installer()->append_site_key_to_download_url( $theme['url'], $this->installer_site_key[$this->installer_theme_active_tab], $this->installer_theme_active_tab );
                    $theme = json_decode( (string) json_encode( $theme ), false );
                    return $theme;
                }
            }

        } else {

            $res = new stdClass();
            $res->info = array();
            $res->themes = array();

            $res->info['page'] = 1;
            $res->info['pages'] = 10;

            $res->info['results'] = count( $themes );

            $this->installer_theme_savethemes_by_slug( $themes );

            if ( isset($this->installer_theme_subscription_type) ) {
                $this->installer_theme_available( $this->installer_theme_active_tab, $this->installer_theme_subscription_type );
            } else {
                $this->installer_theme_available( $this->installer_theme_active_tab );
            }

            $theme_compatible_array=array();
            if ((is_array($themes))) {
            	foreach ($themes as $k=>$v) {
            		$theme_compatible_array[]=(object)($v);
            	}
            }
            $res->themes = $theme_compatible_array;
            $res->themes = apply_filters( 'installer_theme_hook_response_theme', $res->themes );
            return $res;
        }
    }

    private function installer_theme_savethemes_by_slug( $themes, $doing_query = false ) {

        if ( !($doing_query) ) {
            $this->installer_themes[$this->installer_theme_active_tab] = array();
        }

        if ( !(empty($themes)) ) {
            $themes_for_saving = array();
            foreach ( $themes as $k => $theme ) {
                if ( !($doing_query) ) {
                    if ( isset($theme['slug']) ) {
                        $theme_slug = $theme['slug'];
                        if ( !(empty($theme_slug)) ) {
                            $themes_for_saving[] = $theme_slug;
                        }
                    }
                } else {

                    if ( ((isset($theme['slug'])) && (isset($theme['version'])) &&
                            (isset($theme['theme_page_url']))) && (isset($theme['url']))
                    ) {
                        $theme_slug = $theme['slug'];
                        $theme_version = $theme['version'];
                        $theme_page_url = $theme['theme_page_url'];
                        $theme_url = $theme['url'];
                        if ( (!(empty($theme_slug))) && (!(empty($theme_version))) &&
                            (!(empty($theme_page_url))) && (!(empty($theme_url)))
                        ) {
                            $themes_for_saving[$theme_slug] = array(
                                'version' => $theme_version,
                                'theme_page_url' => $theme_page_url,
                                'url' => $theme_url
                            );

                        }
                    }
                }

            }

            if ( !(empty($themes_for_saving)) ) {
                if ( !($doing_query) ) {
                    $existing_themes = get_option( $this->installer_themes_option[$this->installer_theme_active_tab] );
                    if ( !($existing_themes) ) {
                        delete_option( $this->installer_themes_option[$this->installer_theme_active_tab] );
                        update_option( $this->installer_themes_option[$this->installer_theme_active_tab], $themes_for_saving );
                    } else {
                        if ( $existing_themes == $themes_for_saving ) {
                        } else {
                            delete_option( $this->installer_themes_option[$this->installer_theme_active_tab] );
                            update_option( $this->installer_themes_option[$this->installer_theme_active_tab], $themes_for_saving );
                        }
                    }
                } else {
                    return $themes_for_saving;
                }
            }
        }
    }

    private function installer_theme_available( $repo, $subscription_type = '' ) {

        $subscription_type = intval( $subscription_type );
        if ( $subscription_type > 0 ) {

            $themes_associated_with_subscription = $this->installer_themes[$repo] = $this->installer_theme_get_themes_by_subscription( $subscription_type, $repo );
            if ( !(empty($themes_associated_with_subscription)) ) {
                $this->installer_themes[$repo] = $themes_associated_with_subscription;
            }
        } else {

            $this->installer_themes[$repo] = get_option( $this->installer_themes_option[$repo] );
        }
    }

    public function installer_theme_upgrade_check( $the_value ) {

        if ( (is_array( $this->installer_repo_with_themes )) && (!(empty($this->installer_repo_with_themes))) ) {
            foreach ( $this->installer_repo_with_themes as $k => $repo_slug ) {
	            if ( is_array( $this->installer_themes_available_updates ) && isset( $this->installer_themes_available_updates[ $repo_slug ] ) ) {
		            $update_available = get_option( $this->installer_themes_available_updates[ $repo_slug ] );
	            } else {
		            $update_available = false;
	            }

	            if ( $update_available ) {
                    if ( is_array( $update_available ) ) {
                        foreach ( $update_available as $theme_slug => $v ) {
                            $the_value->response [$theme_slug] = array(
                                'theme' => $theme_slug,
                                'new_version' => $v['new_version'],
                                'url' => $v['url'],
                                'package' => $v['package']
                            );
                        }
                    }
                }
            }
        }
        return $the_value;
    }

    private function installer_theme_reposities_that_has_themes( $repositories, $ret_value = true, $doing_api_query = false ) {

        $repositories_with_themes = array();

        if ( (is_array( $repositories )) && (!(empty($repositories))) ) {

            $themes = get_option( 'installer_repositories_with_theme' );

            if ( (!($themes)) || ($doing_api_query) ) {
	            foreach ( $repositories as $repository_id => $repository_data ) {
		            $products_manager = WP_Installer()->get_products_manager();
		            $product_url      = $products_manager->get_products_url(
			            $repository_id,
			            WP_Installer()->get_site_key( $repository_id ),
			            WP_Installer()->get_installer_site_url( $repository_id ),
			            false
		            );
		            if ( $product_url ) {
			            $themes = $this->installer_theme_get_themes( $product_url, $repository_id );
			            if ( ( is_array( $themes ) ) && ( ! ( empty( $themes ) ) ) ) {
				            $repositories_with_themes[] = $repository_id;
			            }
		            }
	            }
            } else {
                $repositories_with_themes = $themes;
            }

            if ( (((is_array( $repositories_with_themes )) && (!(empty($repositories_with_themes)))) && (!($themes))) || ($doing_api_query) ) {
                update_option( 'installer_repositories_with_theme', $repositories_with_themes );
            }
        }

        if ( $ret_value ) {
            return $repositories_with_themes;
        }

    }

    public function installer_theme_sync_native_wp_api( $response, $responsetext, $class, $args, $url ) {

        $api_native_string = 'api.wordpress.org/themes/';
        if ( (strpos( $url, $api_native_string ) !== false) ) {
            $installer_repositories = WP_Installer()->get_repositories();

            $this->installer_theme_reposities_that_has_themes( $installer_repositories, false, true );
        }
    }

    private function installer_theme_get_repo_product_name( $theme_repo ) {

        $theme_repo_name = false;

        if ( isset(WP_Installer()->settings['repositories'][$theme_repo]['data']['product-name']) ) {
            $prod_name = WP_Installer()->settings['repositories'][$theme_repo]['data']['product-name'];
            if ( !(empty($prod_name)) ) {
                $theme_repo_name = $prod_name;
            }
        } else {
            if ( $theme_repo == $this->theme_repo ) {
                $result = $this->installer_theme_general_api_query();
                if ( isset($result['product-name']) ) {
                    $product_name = $result['product-name'];
                    if ( !(empty($product_name)) ) {
                        $theme_repo_name = $product_name;
                    }
                }
            }
        }

        return $theme_repo_name;
    }

    private function installer_theme_general_api_query() {
        $products = false;
        $response = wp_remote_get( $this->repository_theme_products );
        if ( !(is_wp_error( $response )) ) {
            if ( $response && isset($response['response']['code']) && $response['response']['code'] == 200 ) {
                $body = wp_remote_retrieve_body( $response );
                if ( $body ) {
                    $result = json_decode( $body, true );
                    if ( (is_array( $result )) && (!(empty($result))) ) {
                        $products = $result;
                    }
                }

            }
        }

        return $products;
    }

    private function installer_themes_belong_to_us( $theme_slug ) {

        $found = false;
        $theme_slug = trim( $theme_slug );

        foreach ( $this->installer_themes as $repo_with_theme => $themes ) {
            foreach ( $themes as $k => $otgs_theme_slug ) {
                if ( $theme_slug == $otgs_theme_slug ) {
                    return true;
                }
            }
        }
        return $found;

    }

    public function installer_theme_sets_active_tab_on_init() {

        if ( isset ($_SERVER ['REQUEST_URI']) ) {
            $request_uri = $_SERVER ['REQUEST_URI'];
            if ( isset ($_GET ['browse']) ) {
                $active_tab = sanitize_text_field( $_GET['browse'] );
                $this->installer_theme_active_tab = $active_tab;
            } elseif ( isset ($_POST ['request'] ['browse']) ) {
                $active_tab = sanitize_text_field ( $_POST['request']['browse'] );
                $this->installer_theme_active_tab = $active_tab;
            } elseif ( (isset ($_GET ['theme_repo'])) && (isset ($_GET ['action'])) ) {
                $theme_repo = sanitize_text_field( $_GET['theme_repo'] );
                $the_action = sanitize_text_field( $_GET['action'] );
                if ( ('install-theme' == $the_action) && (!(empty($theme_repo))) ) {
                    $this->installer_theme_active_tab = $theme_repo;
                }
            } elseif ( wp_get_referer() ) {
                $referer = wp_get_referer();
                $parts = parse_url( $referer );
                if ( isset($parts['query']) ) {
                    parse_str( $parts['query'], $query );
                    if ( isset($query['browse']) ) {
                        $this->installer_theme_active_tab = $query['browse'];
                    }
                }
            }
        }
    }

    public function installer_theme_add_num_ratings( $themes ) {

        if ( (is_array( $themes )) && (!(empty($themes))) ) {
            foreach ( $themes as $k => $v ) {
                if ( !(isset($v->num_ratings)) ) {
                    $themes[$k]->num_ratings = 100;
                }
                if ( !(isset($v->rating)) ) {
                	$themes[$k]->rating = 100;
                }
            }
        }

        return $themes;
    }

    public function installer_theme_sync_call_wp_theme_api( $locales ) {

        $this->installer_theme_upgrade_theme_check();

        return $locales;
    }

    private function installer_theme_upgrade_theme_check() {

        $installed_themes = wp_get_themes();

        foreach ( $this->installer_repo_with_themes as $k => $repo_slug ) {

            $products_url = $this->repository_theme_products [$repo_slug];

            $available_themes = $this->installer_theme_get_themes( $products_url, $repo_slug );

            if ( !($available_themes) ) {

                return;
            } else {

                $simplified_available_themes = $this->installer_theme_savethemes_by_slug( $available_themes, true );

                if ( (is_array( $installed_themes )) && (!(empty ($installed_themes))) ) {
                    $otgs_theme_updates_available = array();
                    foreach ( $installed_themes as $theme_slug => $theme_object ) {
                        if ( array_key_exists( $theme_slug, $simplified_available_themes ) ) {

                            $local_version = $theme_object->get( 'Version' );

                            $repository_version = $simplified_available_themes [$theme_slug] ['version'];
                            $theme_page_url = $simplified_available_themes [$theme_slug] ['theme_page_url'];
                            $theme_download_url = $simplified_available_themes [$theme_slug] ['url'];

                            if ( version_compare( $repository_version, $local_version, '>' ) ) {

                                $package_url = WP_Installer()->append_site_key_to_download_url( $theme_download_url, $this->installer_site_key [$repo_slug], $repo_slug );

                                $otgs_theme_updates_available[$theme_slug] = array(
                                    'theme' => $theme_slug,
                                    'new_version' => $repository_version,
                                    'url' => $theme_page_url,
                                    'package' => $package_url
                                );
                            }
                        }
                    }
                    if ( !empty($otgs_theme_updates_available) ) {
	                    if ( is_array( $this->installer_themes_available_updates ) && isset( $this->installer_themes_available_updates[ $repo_slug ] ) ) {
		                    update_option( $this->installer_themes_available_updates[ $repo_slug ], $otgs_theme_updates_available );
	                    }
                    } else {
	                    if ( is_array( $this->installer_themes_available_updates ) && isset( $this->installer_themes_available_updates[ $repo_slug ] ) ) {
		                    delete_option( $this->installer_themes_available_updates[ $repo_slug ] );
	                    }
                    }
                }
            }
        }
    }

    public function installer_theme_add_query_arg_tab( $url, $path, $blog_id = null ) {

        $wp_install_string = 'update.php?action=install-theme';
        if ( $path == $wp_install_string ) {
            if ( isset($this->installer_theme_active_tab) ) {
                if ( !(empty($this->installer_theme_active_tab)) ) {
                    $url = add_query_arg( array(
                        'theme_repo' => $this->installer_theme_active_tab
                    ), $url );
                }
            }
        }
        return $url;
    }

    public function installer_theme_frontend_selected_tab() {
        if ( isset($_POST["frontend_tab_selected"]) ) {
            check_ajax_referer( 'installer_theme_frontend_selected_tab', 'installer_theme_frontend_selected_tab_nonce' );

            $frontend_tab_selected = sanitize_text_field( $_POST['frontend_tab_selected'] );
            if ( !(empty($frontend_tab_selected)) ) {
                update_option( 'wp_installer_clientside_active_tab', $frontend_tab_selected, false );

                if ( isset($this->theme_user_registration[$frontend_tab_selected]) ) {
                    if ( !($this->theme_user_registration[$frontend_tab_selected]) ) {

                        if ( is_multisite() ) {
                            $admin_url_passed = network_admin_url();
                        } else {
                            $admin_url_passed = admin_url();
                        }

                        $registration_url = $admin_url_passed . 'plugin-install.php?tab=commercial#installer_repo_' . $frontend_tab_selected;

                        $theme_repo_name = $this->installer_theme_get_repo_product_name( $frontend_tab_selected );;
                        $response['unregistered_messages'] = sprintf( __( 'To install and update %s, please %sregister%s %s for this site.', 'installer' ),
                            $theme_repo_name, '<a href="' . $registration_url . '">', '</a>', $theme_repo_name );

                    }
                }

                $response['output'] = $frontend_tab_selected;
                echo json_encode( $response );
            }
            die();
        }
        die();
    }

    public function installer_theme_loaded_hooks() {

        if ( isset($this->installer_theme_subscription_type) ) {
            $subscription_type = intval( $this->installer_theme_subscription_type );
            if ( $subscription_type > 0 ) {
                add_filter( 'installer_theme_get_themes', array($this, 'installer_theme_filter_themes_by_subscription'), 10, 2 );
            }
        }

    }

    protected function installer_theme_get_themes_by_subscription( $subscription_type, $repo ) {

        $themes_associated_with_subscription = array();
        if ( isset(WP_Installer()->settings['repositories'][$repo]['data']['packages']) ) {
            $packages = WP_Installer()->settings['repositories'][$repo]['data']['packages'];
            $available_themes_subscription = array();
            foreach ( $packages as $package_id => $package_details ) {
                if ( isset($package_details['products']) ) {
                    $the_products = $package_details['products'];
                    foreach ( $the_products as $product_slug => $product_details ) {
                        if ( isset($product_details['subscription_type']) ) {
                            $subscription_type_from_settings = intval( $product_details['subscription_type'] );
                            if ( $subscription_type_from_settings == $subscription_type ) {
                                if ( isset($product_details['themes']) ) {
                                    $themes_associated_with_subscription = $product_details['themes'];
                                    return $themes_associated_with_subscription;
                                }
                            }
                        }

                    }
                }
            }
        }
        return $themes_associated_with_subscription;
    }

    public function installer_theme_filter_themes_by_subscription( $themes, $active_tab ) {

        $orig = is_array( $themes ) ? count( $themes ) : 0;
        if ( in_array( $active_tab, $this->theme_repo ) ) {
            if ( isset($this->installer_themes[$active_tab]) ) {
                $available_themes = $this->installer_themes[$active_tab];
                if ( (is_array( $themes )) && (!(empty($themes))) ) {
                    foreach ( $themes as $k => $theme ) {
                        if ( isset($theme['slug']) ) {
                            $theme_slug = $theme['slug'];
                            if ( !(empty($theme_slug)) ) {
                                if ( !(in_array( $theme_slug, $available_themes )) ) {
                                    unset($themes[$k]);
                                }
                            }
                        }
                    }
                }
            }
        }
        $new = is_array( $themes ) ? count( $themes ) : 0;
        if ( $orig != $new ) {
            $themes = array_values( $themes );
        }

        return $themes;
    }

    public function installer_themes_support_set_up_func() {
        do_action( 'installer_themes_support_set_up' );
    }

}

new Installer_Theme_Class;
