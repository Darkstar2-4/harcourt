<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post\Query;

use WPML\Core\Component\WordsToTranslate\Domain\Post\JobDto;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Term\Term;
use WPML\Core\Component\WordsToTranslate\Domain\TranslatableDTO;

interface JobQueryInterface {


  public function getTerms( Post $post );


  public function getContentToTranslateForLang( Post $post, string $lang );


  public function useThisContentForItem( $idItem, $content );


}
