<?php

namespace WPML\Infrastructure\WordPress\Component\ReportContentStats\Domain\Repository;

use WPML\Core\Component\ReportContentStats\Domain\Repository\DailyTranslationCountRepositoryInterface;

class DailyTranslationCountRepository implements DailyTranslationCountRepositoryInterface {

  const TRANSIENT_KEY    = 'wpml-stats-daily-translation-count';
  const SECONDS_IN_A_DAY = 86400;


  public function getCount(): int {
    $count = \get_transient( self::TRANSIENT_KEY );

    return $count !== false ? $count : 0;
  }


  public function increment() {
    $count = $this->getCount();

    \set_transient( self::TRANSIENT_KEY, $count + 1, self::SECONDS_IN_A_DAY );
  }


}
