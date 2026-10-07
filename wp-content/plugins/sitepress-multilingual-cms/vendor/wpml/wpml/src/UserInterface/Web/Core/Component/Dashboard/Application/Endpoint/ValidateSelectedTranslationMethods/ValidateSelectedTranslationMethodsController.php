<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\ValidateSelectedTranslationMethods;

use WPML\Core\Component\Translation\Application\Service\Dto\SendToTranslationDto;
use WPML\Core\Component\Translation\Application\Service\Validator\AssignedTranslationServiceValidatorService;
use WPML\Core\Component\Translation\Application\Service\Validator\AssignedTranslatorsValidatorService;
use WPML\Core\Component\Translation\Application\Service\Validator\Dto\ValidationResultDto;
use WPML\Core\Component\Translation\Application\Service\Validator\TranslationEditorValidatorService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\SharedKernel\Component\TranslationProxy\Domain\Query\FetchRemoteTranslationServiceException;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class ValidateSelectedTranslationMethodsController implements EndpointInterface {

  private $assignedTranslatorsValidatorService;

  private $assignedTranslationServiceValidatorService;

  private $translationEditorValidatorService;


  public function __construct(
    AssignedTranslatorsValidatorService $assignedTranslatorsValidatorService,
    AssignedTranslationServiceValidatorService $assignedTranslationServiceValidatorService,
    TranslationEditorValidatorService $translationEditorValidatorService
  ) {
    $this->assignedTranslatorsValidatorService        = $assignedTranslatorsValidatorService;
    $this->assignedTranslationServiceValidatorService = $assignedTranslationServiceValidatorService;
    $this->translationEditorValidatorService          = $translationEditorValidatorService;
  }


  public function handle( $requestData = null ): array {
    $requestData       = $requestData ?: [];
    $validationResults = [];

    try {
      $sendToTranslationDto = SendToTranslationDto::fromArray( $requestData );

      $validationResults[] = $this->assignedTranslatorsValidatorService->validate(
        $sendToTranslationDto
      )->toArray();

      $validationResults[] = $this->assignedTranslationServiceValidatorService->validate(
        $sendToTranslationDto
      )->toArray();

      $validationResults[] = $this->translationEditorValidatorService->validate(
        $sendToTranslationDto
      )->toArray();

    } catch ( InvalidArgumentException $e ) {
      $validationResults[] = ( new ValidationResultDto( 'invalid-argument', false ) )->toArray();
    } catch ( FetchRemoteTranslationServiceException $e ) {
      $validationResults[] = ( new ValidationResultDto(
        $this->assignedTranslationServiceValidatorService->getType(),
        false
      ) )->toArray();
    }

    return $validationResults;
  }


}
