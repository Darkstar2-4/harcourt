<?php

class WPML_PB_Last_Translation_Edit_Mode {

	const POST_META_KEY      = '_last_translation_edit_mode';
	const NATIVE_EDITOR      = 'native-editor';
	const TRANSLATION_EDITOR = 'translation-editor';

	public static function is_native_editor( $post_id ) {
		return self::get_last_mode( $post_id ) === self::NATIVE_EDITOR;
	}

	public static function is_translation_editor( $post_id ) {
		return self::get_last_mode( $post_id ) === self::TRANSLATION_EDITOR;
	}

	private static function get_last_mode( $post_id ) {
		return get_post_meta( $post_id, self::POST_META_KEY, true );
	}

	public static function set_native_editor( $post_id ) {
		self::set_mode( $post_id, self::NATIVE_EDITOR );
	}

	public static function set_translation_editor( $post_id ) {
		self::set_mode( $post_id, self::TRANSLATION_EDITOR );
	}

	private static function set_mode( $post_id, $mode ) {
		update_post_meta( $post_id, self::POST_META_KEY, $mode );
	}
}
