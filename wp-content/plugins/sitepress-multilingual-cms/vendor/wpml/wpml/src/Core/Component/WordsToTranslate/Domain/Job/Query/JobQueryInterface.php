<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Job\Query;

use WPML\Core\Component\WordsToTranslate\Domain\TranslatableDTO;

interface JobQueryInterface {


  public function getSourceLang( $id );


  public function getTargetLang( $id );


  public function isAutomatic( $id );


  public function getPreviousAteJobIds( $id );


  public function getJobItemId( $id );


  public function getJobItemType( $id );


  public function getContent( $id );


  public function getWordsToTranslate( $id );


  public function getAutomaticTranslationCosts( $id );


}
