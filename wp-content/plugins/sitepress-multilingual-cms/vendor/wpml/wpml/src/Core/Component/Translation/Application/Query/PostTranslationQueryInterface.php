<?php

namespace WPML\Core\Component\Translation\Application\Query;

interface PostTranslationQueryInterface {


  public function getOriginalPostId( int $translatedPostId ): int;


}
