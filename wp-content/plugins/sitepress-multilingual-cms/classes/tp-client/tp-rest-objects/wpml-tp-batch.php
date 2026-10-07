<?php

class WPML_TP_Batch extends WPML_TP_REST_Object {

	private $id;

	public function get_id() {
		return $this->id;
	}

	public function set_id( $id ) {
		$this->id = (int) $id;
	}

	protected function get_properties() {
		return array(
			'id' => 'id',
		);
	}
}
