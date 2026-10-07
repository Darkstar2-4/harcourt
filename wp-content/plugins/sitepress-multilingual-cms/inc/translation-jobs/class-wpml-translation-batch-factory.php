<?php

class WPML_Translation_Batch_Factory {

	public function create( $id ) {
		global $sitepress;

		$wpdb = $sitepress->get_wpdb();
		return new WPML_Translation_Batch( $wpdb, $id );
	}
}
