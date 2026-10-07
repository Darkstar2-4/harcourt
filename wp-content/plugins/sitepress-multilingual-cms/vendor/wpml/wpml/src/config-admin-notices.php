<?php

namespace WPML;

use WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Endpoint\DismissNoticeController;
use WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\StartUsingDashboardNoticeController;
use WPML\UserInterface\Web\Core\Component\Notices\SwitchToAte\Application\SwitchToAteNoticeController;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\ExistingPage\PostEditPage;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\ExistingPage\PostListingPage;
use WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config\ExistingPage\WpmlDashboardPage;

return [
  'wpml-start-using-dashboard-notice' => [
    'controller' => StartUsingDashboardNoticeController::class,
    'onPages'    => [ PostListingPage::class, PostEditPage::class ],
    'scripts'    => [
      [
        'id'            => 'notice-promote-using-dashboard',
        'src'           => 'public/js/notice-promote-using-dashboard.js',
        'dependencies'  => [ 'wpml-node-modules', 'wp-i18n', 'lodash' ],
        'prerequisites' => StartUsingDashboardNoticeController::class,
        'dataProvider'  => StartUsingDashboardNoticeController::class,
      ],
    ],
    'styles'     => [
      'src'          => 'public/css/notice-promote-using-dashboard.css',
      'dependencies' => [ 'otgs-icons' ]
    ],
    'endpoints'  => [
      'dismissusetmdashboardnotice' => [
        'path'    => '/usetmdashboardnotice/dismiss',
        'handler' => DismissNoticeController::class,
      ],
    ],
  ],
  'wpml-switch-to-ate-notice' => [
    'controller' => SwitchToAteNoticeController::class,
    'onPages'    => [ WpmlDashboardPage::class ],
    'capability' => 'manage_options',
    'scripts'    => [
      [
        'id'            => 'wpml-switch-to-ate-notice',
        'src'           => 'public/js/notice-switch-to-ate.js',
        'dependencies'  => [ 'wpml-dashboard' ]
      ],
    ],
    'styles'     => [
      'src'          => 'public/css/notice-switch-to-ate.css'
    ],
  ],
];
