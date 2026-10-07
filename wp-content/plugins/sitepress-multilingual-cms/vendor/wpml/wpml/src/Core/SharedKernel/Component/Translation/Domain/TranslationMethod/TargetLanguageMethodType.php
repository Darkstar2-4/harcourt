<?php

namespace WPML\Core\SharedKernel\Component\Translation\Domain\TranslationMethod;

class TargetLanguageMethodType {
  const TRANSLATION_SERVICE = 'translation-service';
  const LOCAL_TRANSLATOR = 'local-translator';
  const AUTOMATIC = 'automatic';
  const DUPLICATE = 'duplicate';
}
