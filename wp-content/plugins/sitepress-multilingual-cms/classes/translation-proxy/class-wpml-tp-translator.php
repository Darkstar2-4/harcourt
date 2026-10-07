<?php

class WPML_TP_Translator {

	public function get_icl_translator_status( $force = false ) {
		return TranslationProxy_Translator::get_icl_translator_status( $force );
	}
}
