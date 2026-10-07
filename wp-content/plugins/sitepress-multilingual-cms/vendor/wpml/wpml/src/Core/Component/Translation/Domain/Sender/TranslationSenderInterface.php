<?php

namespace WPML\Core\Component\Translation\Domain\Sender;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;

interface TranslationSenderInterface {


  public function send( TranslationBatch $batch ): array;


  public function rollback( TranslationBatch $batch );


}
