<?php

use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Str;
use WPML\LIB\WP\Hooks;

class WPML_Widgets_Support_Frontend implements IWPML_Action {

	const PRIORITY_AFTER_TRANSLATION_APPLIED = 0;

	private $displayFor;

	public function __construct( $current_language ) {
		$this->displayFor = [ null, $current_language, 'all' ];
	}

	public function add_hooks() {
		add_filter( 'widget_block_content', [ $this, 'filterByLanguage' ], self::PRIORITY_AFTER_TRANSLATION_APPLIED );
		add_filter( 'widget_display_callback', [ $this, 'display' ], - PHP_INT_MAX );
	}

	public function filterByLanguage( $content ) {
		$render = function () use ( $content ) {
			return wpml_collect( parse_blocks( $content ) )
				->map( Fns::unary( 'render_block' ) )
				->reduce( Str::concat(), '' );
		};

		return Hooks::callWithFilter( $render, 'pre_render_block', [ $this, 'shouldRender' ], 10, 2 );
	}

	public function shouldRender( $pre_render, $block ) {
		return Lst::includes( Obj::path( [ 'attrs', 'wpml_language' ], $block ), $this->displayFor ) ? $pre_render : '';
	}

	public function display( $instance ) {
		if (
			! $instance ||
			( is_array( $instance ) && $this->it_must_display( $instance ) )
		) {
			return $instance;
		}

		return false;
	}

	private function it_must_display( $instance ) {
		return Lst::includes( Obj::propOr( null, 'wpml_language', $instance ), $this->displayFor );
	}
}
