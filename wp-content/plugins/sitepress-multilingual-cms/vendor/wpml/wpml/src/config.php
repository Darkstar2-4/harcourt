<?php

return [
  'interfaceMappings' => require __DIR__ . '/config-interface-mappings.php',

  'classDefinitions' => require __DIR__ . '/config-class-definitions.php',

  'adminPages' => require __DIR__ . '/config-admin-pages.php',

  'adminNotices' => require __DIR__ . '/config-admin-notices.php',

  'endpoints' => require __DIR__ . '/config-endpoints.php',

  'scripts' => require __DIR__ . '/config-scripts.php',

  'updates' => require __DIR__ . '/config-updates.php',

  'contentStatsScripts' =>  require __DIR__ . '/config-content-stats-scripts.php',

  'checkPosthogShouldRecord' =>  require __DIR__ . '/config-posthog-should-record.php',

];
