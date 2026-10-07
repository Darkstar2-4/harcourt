<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post\Term;

class Term {

  private $id;

  private $contents = [];


  public function __construct(
    int $id
  ) {
    $this->id = $id;
  }


  public function getId() {
    return $this->id;
  }


  public function addContent( TermContent $content ) {
    $this->contents[] = $content;
  }


  public function getContents() {
    return $this->contents;
  }


}
