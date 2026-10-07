<?php


use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ContentStats\Controller as ContentStatsController;

return [
  'wpml-content-stats' => [
    'src'           => 'public/js/wpml-content-stats.js',
    'prerequisites' => ContentStatsController::class,
    'dataProvider'  => ContentStatsController::class,
    'dependencies'  => [ 'wpml-node-modules' ],
  ],
];
