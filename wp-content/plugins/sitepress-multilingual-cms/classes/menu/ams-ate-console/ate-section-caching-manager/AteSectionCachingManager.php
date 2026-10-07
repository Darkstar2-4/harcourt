<?php

abstract class AteSectionCachingManager implements AteSectionCachingManagerInterface {

	protected $cacheKey;

	protected $cacheFileName;


	public function getCachedAppData( $amsConstructor ) {
		$filePath = get_transient( $this->cacheKey );

		if ( $filePath && file_exists( $filePath ) ) {
			$errors = [];

			$app         = file_get_contents( $filePath );
			$constructor = wp_json_encode( $amsConstructor );

			if ( ! $app || ! trim( $app ) ) {
				$errors[] = 'Empty response when retrieving the ATE Widget App';
			}

			$headers = [
				$_SERVER['SERVER_PROTOCOL'] . ' ' . '200 OK',
				'content-type: application/javascript'
			];

			return [
				'app'         => $app,
				'constructor' => $constructor,
				'headers'     => $headers,
				'isJs'        => true,
				'errors'      => $errors,
				'response'    => [],
			];
		}

		return false;
	}

	public function hasCachedApp() {
		$filePath = get_transient( $this->cacheKey );

		return $filePath && file_exists( $filePath );
	}

	public function cacheApp( $appContent, $refreshCacheIn = 1 ) {
		$cacheDir = CacheDirectory::get();

		if ( ! file_exists( $cacheDir ) ) {
			wp_mkdir_p( $cacheDir );
		}

		$cacheFilePath = $cacheDir . sanitize_file_name( $this->cacheFileName ) . '.js';
		file_put_contents( $cacheFilePath, $appContent );

		set_transient( $this->cacheKey, $cacheFilePath, $refreshCacheIn * HOUR_IN_SECONDS );

		return $cacheFilePath;
	}

}
