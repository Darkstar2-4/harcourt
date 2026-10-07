<?php

use OTGS\Installer\Settings;

class WP_Installer_API{

    public static function get_product_installer_link($repository_id, $package_id = false){

        $menu_url = WP_Installer()->menu_url();

        $url = $menu_url . '#' . $repository_id;
        if($package_id){
            $url .= '/' . $package_id;
        }

        return $url;

    }

    public static function get_product_price($repository_id, $package_id, $product_id, $incl_discount = false){

        $price = WP_Installer()->get_product_price($repository_id, $package_id, $product_id, $incl_discount);

        return $price;
    }

    public static function get_preferred_ts($repository_id = 'wpml'){
		$ts_info = Settings::load_ts_info();

        if(isset($ts_info['repositories'][$repository_id]['ts_info']['preferred'])){
            return $ts_info['repositories'][$repository_id]['ts_info']['preferred'];
        }

        return false;

    }

    public static function set_preferred_ts( $value, $repository_id = 'wpml' ){
		$installer = WP_Installer::instance();
		$settings = $installer->get_settings();

        if( isset( $settings['repositories'][$repository_id]['ts_info']['preferred'] ) ){

            $settings['repositories'][$repository_id]['ts_info']['preferred'] = $value;

            $installer->save_settings( $settings );

        }

    }

    public static function get_ts_referal( $repository_id = 'wpml' ) {

        if(isset(WP_Installer()->settings['repositories'][$repository_id]['ts_info']['referal'])){
            return WP_Installer()->settings['repositories'][$repository_id]['ts_info']['referal'];
        }

        return false;

    }

    public static function get_ts_client_id( $repository_id = 'wpml' ){

        if(isset(WP_Installer()->settings['repositories'][$repository_id]['ts_info']['client_id'])){
            return WP_Installer()->settings['repositories'][$repository_id]['ts_info']['client_id'];
        }

        return false;

    }

    public static function get_site_key( $repository_id = 'wpml' ){

        return WP_Installer()->get_site_key( $repository_id );

    }

	public static function get_registering_user_id( $repository_id = 'wpml' ){

		$user_id = 0;
		if( isset( WP_Installer()->settings['repositories'][$repository_id]['subscription']['registered_by'] ) ){
			$user_id = WP_Installer()->settings['repositories'][$repository_id]['subscription']['registered_by'];
		}

		return $user_id;
	}
}
