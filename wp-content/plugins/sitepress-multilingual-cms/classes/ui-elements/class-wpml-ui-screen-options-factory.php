<?php

class WPML_UI_Screen_Options_Factory {

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function create_pagination( $option_name, $default_per_page ) {
		$pagination = new WPML_UI_Screen_Options_Pagination( $option_name, $default_per_page );
		$pagination->init_hooks();

		return $pagination;
	}

	public function create_help_tab( $id, $title, $content ) {
		if ( is_callable( $title ) ) {
			$title = $title();
		}
		if ( is_callable( $content ) ) {
			$content = $content();
		}
		$help_tab = new WPML_UI_Help_Tab( $this->sitepress->get_wp_api(), $id, $title, $content );
		$help_tab->init_hooks();

		return $help_tab;
	}

	public function create_admin_table_sort() {
		return new WPML_Admin_Table_Sort();
	}
}
