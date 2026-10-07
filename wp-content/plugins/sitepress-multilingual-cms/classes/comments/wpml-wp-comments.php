<?php

class WPML_WP_Comments {

	const LANG_CODE_FIELD = 'wpml_language_code';

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function add_hooks() {
		add_filter( 'comment_form_field_comment', array( $this, 'add_wpml_language_field' ) );
	}

	public function add_wpml_language_field( $comment_field ) {
		$comment_field .= '<input name="' . self::LANG_CODE_FIELD . '" type="hidden" value="' . $this->sitepress->get_current_language() . '" />';

		return $comment_field;
	}
}
