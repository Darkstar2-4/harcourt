<?php

interface IWPML_TM_Admin_Section {

	public function get_order();

	public function get_slug();

	public function get_capabilities();

	public function get_caption();

	public function get_callback();

	public function admin_enqueue_scripts( $hook );

	public function is_visible();
}
