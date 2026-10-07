<?php


use WPML\Core\Component\PostHog\Application\Update\DeletePostHogLegacyLockOption;
use WPML\Core\Component\Translation\Application\Update\Database\Links\AddTablesForLinksTranslation;
use WPML\Core\Component\Translation\Application\Update\Database\TranslationStatus\AddIndexForReviewStatus;
use WPML\Core\Component\WordsToTranslate\Application\Update\Database\TranslateJob\AddColumnWordsToTranslateCount;

return [
  [
    'id' => 1,
    'handler'     => AddIndexForReviewStatus::class,
    'tryOnlyOnce' => true,
    'lazyLoad'   => true,
  ],
  [
    'id' => 2,
    'handler' => AddColumnWordsToTranslateCount::class,
  ],
  [
    'id' => 3,
    'handler' => AddTablesForLinksTranslation::class,
  ],
  [
    'id'          => 4,
    'handler'     => DeletePostHogLegacyLockOption::class,
    'tryOnlyOnce' => true,
  ],
];
