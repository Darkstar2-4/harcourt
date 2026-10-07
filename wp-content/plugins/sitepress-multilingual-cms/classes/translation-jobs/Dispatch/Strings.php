<?php

namespace WPML\TM\Jobs\Dispatch;

use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\LIB\WP\User;
use function WPML\Container\make;

class Strings {
	public static function dispatch(
		callable $sendBatch,
		Messages $messages,
		callable $buildBatch,
		$stringIds,
		$sourceLanguage,
		$targetLanguages
	) {
		$stringsForTranslation = self::filterStringsForTranslation( $messages, $stringIds, $targetLanguages );
		$translators = array_fill_keys( $targetLanguages, User::getCurrentId() );

		$batch = $buildBatch( $stringsForTranslation, $sourceLanguage, $translators );
		if ( $batch ) {
			$batch->setTranslationMode( 'auto' );
			$batch->setHowToHandleExisting( \WPML_TM_Translation_Batch::HANDLE_EXISTING_OVERRIDE );
			$sendBatch( $messages, $batch );
		}
	}


	private static function filterStringsForTranslation( Messages $messages, $stringIds, $targetLanguages ) {
		$stringsToTranslation   = [];
		$ignoredStringsMessages = [];

		$stringFactory = make( \WPML_ST_String_Factory::class );

		foreach ( $stringIds as $stringId ) {
			$stringsToTranslation[ $stringId ] = [];

			$string   = $stringFactory->find_by_id( $stringId );
			$statuses = wpml_collect( $string->get_translation_statuses() )->keyBy( 'language' )->map( Obj::prop( 'status' ) );

			foreach ( $targetLanguages as $language ) {
				if ( (int) Obj::prop( $language, $statuses ) === ICL_TM_WAITING_FOR_TRANSLATOR ) {
					$ignoredStringsMessages[] = $messages->ignoreInProgressStringMessage( $string, $language );
				} else {
					$stringsToTranslation[ $stringId ] [] = $language;
				}
			}
		}

		$messages->showForStrings( $ignoredStringsMessages, 'information' );

		return array_filter( $stringsToTranslation, Lst::length() );
	}
}
