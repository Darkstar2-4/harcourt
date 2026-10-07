<?php

namespace WPML\Compatibility\Divi\V5;

use WPML\LIB\WP\Hooks;
use WPML\FP\Str;
use function WPML\FP\spreadArgs;

class MediaUrls implements \IWPML_Backend_Action, \IWPML_Frontend_Action {

	public function add_hooks() {
		Hooks::onFilter( 'wpml_config_array' )
			->then( spreadArgs( [ $this, 'replaceLinkWithMediaUrl' ] ) );
	}

	public function replaceLinkWithMediaUrl( $config ) {
		foreach ( $config['wpml-config']['gutenberg-blocks']['gutenberg-block'] as &$block ) {
			$type = $block['attr']['type'];
			if ( Str::startsWith( 'divi', $type ) ) {
				$this->processKeys( $block );
			}
		}

		return $config;
	}

	private function processKeys( &$data ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => &$value ) {
			if ( 'key' === $key && is_array( $value ) ) {
				if ( isset( $value['attr'] ) ) {
					if ( isset( $value['attr']['name'] ) && 'image' === $value['attr']['name'] ) {
						$this->replaceImageUrlType( $value );
					}
					$this->processKeys( $value );
				} else {
					foreach ( $value as &$keyItem ) {
						if ( isset( $keyItem['attr']['name'] ) && 'image' === $keyItem['attr']['name'] ) {
							$this->replaceImageUrlType( $keyItem );
						}
						$this->processKeys( $keyItem );
					}
				}
			} else {
				$this->processKeys( $value );
			}
		}
	}

	private function replaceImageUrlType( &$imageKey ) {
		if ( ! isset( $imageKey['key']['attr'] ) ) {
			return;
		}

		$attr = &$imageKey['key']['attr'];
		if ( 'url' === ( $attr['name'] ?? '' ) && 'link' === ( $attr['type'] ?? '' ) ) {
			$attr['type'] = 'media-url';
		}
	}
}
