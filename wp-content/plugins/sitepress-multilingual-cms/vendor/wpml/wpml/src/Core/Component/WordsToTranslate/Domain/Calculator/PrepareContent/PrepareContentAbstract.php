<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Calculator\PrepareContent;

abstract class PrepareContentAbstract {

  protected $rules;

  protected $splitter;


  public function prepareForDiff( string $content ) {
    $content = $this->rules->applyRules( $content );
    return $this->splitter->stringToArray( $content );
  }


}
