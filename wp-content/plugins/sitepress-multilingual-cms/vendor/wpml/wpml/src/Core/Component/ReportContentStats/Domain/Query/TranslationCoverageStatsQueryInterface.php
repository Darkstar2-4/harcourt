<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Query;

use WPML\Core\Component\ReportContentStats\Domain\TranslationCoverageStats;

interface TranslationCoverageStatsQueryInterface {


  public function get( string $defaultLanguageCode, string $postTypeName ): array;


}
