<?php

namespace WPML\Element\API;

use WPML\Collect\Support\Traits\Macroable;
use WPML\FP\Lst;
use WPML\LIB\WP\Post;
use function WPML\FP\curryN;

class PostTranslations {

	use Macroable;

	public static function init() {

		self::macro( 'setAsSource', curryN( 2, self::withPostType( Translations::setAsSource() ) ) );

		self::macro( 'setAsTranslationOf', curryN( 3, self::withPostType( Translations::setAsTranslationOf() ) ) );

		self::macro( 'get', curryN( 1, self::withPostType( Translations::get() ) ) );

		self::macro( 'getInLanguage', curryN( 2, self::withPostType( Translations::getInLanguage() ) ) );

		self::macro( 'getInCurrentLanguage', curryN( 1, self::withPostType( Translations::getInCurrentLanguage() ) )  );

		self::macro( 'getIfOriginal', curryN( 1, self::withPostType( Translations::getIfOriginal() ) ) );

		self::macro( 'getOriginal', curryN( 1, self::withPostType( Translations::getOriginal() ) ) );

		self::macro( 'getOriginalId', curryN( 1, self::withPostType( Translations::getOriginalId() ) ) );
	}

	public static function withPostType( $fn ) {
		return function () use ( $fn ) {
			$args = func_get_args();

			return call_user_func_array( $fn, Lst::insert( 1, 'post_' . Post::getType( $args[0] ), $args ) );
		};
	}
}

PostTranslations::init();
