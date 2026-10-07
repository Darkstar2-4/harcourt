<?php

class WPML_TF_Feedback_Reviewer {

	private $id;

	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_reviewer_display_name() {
		$display_name = __( 'Unknown reviewer', 'sitepress' );

		$reviewer = get_user_by( 'id', $this->get_id() );

		if ( isset( $reviewer->display_name ) ) {
			$display_name = $reviewer->display_name;
		}

		return $display_name;
	}
}
