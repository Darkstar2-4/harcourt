<?php

namespace WPML\TM\ATE\Hooks;

use WPML\FP\Obj;

class ReturnedJobActions implements \IWPML_Action {
	private $removeTranslationDuplicateStatus;

	public function __construct( callable $removeTranslationDuplicateStatus ) {
		$this->removeTranslationDuplicateStatus = $removeTranslationDuplicateStatus;
	}


	public function add_hooks() {
		add_action( 'init', [ $this, 'callActions' ] );
	}

	public function callActions() {
		if ( isset( $_GET['ate_original_id'] ) && Obj::prop( 'complete', $_GET ) ) {
			call_user_func( $this->removeTranslationDuplicateStatus, (int) $_GET['ate_original_id'] );
			do_action( 'wpml_on_back_from_ate_manual_translation', (int) $_GET['ate_original_id'] );
		}
	}
}
