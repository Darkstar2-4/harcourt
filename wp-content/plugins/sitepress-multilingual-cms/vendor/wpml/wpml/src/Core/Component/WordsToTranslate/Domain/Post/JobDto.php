<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post;

class JobDto {

  private $content;

  private $fields;


  public function __construct( $content, $fields ) {
    $this->content = $content;
    $this->fields = $fields;
  }


  public function getContent(): string {
    return $this->content;
  }


  public function getTranslatableFields() {
    return $this->fields;
  }


}
