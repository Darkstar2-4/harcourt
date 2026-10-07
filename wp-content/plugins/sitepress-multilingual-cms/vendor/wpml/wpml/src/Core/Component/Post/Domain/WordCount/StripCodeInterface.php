<?php

namespace WPML\Core\Component\Post\Domain\WordCount;

interface StripCodeInterface {


  public function strip( string $content ): string;


}
