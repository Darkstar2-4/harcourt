<?php

namespace WPML\Core\Component\Translation\Application\Service\TranslationService\BatchBuilder;

use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationDto;
use WPML\Core\Component\Translation\Domain\TranslationBatch\DuplicationBatch;
use WPML\Core\Component\Translation\Domain\TranslationBatch\TranslationBatch;
use WPML\Core\Component\Translation\Domain\TranslationBatch\Validator\IgnoredElement;
use WPML\PHP\Exception\InvalidArgumentException;

interface BatchBuilderInterface {


  public function build( SendToTranslationDto $sendToTranslationDto ): array;


}
