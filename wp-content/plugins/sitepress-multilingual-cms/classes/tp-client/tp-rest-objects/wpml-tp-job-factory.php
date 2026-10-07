<?php

class WPML_TP_Job_Factory {

	public function create( stdClass $job ) {
		return new WPML_TP_Job( $job );
	}
}
