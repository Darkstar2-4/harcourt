<?php

namespace WPML\MediaTranslation\MediaCollector;

interface PathResolverInterface {

	public function getValue( $data );

	public function resolvePath( $data );

}

