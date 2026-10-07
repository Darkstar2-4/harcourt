<?php

namespace WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs;

use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationDto;
use WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder\BatchStringIdExtractor;
use WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder\SendToTranslationDtoBuilder;
use WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobs\DtoCollectionBuilder\TranslationGrouper;
use WPML\Core\Component\Translation\Domain\Translation;

class SendToTranslationDtoCollectionBuilder {

  private $batchStringIdExtractor;

  private $translationGrouper;

  private $dtoBuilder;


  public function __construct(
    BatchStringIdExtractor $batchStringIdExtractor,
    TranslationGrouper $translationGrouper,
    SendToTranslationDtoBuilder $dtoBuilder
  ) {
    $this->batchStringIdExtractor = $batchStringIdExtractor;
    $this->translationGrouper     = $translationGrouper;
    $this->dtoBuilder             = $dtoBuilder;
  }


  public function buildCollection( string $batchName, array $translations ): array {
    $batchIdToStringIdsMap = $this->batchStringIdExtractor->extract( $translations );

    $groupedBySourceLanguage = $this->translationGrouper->groupBySourceLanguage( $translations );

    $dtos = [];
    foreach ( $groupedBySourceLanguage as $sourceLanguage => $translationsGroup ) {
      $dtos[ $sourceLanguage ] = $this->dtoBuilder->build(
        $batchName,
        $sourceLanguage,
        $translationsGroup,
        $batchIdToStringIdsMap
      );
    }

    return $dtos;
  }


}
