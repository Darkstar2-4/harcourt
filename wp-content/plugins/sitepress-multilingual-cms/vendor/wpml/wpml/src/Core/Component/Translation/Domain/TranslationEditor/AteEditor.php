<?php

namespace WPML\Core\Component\Translation\Domain\TranslationEditor;

use WPML\Core\SharedKernel\Component\Translation\Domain\TranslationEditorType;

class AteEditor implements EditorInterface {

  private $editorJobId;


  public function __construct( $editorJobId = null ) {
    $this->editorJobId = $editorJobId;
  }


  public function get(): string {
    return TranslationEditorType::ATE;
  }


  public function getEditorJobId() {
    return $this->editorJobId;
  }


}
