<?php

class WPML_Compatibility_Disqus_Factory implements IWPML_Frontend_Action_Loader {
	public function create() {
		global $sitepress;

		return new WPML_Compatibility_Disqus( $sitepress );
	}

}
