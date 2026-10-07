<?php


use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\PostHog\Controller as PostHogShouldRecordController;

return [
  'check-posthog-should-record' => [
    'src'           => 'public/js/check-posthog-should-record.js',
    'prerequisites' => PostHogShouldRecordController::class,
    'dataProvider'  => PostHogShouldRecordController::class,
    'dependencies'  => [ 'wpml-node-modules' ],
  ],
];
