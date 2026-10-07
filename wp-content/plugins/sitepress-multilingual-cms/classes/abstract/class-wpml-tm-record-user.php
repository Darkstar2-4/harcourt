<?php

class WPML_TM_Record_User {

	protected $tm_records;

	public function __construct( &$tm_records ) {
		$this->tm_records = &$tm_records;
	}

}
