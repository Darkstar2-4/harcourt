<?php

class WPML_Post_Custom_Field_Setting_Keys {

	public static function get_state_array_setting_index() {
		return 'custom_fields_translation';
	}

	public static function get_unlocked_setting_index() {
		return defined( 'WPML_POST_META_UNLOCKED_SETTING_INDEX' )
			? WPML_POST_META_UNLOCKED_SETTING_INDEX
			: 'custom_fields_unlocked_config';
	}

	public static function get_setting_prefix() {
		return 'custom_fields_';
	}


	public static function get_excluded_keys() {
		return array(
			'_edit_last',
			'_edit_lock',
			'_wp_page_template',
			'_wp_attachment_metadata',
			'_icl_translator_note',
			'_alp_processed',
			'_pingme',
			'_encloseme',
			'_icl_lang_duplicate_of',
			'_wpml_media_duplicate',
			'wpml_media_processed',
			'_wpml_media_featured',
			'_thumbnail_id',
			\WPML\MediaTranslation\PostWithMediaFiles::COPIED_MEDIA_IDS_SETTING,
			\WPML\MediaTranslation\PostWithMediaFiles::REFERENCED_MEDIA_IDS_SETTING,
		);
	}
}