<?php

namespace WPML\Core\Component\Translation\Domain\TranslationBatch\Validator;

use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;

interface ValidatorInterface {


  public function validate( TranslationBatch $translationBatch ): array;


}
