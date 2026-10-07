<?php

namespace WPML\Core\Component\ReportContentStats\Domain;

use WPML\Core\Component\ReportContentStats\Domain\Query\OriginalContentStatsQueryInterface;
use WPML\Core\Component\ReportContentStats\Domain\Query\TranslationCoverageStatsQueryInterface;

class ContentStatsCalculator {

  private $originalContentStatsQuery;

  private $translationCoverageStatsQuery;


  public function __construct(
    OriginalContentStatsQueryInterface $originalContentStatsQuery,
    TranslationCoverageStatsQueryInterface $translationCoverageStatsQuery
  ) {
    $this->originalContentStatsQuery     = $originalContentStatsQuery;
    $this->translationCoverageStatsQuery = $translationCoverageStatsQuery;
  }


  public function calculateForPostType( string $defaultLangCode, string $postTypeId ) {
    $originalContentStats = $this->originalContentStatsQuery->get( $defaultLangCode, $postTypeId );

    if ( ! $originalContentStats ) {
      return false;
    }

    $translationCoverageStats = $this->translationCoverageStatsQuery->get( $defaultLangCode, $postTypeId );

    $calculatedCoveragePercentagesPerLang = [];

    foreach ( $translationCoverageStats as $translationCoverageStat ) {
      $calculatedCoveragePercentagesPerLang[ $translationCoverageStat->getLanguageCode() ]
        = $this->calculatePercentagePerLang(
          $originalContentStats->getCharactersCount(),
          $translationCoverageStat->getTranslatedOriginalContentCharsCount()
        );
    }

    return new PostTypeStats(
      $postTypeId,
      $originalContentStats->getPostsCount(),
      $originalContentStats->getCharactersCount(),
      $calculatedCoveragePercentagesPerLang
    );
  }


  private function calculatePercentagePerLang(
    int $originalContentCharsCount,
    int $translatedOriginalContentCharsCount
  ) {
    return $originalContentCharsCount === 0 ?
      0 :
      round(
        $translatedOriginalContentCharsCount / $originalContentCharsCount * 100,
        2
      );
  }


}
