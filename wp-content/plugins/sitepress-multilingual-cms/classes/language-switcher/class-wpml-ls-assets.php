<?php

class WPML_LS_Assets {

	private $enqueued_templates = array();

	private $templates;

	private $settings;

	public function __construct( $templates, &$settings ) {
		$this->templates = $templates;
		$this->settings  = $settings;
	}

	public function init_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts_action' ) );
	}

	public function wp_enqueue_scripts_action() {
		$active_templates_slugs = $this->settings->get_active_templates();

		$active_templates_slugs = apply_filters( 'wpml_ls_enqueue_templates', $active_templates_slugs );

		$templates = $this->templates->get_templates( $active_templates_slugs );

		foreach ( $templates as $slug => $template ) {
			$this->enqueue_template_resources( $slug, $template );
		}
	}

	public function maybe_late_enqueue_template( $slug ) {
		if ( ! in_array( $slug, $this->enqueued_templates ) ) {
			$template = $this->templates->get_template( $slug );
			$this->enqueue_template_resources( $slug, $template );
		}
	}

	private function enqueue_template_resources( $slug, $template ) {
		$this->enqueued_templates[] = $slug;
		if ( $this->settings->can_load_script( $slug ) ) {

			foreach ( $template->get_scripts() as $k => $url ) {
				$site_scheme_url = set_url_scheme( $url );
				wp_enqueue_script( $template->get_resource_handler( $k ), $site_scheme_url, array(), $template->get_version() );
			}
		}

		if ( $this->settings->can_load_styles( $slug ) ) {

			foreach ( $template->get_styles() as $k => $url ) {
				$site_scheme_url = set_url_scheme( $url );
				wp_enqueue_style( $template->get_resource_handler( $k ), $site_scheme_url, array(), $template->get_version() );
			}
		}
	}
}
