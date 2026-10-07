<?php

namespace WPML\TM\Editor;

use WPML\FP\Str;
use WPML\FP\Cast;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\LIB\WP\Option;
use WPML\TM\ATE\ClonedSites\ApiCommunication;
use WPML\UIPage;
use function WPML\FP\pipe;

class ATEDetailedErrorMessage {

	const ERROR_DETAILS_OPTION = 'wpml_ate_error_details';

	public static function saveDetailedError( $errorResponse ) {
		$errorCode    = $errorResponse->get_error_code();
		$errorMessage = $errorResponse->get_error_message();
		$errorData    = $errorResponse->get_error_data( $errorCode );

		$errorDetails = [
			'code'       => $errorCode,
			'message'    => $errorMessage,
			'error_data' => $errorData,
		];

		self::saveErrorDetailsInOptions( $errorDetails );
	}

	public static function readDetailedError( $appendText = null ) {
		$errorDetails = Option::getOr( self::ERROR_DETAILS_OPTION, [] );

		$detailedError = self::hasValidExplainedMessage( $errorDetails )
			? self::formattedDetailedErrors( $errorDetails, $appendText )
			: (
			self::isSiteMigrationError( $errorDetails )
				? self::formattedSiteMigrationError( $errorDetails )
				: null
			);

		self::deleteErrorDetailsFromOptions();

		return $detailedError;
	}

	private static function hasValidExplainedMessage( $errorDetails ) {
		$hasExplainedMessage = pipe(
			Obj::pathOr( '', [ 'error_data', 0, 'explained_message' ] ),
			Str::len(),
			Cast::toBool()
		);

		return $hasExplainedMessage( $errorDetails );
	}

	private static function isSiteMigrationError( $errorDetails ) {
		$isSiteMigrationError = pipe(
			Obj::prop( 'code' ),
			Cast::toInt(),
			Relation::equals( ApiCommunication::SITE_CLONED_ERROR )
		);

		return $isSiteMigrationError( $errorDetails );
	}

	private static function saveErrorDetailsInOptions( $errorDetails ) {
		Option::updateWithoutAutoLoad( self::ERROR_DETAILS_OPTION, $errorDetails );
	}

	private static function deleteErrorDetailsFromOptions() {
		Option::delete( self::ERROR_DETAILS_OPTION );
	}

	private static function formattedDetailedErrors( array $errorDetails, $appendText ) {
		$appendText = $appendText ? '<div>' . $appendText . '</div>' : '';

		$allErrors = '<div>';

		foreach ( Obj::prop( 'error_data', $errorDetails ) as $error ) {
			$allErrors .= '<div>' . Obj::propOr( '', 'explained_message', $error ) . '</div>';
		}

		return $allErrors . $appendText . '</div>';
	}

	private static function formattedSiteMigrationError( $errorDetails ) {
		return '<div>' . Obj::prop( 'message', $errorDetails ) . '</div>';
	}
}