<?php

namespace WPML\Core\Component\ReportContentStats\Domain\Query;

use WPML\Core\Component\ReportContentStats\Domain\OriginalContentStats;

interface OriginalContentStatsQueryInterface {


  public function get( string $defaultLanguageCode, string $postTypeName );


}
