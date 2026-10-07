<?php

namespace WPML\TM\Jobs;

use WPML\FP\Fns;
use WPML\FP\Obj;
use WPML\FP\Str;
use WPML\Translation\TranslationElements\FieldCompression;
use function WPML\FP\pipe;

class TermMeta {
	public static function getTermDescription( $iclTranslateJobId, $termTaxonomyId ) {
		global $wpdb;

		$sql = "SELECT field_data_translated
				FROM {$wpdb->prefix}icl_translate
				WHERE job_id = %d AND field_type = 'tdesc_%d'";

		$description = $wpdb->get_var( $wpdb->prepare( $sql, $iclTranslateJobId, $termTaxonomyId ) );

		return $description ? FieldCompression::decompress( $description ) : '';
	}

	public static function getTermMeta( $iclTranslateJobId, $term_taxonomy_id ) {
		return array_merge(
			self::geRegularTermMeta( $iclTranslateJobId, $term_taxonomy_id ),
			self::getTermMetaWithArrayValue( $iclTranslateJobId, $term_taxonomy_id )
		);
	}

	private static function geRegularTermMeta( $iclTranslateJobId, $termTaxonomyId ) {
		global $wpdb;

		$sql = "SELECT field_data_translated, field_type
				FROM {$wpdb->prefix}icl_translate
				WHERE job_id = %d AND field_type LIKE 'tfield-%-%d'";

		$rowset = $wpdb->get_results( $wpdb->prepare( $sql, $iclTranslateJobId, $termTaxonomyId ) );

		foreach ( $rowset as $row ) {
			$row->field_data_translated = FieldCompression::decompress( $row->field_data_translated );
		}

		return $rowset;
	}

	private static function getTermMetaWithArrayValue( $iclTranslateJobId, $termTaxonomyId ) {
		global $wpdb;

		$sql = "SELECT field_data_translated, field_type
				FROM {$wpdb->prefix}icl_translate
				WHERE job_id = %d AND field_type LIKE 'tfield-%-%d_%'";

		$rowset = $wpdb->get_results( $wpdb->prepare( $sql, $iclTranslateJobId, $termTaxonomyId ) );

		$extractFieldName = pipe( Obj::prop( 'field_type' ), Str::match( '/tfield-(.*)-\d/U' ), Obj::prop( 1 ) );

		$extractOptions = function ( $row, $fieldName ) {
			return Str::pregReplace( "/tfield-{$fieldName}-\d+_/U", '', $row->field_type );
		};

		$groupOptions = function ( $carry, $row ) use ( $extractFieldName, $extractOptions ) {
			$fieldName = $extractFieldName( $row );
			if ( ! isset( $carry[ $fieldName ] ) ) {
				$carry[ $fieldName ] = [];
			}

			$options = $extractOptions( $row, $fieldName );

			$metaKeys = array_merge( [ $fieldName ], explode( '_', $options ) );

			return Utils::insertUnderKeys( $metaKeys, $carry, FieldCompression::decompress( $row->field_data_translated ) );
		};

		$recreateJobElement = function ( $data, $fieldType ) use ( $termTaxonomyId ) {
			return (object) [
				'field_type'            => 'tfield-' . $fieldType . '-' . $termTaxonomyId,
				'field_data_translated' => $data,
			];
		};

		return Obj::values( Fns::map( $recreateJobElement, Fns::reduce( $groupOptions, [], $rowset ) ) );
	}
}
