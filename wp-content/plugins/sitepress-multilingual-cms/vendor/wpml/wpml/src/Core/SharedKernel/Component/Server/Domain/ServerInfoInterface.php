<?php

namespace WPML\Core\SharedKernel\Component\Server\Domain;

interface ServerInfoInterface {


  public function getPhpVersion(): string;


  public function getDbVersion();


  public function getIniGet( string $key );


  public function getOriginalIniGet( string $key );


  public function getWordPressVersion(): string;


  public function getConstant( string $name, $default = null );


  public function isExtensionLoaded( string $name ): bool;


}
