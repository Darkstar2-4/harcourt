<?php

namespace WPML\MediaTranslation\MediaCollector;

return [
	new CollectorBlock(
		'uagb/image',
		[ new PathResolverByString( 'attrs' ), new PathResolverByString( 'id' ) ],
		[ new PathResolverByString( 'attrs' ), new PathResolverByString( 'url' ) ]
	),
	new CollectorBlock(
		'uagb/image-gallery',
		[ new PathResolverByString( 'id' ) ],
		[ new PathResolverByString( 'sizes' ), new PathResolverByString( 'full' ), new PathResolverByString( 'url' ) ],
		[ new PathResolverByString( 'attrs' ), new PathResolverByString( 'mediaGallery' ) ]
	),
];
