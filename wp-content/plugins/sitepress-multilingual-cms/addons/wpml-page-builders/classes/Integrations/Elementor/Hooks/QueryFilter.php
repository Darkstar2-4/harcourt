<?php

namespace WPML\PB\Elementor\Hooks;

use WPML\Convert\Ids;
use WPML\FP\Fns;
use WPML\FP\Maybe;
use WPML\LIB\WP\Hooks;
use WPML\PB\Elementor\DataConvert;
use WPML_Elementor_Data_Settings;

use function WPML\FP\spreadArgs;

class QueryFilter implements \IWPML_Frontend_Action, \IWPML_DIC_Action {

	private $sitepress;

	private $wpmlTermTranslation;

	public function __construct( \SitePress $sitepress, \WPML_Term_Translation $wpmlTermTranslation ) {
		$this->sitepress           = $sitepress;
		$this->wpmlTermTranslation = $wpmlTermTranslation;
	}

	public function add_hooks() {
		Hooks::onFilter( 'get_post_metadata', 10, 4 )
			->then( spreadArgs( Fns::withoutRecursion( Fns::identity(), [ $this, 'translateQueryIds' ] ) ) );
	}

	public function translateQueryIds( $value, $object_id, $meta_key, $single ) {
		if ( WPML_Elementor_Data_Settings::META_KEY_DATA === $meta_key && $single ) {
			return Maybe::of( get_post_meta( $object_id, WPML_Elementor_Data_Settings::META_KEY_DATA, true ) )
				->map(
					function ( $data ) {
						return DataConvert::unserialize( $data, false );
					}
				)
				->map(
					function ( $data ) {
						return $this->recursivelyTranslateQueryIds( $data );
					}
				)
				->map(
					function ( $data ) {
						return DataConvert::serialize( $data, false );
					}
				)
				->getOrElse( $value );
		}

		return $value;
	}

	private function recursivelyTranslateQueryIds( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->recursivelyTranslateQueryIds( $value );
			}
		} elseif ( is_object( $data ) ) {
			if ( ! empty( $data->elements ) ) {
				$data->elements = $this->recursivelyTranslateQueryIds( $data->elements );
			}

			$data = $this->translateSettingsIds( $data );
		}

		return $data;
	}

	private function translateSettingsIds( $data ) {
		if ( empty( $data->settings ) ) {
			return $data;
		}

		$termIdProperties = $this->getTermIdProperties();
		foreach ( $termIdProperties as $property ) {
			if ( ! empty( $data->settings->$property ) ) {
				$data->settings->$property = $this->convertTermTaxonomyIds( $data->settings->$property );
			}
		}

		$postIdProperties = $this->getPostIdProperties();
		foreach ( $postIdProperties as $property ) {
			if ( ! empty( $data->settings->$property ) ) {
				$data->settings->$property = Ids::convert( $data->settings->$property, Ids::ANY_POST );
			}
		}

		return $data;
	}

	private function getTermIdProperties() {
		$properties = [
			'post_query_include_term_ids',
			'post_query_exclude_term_ids',
			'product_query_include_term_ids',
			'product_query_exclude_term_ids',
			'query_include_term_ids',
			'query_exclude_term_ids',
		];

		return apply_filters( 'wpml_pb_elementor_query_term_id_properties', $properties );
	}

	private function getPostIdProperties() {
		$properties = [
			'post_query_posts_ids',
			'query_posts_ids',
		];

		return apply_filters( 'wpml_pb_elementor_query_post_id_properties', $properties );
	}

	private function convertTermTaxonomyIds( $ids ) {
		$currentLanguage = $this->sitepress->get_current_language();

		$translateTermTaxonomyId = function ( $termTaxonomyId ) use ( $currentLanguage ) {
			return $this->wpmlTermTranslation->element_id_in( $termTaxonomyId, $currentLanguage, true );
		};

		return wpml_collect( $ids )
			->map( $translateTermTaxonomyId )
			->filter()
			->toArray();
	}
}
