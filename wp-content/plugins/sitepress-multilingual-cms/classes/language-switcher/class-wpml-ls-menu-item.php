<?php

#[AllowDynamicProperties]
class WPML_LS_Menu_Item {

	public $ID;

	public $attr_title;

	public $aria_label;

	public $aria_expanded;

	public $aria_controls;

	public $link_role = '';

	public $item_role = '';

	public $classes = array();

	public $db_id;

	public $description;

	public $menu_item_parent;

	public $object = 'wpml_ls_menu_item';

	public $object_id;

	public $post_parent;

	public $post_title;

	public $target;

	public $title;

	public $type = 'wpml_ls_menu_item';

	public $type_label;

	public $url;

	public $xfn;

	public $_invalid = false;
	public $menu_order;

	public $post_type = 'nav_menu_item';

	public function __construct( $language, $item_content ) {
		$this->decorate_object( $language, $item_content );
	}

	private function decorate_object( $lang, $item_content ) {
		$this->ID               = isset( $lang['db_id'] ) ? $lang['db_id'] : null;
		$this->object_id        = isset( $lang['db_id'] ) ? $lang['db_id'] : null;
		$this->db_id            = isset( $lang['db_id'] ) ? $lang['db_id'] : null;
		$this->menu_item_parent = isset( $lang['menu_item_parent'] ) ? $lang['menu_item_parent'] : null;

		$is_current_lang       = isset( $lang['is_current'] ) ? $lang['is_current'] : null;
		$is_dropdown_ls_parent = isset( $lang['is_parent'] ) ? $lang['is_parent'] : false;
		$ls_menu_item_label    = ! $is_current_lang ? $lang['menu_item_label'] : '';

		$this->aria_label = $ls_menu_item_label;
		$this->attr_title = $ls_menu_item_label;

		if ( $is_dropdown_ls_parent ) {
			$this->aria_expanded = 'false';
			$this->aria_controls = 'wpml-ls-submenu-' . ( isset( $lang['db_id'] ) ? $lang['db_id'] : 'default' );
		}

		$this->title      = $item_content;
		$this->post_title = $item_content;
		$this->url        = isset( $lang['url'] ) ? $lang['url'] : null;

		if ( isset( $lang['css_classes'] ) ) {
			$this->classes = $lang['css_classes'];
			if ( is_string( $lang['css_classes'] ) ) {
				$this->classes = explode( ' ', $lang['css_classes'] );
			}
		}
	}

	public function __get( $property ) {
		return isset( $this->{$property} ) ? $this->{$property} : null;
	}
}
