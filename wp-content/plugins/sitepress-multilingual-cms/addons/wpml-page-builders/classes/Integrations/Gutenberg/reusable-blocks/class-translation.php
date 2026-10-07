<?php

namespace WPML\PB\Gutenberg\ReusableBlocks;

class Translation {

	const POST_TYPE = 'wp_block';

	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function convertBlock( array $block, $lang = null ) {
		if ( Blocks::isReusable( $block ) ) {
			$block['attrs']['ref'] = $this->convertBlockId( $block['attrs']['ref'], $lang );
		}

		return $block;
	}

	public function convertBlockId( $block_id, $lang = null ) {
		return $this->sitepress->get_object_id( $block_id, self::POST_TYPE, true, $lang );
	}

	public function getSourceLang( $post_id ) {
		$details = $this->sitepress->get_element_language_details( $post_id, 'post_' . get_post_type( $post_id ) );

		return isset( $details->source_language_code ) ? $details->source_language_code : null;
	}
}
