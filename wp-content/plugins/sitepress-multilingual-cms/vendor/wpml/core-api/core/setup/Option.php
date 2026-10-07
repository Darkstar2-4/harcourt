<?php

namespace WPML\Setup;

use WPML\Element\API\Entity\LanguageMapping;
use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Relation;
use WPML\LIB\WP\PostType;
use WPML\WP\OptionManager;

class Option {
	const POSTS_LIMIT_FOR_AUTOMATIC_TRANSLATION = 10;

	const OPTION_GROUP = 'setup';
	const CURRENT_STEP = 'current-step';

	const ORIGINAL_LANG = 'original-lang';
	const TRANSLATED_LANGS = 'translated-langs';
	const LANGUAGES_MAPPING = 'languages-mapping';

	const WHO_MODE = 'who-mode';
	const TRANSLATE_EVERYTHING = 'translate-everything';
	const TRANSLATE_EVERYTHING_DRAFTS = 'translate-everything-drafts';
	const HAS_TRANSLATE_EVERYTHING_BEEN_EVER_USED = 'has-translate-everything-been-ever-used';
	const TRANSLATE_EVERYTHING_COMPLETED = 'translate-everything-completed';
	const TRANSLATE_EVERYTHING_POSTS = 'translate-everything-posts';
	const TRANSLATE_EVERYTHING_PACKAGES_COMPLETED = 'translate-everything-packages';
	const TRANSLATE_EVERYTHING_SRTINGS_COMPLETED = 'translate-everything-strings';
	const TM_ALLOWED = 'is-tm-allowed';
	const REVIEW_MODE = 'review-mode';

	const NO_REVIEW = 'no-review';
	const PUBLISH_AND_REVIEW = 'publish-and-review';
	const HOLD_FOR_REVIEW = 'before-publish';


	public static function getCurrentStep() {
		return self::get( self::CURRENT_STEP, 'languages' );
	}

	public static function saveCurrentStep( $step ) {
		self::set( self::CURRENT_STEP, $step );
	}

	public static function getOriginalLang() {
		return self::get( self::ORIGINAL_LANG );
	}

	public static function setOriginalLang( $lang ) {
		self::set( self::ORIGINAL_LANG, $lang );
	}

	public static function getTranslationLangs() {
		return self::get( self::TRANSLATED_LANGS, [] );
	}

	public static function setTranslationLangs( array $langs ) {
		self::set( self::TRANSLATED_LANGS, $langs );
	}

	public static function setDefaultTranslationMode( $hasPreferredTranslationService = false ) {
		if ( self::get( self::WHO_MODE, null ) === null ) {

			$defaultTranslationMode = $hasPreferredTranslationService ? 'service' : 'myself';
			self::setTranslationMode( [ $defaultTranslationMode ] );
		}
	}

	public static function setOnlyMyselfAsDefault() {
		if ( self::get( self::WHO_MODE, null ) === null ) {
			self::setTranslationMode( [ 'myself' ] );
		}
	}

	public static function setTranslationMode( array $mode ) {
		self::set( self::WHO_MODE, $mode );
		self::notifyJobLog( 'translation_mode_changed', [ 'new' => $mode ] );
	}

	public static function getTranslationMode() {
		return self::get( self::WHO_MODE, [] );
	}

	public static function setTranslateEverythingDefault() {
		if ( self::get( self::TRANSLATE_EVERYTHING, null ) === null ) {
			self::setTranslateEverything( false );
		}
	}

	public static function shouldTranslateEverything( $default = false ) {
		return self::get( self::TRANSLATE_EVERYTHING, $default );
	}

	public static function setTranslateEverything( $state ) {
		$tmAllowed  = self::isTMAllowed();
		$finalValue = $tmAllowed ? $state : false;

		self::set( self::TRANSLATE_EVERYTHING, $finalValue );

		self::notifyJobLog( 'translate_everything_changed', [
			'new'              => $finalValue,
			'requested_value'  => $state,
			'tm_allowed'       => $tmAllowed,
			'silently_clamped' => $state !== $finalValue,
		] );
	}

	public static function setHasTranslateEverythingBeenEverUsed( $state = false ) {
		self::set( self::HAS_TRANSLATE_EVERYTHING_BEEN_EVER_USED, $state );
	}

	public static function getHasTranslateEverythingBeenEverUsed() {
		return self::get( self::HAS_TRANSLATE_EVERYTHING_BEEN_EVER_USED, false );
	}

	public static function getTranslateEverything() {
		return self::get( self::TRANSLATE_EVERYTHING, false );
	}


	public static function isTMAllowed() {
		return self::get( self::TM_ALLOWED );
	}

	public static function setTMAllowed( $isTMAllowed ) {
		self::set( self::TM_ALLOWED, $isTMAllowed );
		self::notifyJobLog( 'tm_allowed_changed', [ 'new' => $isTMAllowed ] );
	}

	public static function setReviewMode( $mode ) {
		$allowedOptions = [ null, self::PUBLISH_AND_REVIEW, self::NO_REVIEW, self::HOLD_FOR_REVIEW ];
		if ( Lst::includes( $mode, $allowedOptions ) ) {
			self::set( self::REVIEW_MODE, $mode );
			self::notifyJobLog( 'review_mode_changed', [ 'new' => $mode ] );
		} else {
			self::notifyJobLog( 'review_mode_invalid_value', [
				'rejected_value' => is_scalar( $mode ) ? (string) $mode : gettype( $mode ),
			], true );
		}
	}

	public static function getReviewMode( $default = null ) {
		return self::get( self::REVIEW_MODE, $default );
	}

	public static function shouldBeReviewed() {
		return self::getReviewMode() !== self::NO_REVIEW;
	}

	public static function getLanguageMappings() {
		return self::get( self::LANGUAGES_MAPPING, [] );
	}

	public static function addLanguageMapping( LanguageMapping $languageMapping ) {
		self::set( self::LANGUAGES_MAPPING, Lst::append( $languageMapping, self::getLanguageMappings() ) );
	}

	private static function get( $key, $default = null ) {
		return ( new OptionManager() )->get( self::OPTION_GROUP, $key, $default );
	}

	private static function set( $key, $value ) {
		return ( new OptionManager() )->set( self::OPTION_GROUP, $key, $value );
	}

	public static function getTranslateEverythingDefaultInSetup( $hasPreferredTranslationService = false ) {
		if ( $hasPreferredTranslationService ) {
			return false;
		}

		return PostType::getPublishedCount( 'post' ) + PostType::getPublishedCount( 'page' ) > self::POSTS_LIMIT_FOR_AUTOMATIC_TRANSLATION
			? false
			: true;
	}


	public static function setTranslateEverythingCompletedPosts( array $completed ) {
		self::set( self::TRANSLATE_EVERYTHING_POSTS, $completed );
	}

	public static function getTranslateEverythingCompletedPosts(): array {
		return self::get( self::TRANSLATE_EVERYTHING_POSTS, [] );
	}

	public static function setTranslateEverythingCompletedPackages( array $completed ) {
		self::set( self::TRANSLATE_EVERYTHING_PACKAGES_COMPLETED, $completed );
	}

	public static function getTranslateEverythingCompletedPackages(): array {
		return self::get( self::TRANSLATE_EVERYTHING_PACKAGES_COMPLETED, [] );
	}

	public static function setTranslateEverythingCompletedStrings( array $completed ) {
		self::set( self::TRANSLATE_EVERYTHING_SRTINGS_COMPLETED, $completed );
	}

	public static function getTranslateEverythingCompletedStrings(): array {
		return self::get( self::TRANSLATE_EVERYTHING_SRTINGS_COMPLETED, [] );
	}

	public static function getTranslateEverythingDrafts() {
		return self::get( self::TRANSLATE_EVERYTHING_DRAFTS, 0 );
	}

	public static function setTranslateEverythingDrafts( $isActive ) {
		$result = self::set( self::TRANSLATE_EVERYTHING_DRAFTS, $isActive );
		self::notifyJobLog( 'translate_everything_drafts_changed', [ 'new' => $isActive ] );
		return $result;
	}

	private static function notifyJobLog( $eventId, array $data, $isError = false ) {
		if ( ! class_exists( \WPML\TM\Jobs\JobLog::class ) ) {
			return;
		}
		if ( $isError ) {
			\WPML\TM\Jobs\JobLog::addError( $eventId, $data );
		} else {
			\WPML\TM\Jobs\JobLog::add( $eventId, $data );
		}
	}
}
