<?php

namespace WPML\Core\Port;

interface PluginInterface {


  public function getVersion();


  public function getVersionWithoutSuffix();


  public function getVersionWhenSetupRan();


  public function getVersionWhenSetupRanWithoutSuffix();


  public function isSetupComplete();


  public function getLanguageHomeUrl( string $languageCode ): string;


  public function getATEHost(): string;


  public function getAMSHost(): string;


}
