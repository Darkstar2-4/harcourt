<?php

namespace WPML\PB\Gutenberg\ReusableBlocks;

use WPML\FP\Fns;

class Integration implements \WPML\PB\Gutenberg\Integration {

	private $translation;

	public function __construct( Translation $translation 	) {
		$this->translation = $translation;
	}

	public function add_hooks() {
		add_filter( 'render_block_data', [ $this, 'convertReusableBlock' ] );
		add_filter( 'render_block', Fns::withoutRecursion( Fns::identity(), [ $this, 'reRenderInnerReusableBlock' ] ), 10, 2 );
	}

	public function convertReusableBlock( array $block ) {
		return $this->translation->convertBlock( $block );
	}

	public function reRenderInnerReusableBlock( $blockContent, $block ) {
		$originalId = Blocks::getReusableId( $block );

		if ( $originalId ) {
			$convertedBlock = $this->translation->convertBlock( $block );

			if ( Blocks::getReusableId( $convertedBlock ) !== $originalId ) {
				return render_block( $convertedBlock );
			}
		}

		return $blockContent;
	}
}
