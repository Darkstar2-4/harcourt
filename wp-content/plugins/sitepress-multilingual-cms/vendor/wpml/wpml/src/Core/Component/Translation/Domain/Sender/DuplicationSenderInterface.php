<?php

namespace WPML\Core\Component\Translation\Domain\Sender;

use WPML\Core\Component\Translation\Domain\Translation;
use WPML\Core\Component\Translation\Domain\TranslationBatch\DuplicationBatch;

interface DuplicationSenderInterface {


  public function send( DuplicationBatch $batch ): array;


}
