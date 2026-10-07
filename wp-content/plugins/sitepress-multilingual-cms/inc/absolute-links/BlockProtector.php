<?php

namespace WPML\AbsoluteLinks;

class BlockProtector {

	private $protectedBlocks = [];

	public function protect( $text ) {
		if ( ! function_exists( 'has_blocks' ) || ! has_blocks( $text ) ) {
			return $text;
		}

		$integrationClass = \WPML_Gutenberg_Integration::class;

		$decodeForwardSlashes = function ( $str ) {
			return str_replace( '\\/', '/', $str );
		};

		$replaceBlockWithPlaceholder = function ( $text, $block ) {
			$key = md5( $block );

			if ( false !== mb_strpos( $text, $block ) ) {
				$this->protectedBlocks[ $key ] = $block;

				return str_replace( $block, $key, $text );
			}

			if ( preg_match( '#^<!-- wp:(\S+)\s#', $block, $m ) ) {
				$isSelfClosing = mb_substr( rtrim( $block ), -4 ) === '/-->';
				$originalBlock = $this->findBlockInText( $text, $m[1], $isSelfClosing );

				if ( null !== $originalBlock ) {
					$key                           = md5( $originalBlock );
					$this->protectedBlocks[ $key ] = $originalBlock;

					return str_replace( $originalBlock, $key, $text );
				}
			}

			return $text;
		};

		return wpml_collect( \WPML_Gutenberg_Integration::parse_blocks( $text ) )
			->map( [ $integrationClass, 'sanitize_block' ] )
			->filter( [ $integrationClass, 'has_non_empty_attributes' ] )
			->map( [ $integrationClass, 'render_block' ] )
			->map( $decodeForwardSlashes )
			->reduce( $replaceBlockWithPlaceholder, $text );
	}

	protected function findBlockInText( $text, $blockName, $isSelfClosing = false ) {
		$openTag = '<!-- wp:' . $blockName . ' ';

		$openPos = mb_strpos( $text, $openTag );
		if ( false === $openPos ) {
			return null;
		}

		if ( $isSelfClosing ) {
			$endPos = mb_strpos( $text, '/-->', $openPos );
			if ( false === $endPos ) {
				return null;
			}

			return mb_substr( $text, $openPos, $endPos + 4 - $openPos );
		}

		$closeTag    = '<!-- /wp:' . $blockName . ' -->';
		$openTagLen  = mb_strlen( $openTag );
		$closeTagLen = mb_strlen( $closeTag );
		$searchPos   = $openPos + $openTagLen;
		$depth       = 1;
		$textLen     = mb_strlen( $text );

		while ( $depth > 0 && $searchPos < $textLen ) {
			$nextOpen  = mb_strpos( $text, $openTag, $searchPos );
			$nextClose = mb_strpos( $text, $closeTag, $searchPos );

			if ( false === $nextClose ) {
				return null;
			}

			if ( false !== $nextOpen && $nextOpen < $nextClose ) {
				$depth++;
				$searchPos = $nextOpen + $openTagLen;
			} else {
				$depth--;
				if ( 0 === $depth ) {
					return mb_substr( $text, $openPos, $nextClose + $closeTagLen - $openPos );
				}
				$searchPos = $nextClose + $closeTagLen;
			}
		}

		return null;
	}

	public function unProtect( $text ) {
		foreach ( $this->protectedBlocks as $key => $value ) {
			$text = str_replace( $key, $value, $text );
		}

		return $text;
	}
}
