<?php

namespace WPML;

interface ConfigInterface {


  public function loadRESTEndpoints();


  public function loadAjaxEndpoints();


  public function registerAdminPages();


  public function loadAdminNotices();


  public function loadAdminScripts();


  public function prepareUpdates();


  public function getInterfaceMappings();


  public function getClassDefinitions();


  public function loadContentStatsScripts();


  public function loadCheckPosthogShouldRecordScript();


}
