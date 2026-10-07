<?php

abstract class WPML_SP_User {

	protected $sitepress;

	public function __construct( &$sitepress ) {
		$this->sitepress = &$sitepress;
	}
}
