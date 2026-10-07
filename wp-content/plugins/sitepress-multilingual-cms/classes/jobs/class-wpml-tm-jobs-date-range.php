<?php

class WPML_TM_Jobs_Date_Range {
	private $begin;

	private $end;

	private $include_null_date;

	public function __construct( ?DateTime $begin = null, ?DateTime $end = null, $include_null_date = false ) {
		$this->begin             = $begin;
		$this->end               = $end;
		$this->include_null_date = (bool) $include_null_date;
	}

	public function get_begin() {
		return $this->begin;
	}

	public function get_end() {
		return $this->end;
	}

	public function is_include_null_date() {
		return $this->include_null_date;
	}
}
