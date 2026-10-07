<?php

use WPML\API\Sanitize;

class WPML_TF_Settings implements IWPML_TF_Settings {

	const BUTTON_MODE_DISABLED = 'disabled';
	const BUTTON_MODE_LEFT     = 'left';
	const BUTTON_MODE_RIGHT    = 'right';
	const BUTTON_MODE_CUSTOM   = 'custom';

	const ICON_STYLE_LEGACY   = 'translation';
	const ICON_STYLE_STAR     = 'star';
	const ICON_STYLE_THUMBSUP = 'thumbsup';
	const ICON_STYLE_BULLHORN = 'bullhorn';
	const ICON_STYLE_COMMENT  = 'comment';
	const ICON_STYLE_QUOTE    = 'quote';

	const DISPLAY_ALWAYS = 'always';
	const DISPLAY_CUSTOM = 'custom';

	const EXPIRATION_ON_PUBLISH_OR_UPDATE = 'publish_or_update';
	const EXPIRATION_ON_PUBLISH_ONLY      = 'publish_only';
	const EXPIRATION_ON_UPDATE_ONLY       = 'update_only';

	const DELAY_DAY   = 1;
	const DELAY_WEEK  = 7;
	const DELAY_MONTH = 30;

	private $enabled = false;

	private $button_mode = self::BUTTON_MODE_LEFT;

	private $icon_style = self::ICON_STYLE_LEGACY;

	private $languages_to = null;

	private $display_mode = self::DISPLAY_CUSTOM;

	private $expiration_mode = self::EXPIRATION_ON_PUBLISH_OR_UPDATE;

	private $expiration_delay_quantity = 1;

	private $expiration_delay_unit = self::DELAY_MONTH;

	public function set_enabled( $enabled ) {
		$this->enabled = (bool) $enabled;
	}

	public function is_enabled() {
		return $this->enabled;
	}

	public function set_button_mode( $button_mode ) {
  		$this->button_mode = Sanitize::string( $button_mode );
	}

	public function get_button_mode() {
		return $this->button_mode;
	}

	public function set_icon_style( $style ) {
		$this->icon_style = Sanitize::string( $style );
	}

	public function get_icon_style() {
		return $this->icon_style;
	}

	public function set_languages_to( array $languages_to ) {
		$this->languages_to = array_map( 'sanitize_title', $languages_to );
	}

	public function get_languages_to() {
		return $this->languages_to;
	}

	public function set_display_mode( $display_mode ) {
		$this->display_mode = Sanitize::string( $display_mode );
	}

	public function get_display_mode() {
		return $this->display_mode;
	}

	public function set_expiration_mode( $expiration_mode ) {
		$this->expiration_mode = Sanitize::string( $expiration_mode );
	}

	public function get_expiration_mode() {
		return $this->expiration_mode;
	}

	public function set_expiration_delay_quantity( $expiration_delay_quantity ) {
		$this->expiration_delay_quantity = (int) $expiration_delay_quantity;
	}

	public function get_expiration_delay_quantity() {
		return $this->expiration_delay_quantity;
	}

	public function set_expiration_delay_unit( $expiration_delay_unit ) {
		$this->expiration_delay_unit = (int) $expiration_delay_unit;
	}

	public function get_expiration_delay_unit() {
		return $this->expiration_delay_unit;
	}

	public function get_expiration_delay_in_days() {
		return $this->expiration_delay_quantity * $this->expiration_delay_unit;
	}

	public function get_properties() {
		return get_object_vars( $this );
	}
}
