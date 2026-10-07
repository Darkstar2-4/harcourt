<?php

class WPML_Translate_Link_Targets_UI extends WPML_TM_MCS_Section_UI {
	const ID = 'ml-content-setup-sec-links-target';

	private $wpdb;
	private $pro_translation;
	private $wp_api;
	private $sitepress;

	public function __construct( $title, $wpdb, $sitepress, $pro_translation ) {
		parent::__construct( self::ID, $title );
		$this->wpdb            = $wpdb;
		$this->pro_translation = $pro_translation;
		$this->sitepress       = $sitepress;
	}

	public function add_hooks() {
		if ( $this->links_need_adjustment() ) {
			parent::add_hooks();
		}
	}

	protected function render_content() {
		$output = '';

		$main_message     = __( 'Adjust links in posts so they point to the translated content', 'wpml-translation-management' );
		$complete_message = __( 'All posts have been processed. %s links were changed to point to the translated content.', 'wpml-translation-management' );
		$scanning_message = __( 'Scanning now, please wait...', 'sitepress' );
		$error_message    = __( 'Error! Reload the page and try again.', 'sitepress' );

		if ( wpml_is_st_loaded() ) {
			$main_message     = __( 'Adjust links in posts and strings so they point to the translated content', 'wpml-translation-management' );
			$complete_message = __( 'All posts and strings have been processed. %s links were changed to point to the translated content.', 'wpml-translation-management' );
		}

		$data_attributes = array(
			'post-message'     => esc_attr__( 'Processing posts... %1$s of %2$s done.', 'wpml-translation-management' ),
			'string-message'   => esc_attr__( 'Processing strings... %1$s of %2$s done.', 'wpml-translation-management' ),
			'complete-message' => esc_attr( $complete_message ),
			'scanning-message' => esc_attr( $scanning_message ),
			'error-message'    => esc_attr( $error_message ),
		);

		$output .= '<p>' . $main_message . '</p>';
		$output .= '<button id="wpml-scan-link-targets" class="button-secondary wpml-button base-btn wpml-button--outlined"';

		foreach ( $data_attributes as $key => $value ) {
			$output .= ' data-' . $key . '="' . $value . '"';
		}
		$output .= '>' . esc_html__( 'Scan now and adjust links', 'wpml-translation-management' ) . '</button>';
		$output .= '<span class="spinner"> </span>';
		$output .= '<p class="results"> </p>';
		$output .= wp_nonce_field( 'WPML_Ajax_Update_Link_Targets', 'wpml-translate-link-targets', true, false );

		return $output;
	}

	private function links_need_adjustment() {
		$global_state = new WPML_Translate_Link_Target_Global_State( $this->sitepress );
		if ( $global_state->is_rescan_required() ) {
			return true;
		}

		$posts_link_target = new WPML_Translate_Link_Targets_In_Posts(
			$global_state,
			$this->wpdb,
			$this->pro_translation
		);

		if ( $posts_link_target->get_number_to_be_fixed( 0, 1 ) > 0 ) {
			return true;
		}

		$wp_api              = $this->sitepress->get_wp_api();
		$strings_link_target = new WPML_Translate_Link_Targets_In_Strings(
			$global_state,
			$this->wpdb,
			$wp_api,
			$this->pro_translation
		);

		if ( $strings_link_target->get_number_to_be_fixed( 0, 1 ) > 0 ) {
			return true;
		}

		return false;
	}
}
