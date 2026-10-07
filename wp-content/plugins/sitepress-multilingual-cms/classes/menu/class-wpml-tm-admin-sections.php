<?php

use WPML\FP\Relation;

class WPML_TM_Admin_Sections {
	private $tab_items = array();

	private $admin_sections = array();
	private $items_urls = array();

	public function init_hooks() {
		add_action( 'init', [ $this, 'init_sections' ] );
	}

	public function init_sections() {
		foreach ( $this->get_admin_sections() as $section ) {
			$this->tab_items[ $section->get_slug() ] = [
				'caption'          => $section->get_caption(),
				'current_user_can' => $section->get_capabilities(),
				'callback'         => $section->get_callback(),
				'order'            => $section->get_order(),
			];
			if ( method_exists( $section, 'get_description' ) ) {
				$this->tab_items[ $section->get_slug() ]['description'] = $section->get_description();
			}
			add_action( 'admin_enqueue_scripts', [ $section, 'admin_enqueue_scripts' ] );
		}
	}

	private function get_admin_sections() {
		if ( ! $this->admin_sections ) {
			foreach ( $this->get_admin_section_factories() as $factory ) {
				if ( in_array( 'IWPML_TM_Admin_Section_Factory', class_implements( $factory ), true ) ) {
					$sections_factory = new $factory();
					$section = $sections_factory->create();

					if ( $section && in_array( 'IWPML_TM_Admin_Section', class_implements( $section ), true ) && $section->is_visible() ) {
						$this->admin_sections[ $section->get_slug() ] = $section;
					}
				}
			}
		}

		return $this->admin_sections;
	}

	public function get_tab_items() {
		return $this->tab_items;
	}

	private function get_admin_section_factories() {
		$admin_sections_factories = array(
			WPML_TM_Translation_Roles_Section_Factory::class,
			WPML_TM_AMS_ATE_Console_Section_Factory::class,
		);

		return apply_filters( 'wpml_tm_admin_sections_factories', $admin_sections_factories );
	}

	public function get_item_url( $slug ) {
		if ( $this->get_section( $slug ) ) {
			if ( ! array_key_exists( $slug, $this->items_urls ) ) {
				$this->items_urls[ $slug ] = admin_url( 'admin.php?page=' . WPML_TM_FOLDER . WPML_Translation_Management::PAGE_SLUG_MANAGEMENT . '&sm=' . $slug );
			}

			return $this->items_urls[ $slug ];
		}

		return '';
	}

	public function get_section( $slug ) {
		$sections = $this->get_admin_sections();
		if ( array_key_exists( $slug, $sections ) ) {
			return $sections[ $slug ];
		}

		return null;
	}

	public static function is_translation_roles_section() {
		return self::is_section( 'translators' );
	}

	public static function is_translation_services_section() {
		return self::is_section( 'translation-services' );
	}

	public static function is_dashboard_section() {
		return self::is_section( 'dashboard' );
	}

	private static function is_section( $section ) {
		return Relation::propEq( 'page', 'tm/menu/main.php', $_GET ) &&
		       Relation::propEq( 'sm', $section, $_GET );
	}
}
