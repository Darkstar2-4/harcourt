<?php

class WPML_Active_Plugin_Provider {
	public function get_active_plugins() {
		$active_plugin_names = array();
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				if ( is_plugin_active( $plugin_file ) ) {
					$active_plugin_names[] = $plugin_data;
				}
			}
		}

		return $active_plugin_names;
	}

	public function get_active_plugin_names() {
		return wp_list_pluck( $this->get_active_plugins(), 'Name' );
	}
}
