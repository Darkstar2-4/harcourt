<?php

use WPML\TranslationRoles\UI\Initializer;
use WPML\TM\Menu\TranslationServices\Section;
use WPML\TM\Menu\TranslationMethod\TranslationMethodSettings;
use WPML\FP\Relation;
use WPML\LIB\WP\User;

class WPML_TM_Translation_Roles_Section implements IWPML_TM_Admin_Section {
	const SLUG = 'translators';

	private $translation_services_section;

	public function __construct( Section $translation_services_section ) {
		$this->translation_services_section = $translation_services_section;

		TranslationMethodSettings::addHooks();
	}

	public function get_order() {
		return 300;
	}

	public function get_slug() {
		return self::SLUG;
	}

	public function get_capabilities() {
		return [ User::CAP_MANAGE_TRANSLATIONS, User::CAP_ADMINISTRATOR ];
	}

	public function get_caption() {
		return __( 'Manage Translators & Services', 'sitepress' );

	}

	public function get_description() {
		return '<p class="wpml-tab-description">' . __( 'Choose who translates your site', 'sitepress' ) . '</p>';
	}

	public function get_callback() {
		return [ $this, 'render' ];
	}

	public function admin_enqueue_scripts( $hook ) {
		if ( Relation::propEq( 'sm', 'translators', $_GET ) ) {
			Initializer::loadJS();
		}
	}

	public function is_visible() {
		return true;
	}

	public function render() {
		?>
		<div id="wpml-translation-roles-ui-container"></div>
		<?php
		$this->translation_services_section->render();
	}
}
