<?php

namespace WPML\TM\Menu\TranslationServices;


use WPML\FP\Maybe;
use function WPML\FP\invoke;

class ActiveServiceRepository {
	public static function get() {
		global $sitepress;

		$active_service = $sitepress->get_setting( 'translation_service' );

		return $active_service ? new \WPML_TP_Service( $active_service ) : null;
	}

	public static function getId() {
		return Maybe::fromNullable( self::get() )
		            ->map( invoke( 'get_id' ) )
		            ->getOrElse( null );

	}
}