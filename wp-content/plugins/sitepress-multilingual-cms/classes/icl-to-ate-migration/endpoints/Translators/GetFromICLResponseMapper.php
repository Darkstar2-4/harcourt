<?php

namespace WPML\ICLToATEMigration\Endpoints\Translators;

use WPML\FP\Either;
use WPML\FP\Fns;

class GetFromICLResponseMapper {

	public static function map( $records ) {
		return Either::of( [ 'translators' => Fns::map( [ self::class, 'constructUserData' ], $records ) ] );
	}

	public static function constructUserData( $record ) {
		$user = get_user_by( 'email', $record->email );

		return [
			'user'          => [
				'id'       => $record->icl_id,
				'first'    => $record->first_name,
				'last'     => $record->last_name,
				'email'    => $record->email,
				'userName' => $user ? $user->data->user_login : strtolower( $record->first_name . '_' . $record->last_name ),
				'wpRole'   => $user ? current( $user->roles ) : 'subscriber'
			],
			'languagePairs' => self::constructUserLanguagePairs( $record->lang_pairs )
		];
	}

	public static function constructUserLanguagePairs( $langPairs ) {
		$constructedLangPairs = [];

		foreach ( $langPairs as $langPair ) {
			$constructedLangPairs[ $langPair->from ][] = $langPair->to;
		}

		return $constructedLangPairs;
	}
}
