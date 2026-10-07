<?php

define(
  'WPML_VERSION',
  defined( 'ICL_SITEPRESS_SCRIPT_VERSION' )
    ? ICL_SITEPRESS_SCRIPT_VERSION
    : '4.7.0'
);

define( 'WPML_ROOT_DIR', __DIR__ . '/..' );
define( 'WPML_PUBLIC_DIR', WPML_ROOT_DIR . '/public' );


define( 'WPML_CAP_MANAGE_OPTIONS', 'manage_options' );
define( 'WPML_CAP_MANAGE_TRANSLATIONS', 'manage_translations' );
