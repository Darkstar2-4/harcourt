<?php

namespace WPML\BlockEditor\Blocks\LanguageSwitcher;

use WPML\BlockEditor\Blocks\LanguageSwitcher;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\BothLanguages;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\CurrentLanguage;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\LabelTemplateInterface;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\LanguageCode;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\NativeLanguage;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\LanguageItemTemplate;
use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\LanguageSwitcherTemplate;
use WPML\FP\Obj;

class Parser {

	const PATH_LANGUAGE_ITEM = '//*[@data-wpml="language-item"]';
	const PATH_CURRENT_LANGUAGE_ITEM = '//*[@data-wpml="current-language-item"]';
	const PATH_ITEM_LINK = '/*[@data-wpml="link"]';
	const PATH_ITEM_LABEL = '/*[@data-wpml="label"]';
	const PATH_ITEM_CODE_LABEL_TYPE = '/*[@data-wpml-label-type="code"]';
	const PATH_ITEM_CURRENT_LABEL_TYPE = '/*[@data-wpml-label-type="current"]';
	const PATH_ITEM_BOTH_LABEL_TYPE = '/*[@data-wpml-label-type="both"]';
	const PATH_ITEM_NATIVE_LABEL_TYPE = '/*[@data-wpml-label-type="native"]';
	const PATH_ITEM_FLAG_URL = '/*[@data-wpml="flag-url"]';
	const PATH_LANGUAGE_ITEM_CONTAINER = '(//*[@data-wpml="language-item"])[last()]/..';
	const PATH_CONTAINER_TEMPLATE = '(%s)[last()]/..';

	const LABEL_TYPES = [
		CurrentLanguage::class,
		LanguageCode::class,
		NativeLanguage::class,
		BothLanguages::class,
	];

	public function parse( $blockAttrs, $blockHTML, $sourceBlock, $context ) {
		if ( empty( $blockHTML ) ) {
			return null;
		}

		if ( isset( $blockAttrs[ 'fontFamilyValue' ] ) ) {
			$blockHTML = $this->maybeFixFontFamilyInStyle( $blockHTML, $blockAttrs[ 'fontFamilyValue' ] );
		}

		if ( $sourceBlock->name === LanguageSwitcher::BLOCK_NAVIGATION_LANGUAGE_SWITCHER ) {
			$blockHTML = $this->maybeReplaceSubmenuClassnamesForNavBlock( $blockHTML, $blockAttrs, $context );
			$blockHTML = $this->maybeReplaceOrientationClassnamesForNavBlock( $blockHTML, $context );
		}

		$blockDOMDocument = new \DOMDocument();
		libxml_use_internal_errors( true );
		$blockDOMDocument->loadHTML( $blockHTML );
		$errors = libxml_get_errors();


		$domXPath = new \DOMXpath( $blockDOMDocument );

		$currentLanguageItemContainerNode = $this->getContainerNode( self::PATH_CURRENT_LANGUAGE_ITEM, $domXPath );
		$currentLanguageItemLabel         = $this->getLanguageItemlabel( $domXPath, self::PATH_CURRENT_LANGUAGE_ITEM );
		$currentLanguageItemTemplate      = new LanguageItemTemplate(
			$this->getTemplateNode( self::PATH_CURRENT_LANGUAGE_ITEM, $currentLanguageItemContainerNode, $domXPath ),
			$currentLanguageItemContainerNode,
			$currentLanguageItemLabel
		);

		$languageItemContainerNode = $this->getContainerNode( self::PATH_LANGUAGE_ITEM, $domXPath );
		$languageItemLabel         = $this->getLanguageItemlabel( $domXPath, self::PATH_LANGUAGE_ITEM );
		$languageItemTemplateNode  = $this->getTemplateNode( self::PATH_LANGUAGE_ITEM, $languageItemContainerNode, $domXPath );
		$languageItemTemplate      = new LanguageItemTemplate(
			$languageItemTemplateNode,
			$languageItemContainerNode,
			$languageItemLabel
		);

		return new LanguageSwitcherTemplate( $languageItemTemplate, $currentLanguageItemTemplate, $blockDOMDocument );
	}

	private function getContainerNode( $selector, $domQuery ) {
		return $domQuery->query( sprintf( self::PATH_CONTAINER_TEMPLATE, $selector ) )->item( 0 );
	}

	private function getTemplateNode( $selector, $container, $domQuery ) {
		$itemQuery = $domQuery->query( $selector );
		$firstItem = $itemQuery->item( 0 );

		if ( empty( $firstItem ) ) {
			return null;
		}
		$template = $firstItem->cloneNode( true );
		foreach ( $itemQuery as $item ) {
			if ( $item instanceof \DOMElement ) {
				$container->removeChild( $item );
			} else if ( $item instanceof \DOMNode ) {
				$item->remove();
			}
		}

		return $template;
	}

	private function getLanguageItemlabel( $DOMXpath, $XPathPrefix ) {
		foreach ( static::LABEL_TYPES as $labelTypeClass ) {
			$labelType = new $labelTypeClass();
			if ( $labelType->matchesXPath( $DOMXpath, $XPathPrefix ) ) {
				return $labelType;
			}
		}

		return null;
	}

	private function maybeFixFontFamilyInStyle( $blockHTML, $fontFamilyValue ) {
		$fontFamilyValuePattern = '/\\--font-family:' . preg_quote( $fontFamilyValue ) . '/';

		$convertDoubleQuoteToXMLEntity = function ( $matches ) {
			return str_replace( '"', '&quot;', $matches[ 0 ] );
		};

		return preg_replace_callback( $fontFamilyValuePattern, $convertDoubleQuoteToXMLEntity, $blockHTML );
	}

	private function maybeReplaceSubmenuClassnamesForNavBlock( $blockHTML, $blockAttrs, $context ) {
		$navigationLsHasSubMenuInSameBlock = Obj::propOr( null, 'navigationLsHasSubMenuInSameBlock', $blockAttrs );

		if ( ! $navigationLsHasSubMenuInSameBlock ) {
			$openOnClick = Obj::propOr( null, 'layoutOpenOnClick', $blockAttrs );
			$showArrow   = Obj::propOr( null, 'layoutShowArrow', $blockAttrs );
		} else {
			$submenuVisibility = Obj::propOr( null, 'submenuVisibility', $context );
			$openOnClick       = null !== $submenuVisibility
				? 'click' === $submenuVisibility
				: Obj::propOr( null, 'openSubmenusOnClick', $context );
			$showArrow         = Obj::propOr( null, 'showSubmenuIcon', $context );
		}

		if ( $openOnClick !== null ) {
			$openOnClickClass = 'open-on-click';
			$openOnHoverClass = 'open-on-hover-click';

			$blockHTML = $openOnClick
				? str_replace( $openOnHoverClass, $openOnClickClass, $blockHTML )
				: str_replace( $openOnClickClass, $openOnHoverClass, $blockHTML );
		}

		if ( $showArrow !== null ) {
			$blockHTML = ! $showArrow
				? str_replace( 'wpml-ls-dropdown', 'wpml-ls-dropdown hide-arrow', $blockHTML )
				: $blockHTML;

		}

		return $blockHTML;
	}

	private function maybeReplaceOrientationClassnamesForNavBlock( $blockHTML, $context ) {
		$orientation = Obj::pathOr( null, [ 'layout', 'orientation' ], $context );

		if ( $orientation === null ) {
			return $blockHTML;
		}

		$verticalClasses   = [ 'vertical-list', 'isVertical' ];
		$horizontalClasses = [ 'horizontal-list', 'isHorizontal' ];

		return $orientation === 'horizontal'
			? str_replace( $verticalClasses, $horizontalClasses, $blockHTML )
			: str_replace( $horizontalClasses, $verticalClasses, $blockHTML );
	}
}
