<?php

namespace WPML\Infrastructure\WordPress\Component\Troubleshooting\TranslationTablesOptimization\Domain\TranslationElements\CompressFix;

use WPML\Core\Component\Troubleshooting\TranslationTablesOptimization\Domain\MigrationDataService\ProcessorInterface;
use WPML\Translation\TranslationElements\FieldCompression;

class FixDoubleCompressionProcessor implements ProcessorInterface {

  private $wpdb;


  public function __construct( $wpdb ) {
    $this->wpdb = $wpdb;
  }


  public function process( array $records ): array {
    $processed  = [];
    $updateData = [];

    foreach ( $records as $record ) {
      $fieldDataResult = FieldCompression::fixDoubleCompression( $record->fieldData );
      $fieldData       = $fieldDataResult['data'];

      $fieldDataTranslatedResult = FieldCompression::fixDoubleCompression( $record->fieldDataTranslated );
      $fieldDataTranslated       = $fieldDataTranslatedResult['data'];

      if ( $fieldDataResult['was_double_compressed'] || $fieldDataTranslatedResult['was_double_compressed'] ) {
        $updateData[] = [
          'tid'                   => $record->tid,
          'field_data'            => $fieldData,
          'field_data_translated' => $fieldDataTranslated,
        ];
      }

      $processed[] = $record->tid;
    }

    if ( ! empty( $updateData ) ) {
      $this->bulkUpdateTranslateTable( $updateData );
    }

    return $processed;
  }


  private function bulkUpdateTranslateTable( array $data ) {
    if ( empty( $data ) ) {
      return;
    }

    $fieldDataCases = [];
    $fieldDataTranslatedCases = [];
    $tidValues = [];

    foreach ( $data as $record ) {
      $tid                        = (int) $record['tid'];
      $fieldDataCases[]           = $this->wpdb->prepare( 'WHEN tid = %d THEN %s', $tid, $record['field_data'] );
      $fieldDataTranslatedCases[] =
        $this->wpdb->prepare( 'WHEN tid = %d THEN %s', $tid, $record['field_data_translated'] );
      $tidValues[]                = $tid;
    }


    $tableName = $this->wpdb->prefix . 'icl_translate';
    $sql       = "UPDATE {$tableName} SET 
      field_data = CASE " . implode( ' ', $fieldDataCases ) . " END,
      field_data_translated = CASE " . implode( ' ', $fieldDataTranslatedCases ) . " END
      WHERE tid IN (" . implode( ',', $tidValues ) . ")";

    $this->wpdb->query( $sql );
  }


}
