<?php

namespace WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery;

use WPML\Core\Component\Post\Application\Query\Criteria\SearchCriteria;
use WPML\Core\Component\Post\Application\Query\Dto\PostWithTranslationStatusDto;
use WPML\Core\Component\Post\Application\Query\Dto\TranslationStatusDto;
use WPML\Core\Component\Translation\Application\Service\CompletedTranslationService;
use WPML\Core\Port\Persistence\ResultCollection;
use WPML\Core\Port\Persistence\ResultCollectionInterface;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationEditorType;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod\TargetLanguageMethodType;
use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationStatus;
use WPML\Infrastructure\WordPress\Component\Item\Application\Query\SearchQuery;
use WPML\PHP\Exception\InvalidArgumentException;
use function WPML\PHP\array_keys_exists;


class ItemWithTranslationStatusDtoMapper {

  const REQUIRED_RAW_KEYS = [
    'ID',
    'post_title',
    'post_status',
    'post_date',
    'post_type',
    'translator_note',
    'use_native_editor'
  ];

  private $completedTranslationService;


  public function __construct( CompletedTranslationService $completedTranslationService ) {
    $this->completedTranslationService = $completedTranslationService;
  }


  public function mapCollection(
    ResultCollectionInterface $items,
    ResultCollectionInterface $jobs,
    SearchCriteria $searchCriteria
  ) {
    $mappedItems = [];

    $postJobs = [];
    foreach ( $jobs->getResults() as $job ) {
      $postJobs[ $job['original_element_id'] ][] = $job;
    }

    foreach ( $items->getResults() as $rawData ) {
      $mappedItems[] = $this->mapSingle( $rawData, $postJobs[ $rawData['ID'] ] ?? [], $searchCriteria );
    }

    return new ResultCollection( $mappedItems );
  }


  private function mapSingle( array $rawData, array $jobs, SearchCriteria $searchCriteria ) {
    if ( ! array_keys_exists( self::REQUIRED_RAW_KEYS, $rawData ) ) {
      throw new InvalidArgumentException(
        'Invalid raw data for ItemWithTranslationStatusDtoMapper.' .
        'Required keys: ' . implode( ', ', self::REQUIRED_RAW_KEYS )
      );
    }

    $translationStatuses = $this->mapTranslationStatuses( $jobs, $searchCriteria );

    return new PostWithTranslationStatusDto(
      (int) $rawData['ID'],
      $rawData['post_title'],
      $rawData['post_status'],
      $rawData['post_date'],
      $rawData['post_type'],
      $translationStatuses,
      isset( $rawData['word_count'] ) && is_numeric( $rawData['word_count'] ) ? (int) $rawData['word_count'] : null,
      $rawData['translator_note'],
      in_array( $rawData['use_native_editor'], [ 'yes', 'no' ], true ) ? $rawData['use_native_editor'] : ''
    );
  }


  private function mapTranslationStatuses( array $jobs, SearchCriteria $searchCriteria ): array {
    $translationStatuses = [];

    foreach ( $searchCriteria->getTargetLanguageCodes() as $langCode ) {
      $translationStatuses[ $langCode ] = new TranslationStatusDto( 0 );
    }

    foreach ( $jobs as $job ) {
      $langCode = $job['language_code'];

      $status = TranslationStatus::getPostDisplayStatus( $job )->get();

      $isTranslated = $this->completedTranslationService->isTranslationCompleted(
        $status,
        (bool) $job['needs_update'],
        (int) $job['translation_id'],
        (int) $job['element_id']
      );

      $editor       = $this->parseEditor( $job['editor'] );
      $method       = $this->getMethod(
        $status,
        $job['translation_service'] ?: 'NULL',
        (int) $job['automatic'] > 0,
        (int) $job['job_id']
      );

      $translationStatuses[ $langCode ] = new TranslationStatusDto(
        $status,
        $job['review_status'],
        $job['job_id'] ? (int) $job['job_id'] : null,
        $method,
        $editor,
        $isTranslated,
        $job['translator_id'] ? (int) $job['translator_id'] : null,
        isset( $job['editor_job_id'] ) ? (int) $job['editor_job_id'] : null
      );
    }

    return $translationStatuses;
  }


  private function parseEditor( ?string $editor = null ) {
    if ( ! $editor || $editor === 'NULL' ) {
      return TranslationEditorType::NONE;
    }

    if ( $editor === 'wp' ) {
      return TranslationEditorType::WORDPRESS;
    }

    if ( $editor === 'wpml' ) {
      return TranslationEditorType::CLASSIC;
    }

    if ( in_array( $editor, TranslationEditorType::getTypes(), true ) ) {
      return $editor;
    }

    return TranslationEditorType::NONE;
  }


  private function getMethod( int $status, string $translationService, bool $automatic, int $jobId ) {
    $method = null;
    if ( $status === TranslationStatus::DUPLICATE ) {
      $method = TargetLanguageMethodType::DUPLICATE;
    } else if ( $translationService !== 'local' && $translationService !== '' && $translationService !== 'NULL' ) {
      $method = TargetLanguageMethodType::TRANSLATION_SERVICE;
    } else if ( $automatic ) {
      $method = TargetLanguageMethodType::AUTOMATIC;
    } else if ( $jobId ) {
      $method = TargetLanguageMethodType::LOCAL_TRANSLATOR;
    }

    return $method;
  }


}
