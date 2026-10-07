<?php

function update_wpml_config_index_event() {
	global $sitepress;
	$http               = new WP_Http();
	$log                = new WPML_Config_Update_Log();
	$update_wpml_config = new WPML_Config_Update( $sitepress, $http, $log );
	return $update_wpml_config->run();
}
