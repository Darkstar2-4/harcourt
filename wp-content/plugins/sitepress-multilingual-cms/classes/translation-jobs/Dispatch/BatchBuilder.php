<?php

namespace WPML\TM\Jobs\Dispatch;

use WPML\FP\Curryable;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\FP\Str;

use function WPML\FP\curryN;
use function WPML\FP\pipe;

class BatchBuilder {
	use Curryable;

	public static function init() {}

	public static function buildPostsBatch( $data = null, $sourceLanguage = null, $translators = null ) {
		return call_user_func_array(
			curryN(
				3,
				function ( $data, $sourceLanguage, $translators ) {
					return self::build(
						'Translation-%s-%s',
						self::getPostElements(),
						$data,
						$sourceLanguage,
						$translators
					);
				}
			),
			func_get_args()
		);
	}

	public static function buildStringsBatch( $data = null, $sourceLanguage = null, $translators = null ) {
		return call_user_func_array(
			curryN(
				3,
				function ( $data, $sourceLanguage, $translators ) {
					return self::build(
						'Strings translation-%s-%s',
						self::getStringElements(),
						$data,
						$sourceLanguage,
						$translators
					);
				}
			),
			func_get_args()
		);
	}

	public static function getPostElements( $postsForTranslation = null, $sourceLanguage = null ) {
		return call_user_func_array(
			curryN(
				2,
				function ( $postsForTranslation, $sourceLanguage ) {
					$elements = [];

					foreach ( $postsForTranslation as $postId => $postData ) {
						$elements[] = new \WPML_TM_Translation_Batch_Element(
							$postId,
							$postData['type'],
							$sourceLanguage,
							array_fill_keys( $postData['target_languages'], \TranslationManagement::TRANSLATE_ELEMENT_ACTION ),
							Obj::propOr( [], 'media', $postData )
						);
					}
					return $elements;
				}
			),
			func_get_args()
		);
	}


	public static function getStringElements( $stringsForTranslation = null, $sourceLanguage = null ) {
		return call_user_func_array(
			curryN(
				2,
				function ( $stringsForTranslation, $sourceLanguage ) {
					$elements = [];

					$setTranslateAction = pipe(
						Fns::map( pipe( Lst::makePair( \TranslationManagement::TRANSLATE_ELEMENT_ACTION ), Lst::reverse() ) ),
						Lst::fromPairs()
					);

					foreach ( $stringsForTranslation as $stringId => $targetLanguages ) {
						$elements[] = new \WPML_TM_Translation_Batch_Element(
							$stringId,
							'string',
							$sourceLanguage,
							$setTranslateAction( $targetLanguages )
						);
					}

					return $elements;
				}
			),
			func_get_args()
		);
	}

	private static function build( $batchNameTemplate, callable $buildElementStrategy, array $data, $sourceLanguage, array $translators ) {
		$targetLanguagesString = pipe( Lst::flatten(), 'array_unique', Lst::join( '|' ) );
		$idsHash               = pipe( 'array_keys', Lst::join( '-' ), 'md5', Str::sub( 16 ) );

		$batchName = sprintf(
			$batchNameTemplate,
			$targetLanguagesString( $data ),
			$idsHash( $data )
		);

		$elements = apply_filters(
			'wpml_tm_batch_factory_elements',
			$buildElementStrategy( $data, $sourceLanguage ),
			$batchName
		);

		return $elements ? new \WPML_TM_Translation_Batch( $elements, $batchName, $translators, null ) : null;
	}
}

BatchBuilder::init();
