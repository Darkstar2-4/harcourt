<?php

class WPML_TM_User {

	protected $tm_instance;

	public function __construct( TranslationManagement $tm_instance ) {
		$this->tm_instance = $tm_instance;
	}
}
