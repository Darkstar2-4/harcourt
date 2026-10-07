<?php

namespace WPML\PB\Elementor\V4;

use WPML\FP\Obj;
use WPML\LIB\WP\Hooks as WPHooks;

use function WPML\FP\spreadArgs;

class Hooks implements \IWPML_Frontend_Action, \IWPML_DIC_Action {

	const V4_WIDGET_PREFIX      = 'e-';
	const TYPE_KEY              = '$$type';
	const DESTINATION_TYPE_PATH = [ 'value', 'destination', self::TYPE_KEY ];
	const LINK_QUERY_ID_PATH    = [ 'value', 'destination', 'value', 'id', 'value' ];
	const COMPONENT_ID_PATH     = [ 'component_instance', 'value', 'component_id', 'value' ];

	private $sitepress;

	public function __construct( \SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function add_hooks() {
		WPHooks::onFilter( 'elementor/frontend/builder_content_data' )
			->then( spreadArgs( [ $this, 'translateContentIds' ] ) );
	}

	public function translateContentIds( array $data ) {
		foreach ( $data as &$element ) {
			if ( $this->isV4Widget( $element ) ) {
				$element['settings'] = $this->translateSettingsIds( $element['settings'] );
			}

			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->translateContentIds( $element['elements'] );
			}
		}

		return $data;
	}

	private function isV4Widget( array $element ) {
		$widgetType = $element['widgetType'] ?? null;

		return $widgetType
			&& strpos( $widgetType, self::V4_WIDGET_PREFIX ) === 0
			&& isset( $element['settings'] )
			&& is_array( $element['settings'] );
	}

	private function translateSettingsIds( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( $this->isLinkWithQueryDestination( $value ) ) {
				$settings[ $key ] = $this->translateIdInPath( $value, self::LINK_QUERY_ID_PATH );
			}
		}

		$settings = $this->translateIdInPath( $settings, self::COMPONENT_ID_PATH );

		return $settings;
	}

	private function isLinkWithQueryDestination( $value ) {
		return is_array( $value )
			&& 'link' === Obj::prop( self::TYPE_KEY, $value )
			&& 'query' === Obj::path( self::DESTINATION_TYPE_PATH, $value )
			&& null !== Obj::path( self::LINK_QUERY_ID_PATH, $value );
	}

	private function translateIdInPath( array $data, array $path ) {
		$postId = Obj::path( $path, $data );

		if ( ! is_numeric( $postId ) ) {
			return $data;
		}

		$postType = get_post_type( (int) $postId );
		if ( ! $postType ) {
			return $data;
		}

		$translatedId = $this->sitepress->get_object_id( (int) $postId, $postType );

		if ( $translatedId ) {
			$data = Obj::assocPath( $path, $translatedId, $data );
		}

		return $data;
	}
}
