<?php

class WPML_LS_Dependencies_Factory {

	private $sitepress;

	private $parameters;

	private $templates;

	private $slot_factory;

	private $settings;

	private $model_build;

	private $inline_styles;

	private $render;

	private $admin_ui;

	private $shortcodes;

	private $actions;

	public function __construct( SitePress $sitepress, array $parameters = [] ) {
		$this->sitepress  = $sitepress;
		$this->parameters = $parameters;
	}

	public function sitepress() {
		return $this->sitepress;
	}

	public function parameter( $key ) {
		return isset( $this->parameters[ $key ] ) ? $this->parameters[ $key ] : null;
	}

	public function templates() {
		if ( ! $this->templates ) {
			$this->templates = new WPML_LS_Templates();
		}

		return $this->templates;
	}

	public function slot_factory() {
		if ( ! $this->slot_factory ) {
			$this->slot_factory = new WPML_LS_Slot_Factory();
		}

		return $this->slot_factory;
	}

	public function settings() {
		if ( ! $this->settings ) {
			$this->settings = new WPML_LS_Settings( $this->templates(), $this->sitepress(), $this->slot_factory() );
		}

		return $this->settings;
	}

	public function model_build() {
		if ( ! $this->model_build ) {
			$this->model_build = new WPML_LS_Model_Build( $this->settings(), $this->sitepress(), $this->parameter( 'css_prefix' ) );
		}

		return $this->model_build;
	}

	public function inline_styles() {
		if ( ! $this->inline_styles ) {
			$this->inline_styles = new WPML_LS_Inline_Styles( $this->templates(), $this->settings(), $this->model_build() );
		}

		return $this->inline_styles;
	}

	public function render() {
		if ( ! $this->render ) {
			$this->render = new WPML_LS_Render( $this->templates(), $this->settings(), $this->model_build(), $this->inline_styles(), $this->sitepress() );
		}

		return $this->render;
	}

	public function admin_ui() {
		if ( ! $this->admin_ui ) {
			$this->admin_ui = new WPML_LS_Admin_UI( $this->templates(), $this->settings(), $this->render(), $this->inline_styles(), $this->sitepress() );
		}

		return $this->admin_ui;
	}

	public function shortcodes() {
		if ( ! $this->shortcodes ) {
			$this->shortcodes = new WPML_LS_Shortcodes( $this->settings(), $this->render(), $this->sitepress() );
		}

		return $this->shortcodes;
	}

	public function actions() {
		if ( ! $this->actions ) {
			$this->actions = new WPML_LS_Actions( $this->settings(), $this->render(), $this->sitepress() );
		}

		return $this->actions;
	}

}
