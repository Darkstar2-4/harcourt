<?php

class WPML_TM_Job_TS_Status {

	private $status;
	private $links = array();

	public function __construct( $status, $links ) {
		$this->status = $status;
		$this->links  = $links;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_links() {
		return $this->links;
	}

	public function __toString() {
		if ( $this->status ) {
			return wp_json_encode(
				array(
					'status' => $this->status,
					'links'  => $this->links,
				)
			);
		}
		return '';
	}
}
