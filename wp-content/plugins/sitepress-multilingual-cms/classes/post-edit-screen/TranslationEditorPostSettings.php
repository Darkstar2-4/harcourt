<?php

namespace WPML\TM\PostEditScreen;

use WPML\Element\API\PostTranslations;
use WPML\Element\API\Translations;
use WPML\FP\Fns;
use WPML\LIB\WP\Hooks;
use WPML\TM\PostEditScreen\Endpoints\SetEditorMode;
use WPML\Core\WP\App\Resources;
use WPML\API\Settings;
use function WPML\FP\spreadArgs;
use WPML\PostHog\Event\SendDataToPostHog;
use WPML\PostHog\State\PostHogState;

class TranslationEditorPostSettings {
	private $sitepress;

	public function __construct( $sitepress ) {
		$this->sitepress = $sitepress;
		$this->registerPostHogAjaxCapture();
	}

	public function add_hooks() {
		Hooks::onAction( 'admin_enqueue_scripts' )
		     ->then( [ $this, 'localize' ] )
		     ->then( Resources::enqueueApp( 'postEditTranslationEditor' ) );

		$render = Fns::once( spreadArgs( [ $this, 'render' ] ) );

		Hooks::onAction( 'wpml_before_post_edit_translations_table' )
		     ->then( $render );

		Hooks::onAction( 'wpml_before_post_edit_translations_summary' )
		     ->then( $render );
	}

	public static function localize() {
		return [
			'name' => 'wpml_translation_post_editor',
			'data' => [
				'endpoints' => [
					'setEditorMode' => SetEditorMode::class,
				],
				'urls' => [
					'tmdashboard' => admin_url( 'admin.php?page=tm%2Fmenu%2Fmain.php' ),
				],
				'nonces' => [
					'captureTranslationEditorData' => wp_create_nonce( 'wpml_posthog_capture_data_nonce' ),
				],
				'trackingMode' => PostHogState::getTrackingMode(),
			],
		];
	}

	public function render( $post ) {
		global $wp_post_types;

		if ( ! Translations::isOriginal( $post->ID, PostTranslations::get( $post->ID ) ) ) {
			return;
		}

		list( $useTmEditor, $isWpmlEditorBlocked, $reason ) = \WPML_TM_Post_Edit_TM_Editor_Mode::get_editor_settings( $this->sitepress, $post->ID );

		$editorModeFor = '';
		$tmSettings = $this->sitepress->get_setting( 'translation-management' );
		if ( $useTmEditor && ! $isWpmlEditorBlocked ) {
			list ( $enabledEditor, $editorModeFor ) = $this->getEditorAndMode( $post->ID, $tmSettings, SetEditorMode::TRANSLATION_EDITOR_WPML );
		} else {
			list ( $enabledEditor, $editorModeFor ) = $this->getEditorAndMode( $post->ID, $tmSettings, SetEditorMode::TRANSLATION_EDITOR_NATIVE );
			$enabledEditor = SetEditorMode::TRANSLATION_EDITOR_NATIVE;
		}
		$postTypeLabels = $wp_post_types[ $post->post_type ]->labels;
		$currentWpmlEditor     = (string) Settings::pathOr( ICL_TM_TMETHOD_ATE, [
			'translation-management',
			'doc_translation_method'
		] );

		$wpmlEditorName = ICL_TM_TMETHOD_ATE === $currentWpmlEditor
			? esc_attr__( 'Advanced Translation Editor', 'sitepress' )
			: esc_attr__( 'Classic Translation Editor', 'sitepress' );
		$sourceLang = $this->sitepress->get_element_language_details( $post->ID )->language_code;

		echo '
		<div 
			id="translation-editor-post-settings" 
			data-wpml-editor-name="' . $wpmlEditorName . '" 
			data-post-id="' . $post->ID . '" 
			data-post-type="' . $post->post_type . '" 
			data-enabled-editor="' . $enabledEditor . '" 
			data-wpml-editor-mode-for="' . $editorModeFor . '" 
			data-is-wpml-blocked="' . $isWpmlEditorBlocked . '" 
			data-wpml-blocked-reason="' . $reason . '" 
			data-type-singular="' . $postTypeLabels->singular_name . '" 
			data-type-plural="' . $postTypeLabels->name . '"
			data-source-lang="'. $sourceLang .'"
		></div>
		';
		echo '<div id="icl-translation-dashboard"></div>';
	}

	private function getEditorAndMode( $post_id, $tmSettings, $editor = 'wpml' ) {
		$meta_key = \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_USE_WPML;
		$post_type_key = \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_FOR_POST_TYPE_USE_WPML;
		$global_key = \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_USE_WPML;

		if ( $editor === SetEditorMode::TRANSLATION_EDITOR_NATIVE ) {
			$meta_key = \WPML_TM_Post_Edit_TM_Editor_Mode::POST_META_KEY_USE_NATIVE;
			$post_type_key = \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_FOR_POST_TYPE_USE_NATIVE;
			$global_key = \WPML_TM_Post_Edit_TM_Editor_Mode::TM_KEY_GLOBAL_USE_NATIVE;
		}

		$post_meta = get_post_meta( $post_id, $meta_key, true );
		if ( $post_meta ) {
			return [
				$post_meta,
				SetEditorMode::MODE_FOR_THIS_POST
			];
		}

		$post_type = get_post_type( $post_id );
		if ( isset( $tmSettings[ $post_type_key ][ $post_type ] ) ) {
			return [
				$tmSettings[ $post_type_key ][ $post_type ],
				SetEditorMode::MODE_FOR_POST_TYPE
			];
		}

		if ( isset( $tmSettings[ $global_key ] ) ) {
			return [
				$tmSettings[ $global_key ],
				SetEditorMode::MODE_FOR_GLOBAL
			];
		}

		return [
			SetEditorMode::TRANSLATION_EDITOR_DASHBOARD,
			SetEditorMode::MODE_FOR_GLOBAL
		];
	}

	private function registerPostHogAjaxCapture() {
		if ( PostHogState::isEnabled() ) {
			new SendDataToPostHog();
		}
	}
}
