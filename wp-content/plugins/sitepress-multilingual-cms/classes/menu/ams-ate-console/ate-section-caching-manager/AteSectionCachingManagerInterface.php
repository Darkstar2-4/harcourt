<?php

interface AteSectionCachingManagerInterface {

	public function getCachedAppData( $amsConstructor );

	public function cacheApp( $appContent, $invalidateIn = 1 );
}
