<?php

namespace WPML\PB\Gutenberg\Navigation;

use WPML\Convert\Ids;
use WPML\FP\Obj;
use WPML\FP\Relation;

class Frontend implements \WPML\PB\Gutenberg\Integration {

	public function add_hooks() {
		add_filter( 'render_block_data', [ $this, 'translateNavigationId' ] );
		add_filter( 'block_core_navigation_render_inner_blocks', [ $this, 'translateNavigationLinkId' ] );
	}

	public function translateNavigationId( $data ) {
		if ( Relation::propEq( 'blockName', 'core/navigation', $data ) && Obj::path( [ 'attrs', 'ref' ], $data ) ) {
			$data['attrs']['ref'] = apply_filters( 'wpml_object_id', $data['attrs']['ref'], 'wp_navigation', true );

		}

		return $data;
	}

	public function translateNavigationLinkId( $blocks ) {
		foreach ( $blocks as $key => $block ) {
			if ( Obj::prop( 'id', $block->attributes ) && 'post-type' === Obj::prop( 'kind', $block->attributes ) ) {
				$blocks[ $key ]->attributes['id'] = Ids::convert( $block->attributes['id'], 'any_post', true );
			} elseif ( Obj::prop( 'id', $block->attributes ) && 'taxonomy' === Obj::prop( 'kind', $block->attributes ) ) {
				$blocks[ $key ]->attributes['id'] = Ids::convert( $block->attributes['id'], 'any_term', true );
			}
		}

		return $blocks;
	}

}
