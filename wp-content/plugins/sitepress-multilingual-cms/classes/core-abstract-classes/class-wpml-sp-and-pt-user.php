<?php

abstract class WPML_SP_And_PT_User extends WPML_SP_User {

	protected $post_translation;

	public function __construct( &$post_translation, &$sitepress ) {
		parent::__construct( $sitepress );
		$this->post_translation = &$post_translation;
	}
}
