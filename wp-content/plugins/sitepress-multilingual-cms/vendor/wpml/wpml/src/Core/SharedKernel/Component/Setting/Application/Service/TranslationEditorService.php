<?php

namespace WPML\Core\SharedKernel\Component\Setting\Application\Service;

use WPML\Core\SharedKernel\Component\Setting\Application\Query\TranslationEditorQueryInterface;
use WPML\Core\SharedKernel\Component\Setting\Domain\TranslationEditorSetting;

class TranslationEditorService {

  private $translationEditorQuery;


  public function __construct(
    TranslationEditorQueryInterface $translationEditorQuery
  ) {
    $this->translationEditorQuery = $translationEditorQuery;
  }


  public function getTranslationEditorSetting() {
    return $this->translationEditorQuery->getTranslationEditorSetting();
  }


}
