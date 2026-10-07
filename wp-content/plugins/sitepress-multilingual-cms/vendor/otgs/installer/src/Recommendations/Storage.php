<?php

namespace OTGS\Installer\Recommendations;

class Storage {
	const ADMIN_NOTICES_OPTION = 'otgs_installer_recommendations_admin_notices_v2';

	public static function save( $key, $data ) {
		$current                                   = get_option( self::ADMIN_NOTICES_OPTION, [] );
		$current[ $data['repository_id'] ][ $key ] = $data;
		update_option( self::ADMIN_NOTICES_OPTION, $current, 'no' );
	}

	public static function exists($pluginSlug,$repositoryId){
		$items = self::getAll();
		return isset($items[ $repositoryId ]) && isset($items[ $repositoryId ][ $pluginSlug ] );
	}

	public static function missing($pluginSlug,$repositoryId){
		return !self::exists($pluginSlug,$repositoryId);
	}

	public static function dismissNotice( $pluginSlug, $repositoryId ) {
		$current = get_option( self::ADMIN_NOTICES_OPTION, [] );
		if ( isset( $current[ $repositoryId ][ $pluginSlug ] ) ) {
			$current[ $repositoryId ][ $pluginSlug ]['notice_dismissed'] = true;
			update_option( self::ADMIN_NOTICES_OPTION, $current, 'no' );
		}
	}

	public static function isDismissed( $pluginSlug, $repositoryId ) {
		$current = get_option( self::ADMIN_NOTICES_OPTION, [] );
		return isset( $current[ $repositoryId ][ $pluginSlug ]['notice_dismissed'] )
		       && $current[ $repositoryId ][ $pluginSlug ]['notice_dismissed'] === true;
	}

	public static function delete( $pluginSlug, $repositoryId ) {
		$current = get_option( self::ADMIN_NOTICES_OPTION, [] );
		unset( $current[ $repositoryId ][ $pluginSlug ] );
		update_option( self::ADMIN_NOTICES_OPTION, $current, 'no' );
	}

	public static function getAll() {
		return get_option( self::ADMIN_NOTICES_OPTION, [] );
	}
}
