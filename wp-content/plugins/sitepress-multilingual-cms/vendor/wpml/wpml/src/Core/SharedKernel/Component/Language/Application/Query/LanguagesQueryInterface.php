<?php

namespace WPML\Core\SharedKernel\Component\Language\Application\Query;

use WPML\Core\SharedKernel\Component\Language\Application\Query\Dto\LanguageDto;


interface LanguagesQueryInterface {


  public function getDefaultCode(): string;


  public function getCurrentLanguageCode(): string;


  public function getDefault(): LanguageDto;


  public function getActive();


  public function getSecondary( bool $withRespectToCurrentLang = false, $currentLang = null );


}
