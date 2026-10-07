<?php


use WPML\UserInterface\Web\Core\Component\PostHog\Application\PostHogController;
use WPML\UserInterface\Web\Core\Component\WpmlProxy\Application\WpmlProxyAutoDisableController;
use WPML\UserInterface\Web\Core\Component\WpmlProxy\Application\WpmlProxyAutoEnableController;

return [
  'wpml-node-modules'          => [
    'src'          => 'public/js/node-modules.js',
    'onlyRegister' => true,
  ],
  'wpml-posthog'               => [
    'src'           => 'public/js/wpml-posthog.js',
    'dependencies'  => [ 'wpml-node-modules' ],
    'prerequisites' => PostHogController::class,
    'dataProvider'  => PostHogController::class,
  ],
  'wpml-proxy-auto-enable'     => [
    'src'          => 'public/js/wpml-proxy-auto-enable.js',
    'dataProvider' => WpmlProxyAutoEnableController::class,
    'prerequisites' => WpmlProxyAutoEnableController::class,
  ],
  'wpml-proxy-auto-disable'     => [
    'src'          => 'public/js/wpml-proxy-auto-disable.js',
    'dataProvider' => WpmlProxyAutoDisableController::class,
    'prerequisites' => WpmlProxyAutoDisableController::class,
  ],
];
