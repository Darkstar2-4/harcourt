<?php

namespace WPML\Compatibility\Divi\V5;

use WPML\LIB\WP\Hooks;
use WPML\PB\Integrations\Divi\Helper;

use function WPML\FP\spreadArgs;

class LanguageSwitcher implements \IWPML_Frontend_Action {

	public function add_hooks() {
		Hooks::onFilter( 'wpml_ls_html', 10, 3 )
			->then( spreadArgs( [ $this, 'disableInBuilder' ] ) );
	}

	public function disableInBuilder( $html, $model, $slot ) {
		if ( Helper::isInDiviBuilderMainWindow() && 'footer' === $slot->get( 'slot_slug' ) ) {
			$html = '';
		}

		return $html;
	}
}
