<?php

class WPML_Ajax_Scan_Link_Targets extends WPML_WPDB_User implements IWPML_AJAX_Action_Run {

	private $post_links;

	private $string_links;

	private $post_data;


	public function __construct(
		WPML_Translate_Link_Targets_In_Posts_Global $post_links,
		$string_links,
		$post_data
	) {
		$this->post_links   = $post_links;
		$this->string_links = $string_links;
		$this->post_data    = $post_data;
	}

	public function run() {
		if (
			! wp_verify_nonce(
				$this->post_data['nonce'],
				'WPML_Ajax_Update_Link_Targets'
			)
		) {
			return new WPML_Ajax_Response( false, 'wrong nonce' );
		}

		return new WPML_Ajax_Response(
			true,
			[
				'post_count'   => $this->post_links->get_number_to_be_fixed(),
				'string_count' => $this->string_links
					? $this->string_links->get_number_to_be_fixed()
					: 0,
			]
		);
	}
}
