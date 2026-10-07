<?php

class WPML_Resolve_Absolute_Url implements IWPML_Resolve_Object_Url {

	private $sitepress;

	private $translate_link_targets;

	private $lock;

	public function __construct( SitePress $sitepress, WPML_Translate_Link_Targets $translate_link_targets ) {
		$this->sitepress              = $sitepress;
		$this->translate_link_targets = $translate_link_targets;
	}

	public function resolve_object_url( $url, $lang ) {
		if ( $this->lock ) {
			return false;
		}

		$this->lock   = true;
		$current_lang = $this->sitepress->get_current_language();
		$this->sitepress->switch_lang( $lang );

		try {
			$new_url = $this->translate_link_targets->convert_url( $url );

			if ( trailingslashit( $new_url ) === trailingslashit( $url ) ) {
				$new_url = false;
			}
		} catch ( Exception $e ) {
			$new_url = false;
		}

		$this->sitepress->switch_lang( $current_lang );
		$this->lock = false;

		return $new_url;

	}
}
