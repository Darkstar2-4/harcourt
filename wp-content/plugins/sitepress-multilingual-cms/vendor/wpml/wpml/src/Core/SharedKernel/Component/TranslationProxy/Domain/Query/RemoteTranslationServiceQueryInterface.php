<?php

namespace WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query;

use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\RemoteTranslationServiceDomain;

interface RemoteTranslationServiceQueryInterface {


  public function getCurrent( bool $forceRefreshExtraFields = false );


}
