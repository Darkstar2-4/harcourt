<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Post\Query;

use WPML\Core\Component\WordsToTranslate\Domain\Post\Post;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Term\Term;
use WPML\Core\Component\WordsToTranslate\Domain\Post\Term\TermContent;


interface TranslationQueryInterface {


  public function getLastTranslatedOriginalContentForPost(
    $post,
    $lang,
    $fieldsToTranslate
  );


  public function isTermTranslatable( Term $term, string $lang );


  public function getLastTranslatedOriginalContentForTermContent(
    Term $term,
    TermContent $termContent,
    string $langCode
  );


}
