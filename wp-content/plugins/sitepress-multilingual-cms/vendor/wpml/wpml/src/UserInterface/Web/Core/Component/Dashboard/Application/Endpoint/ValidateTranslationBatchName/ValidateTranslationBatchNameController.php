<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\ValidateTranslationBatchName;

use WPML\Core\Component\Translation\Application\Service\TranslationBatchService\BatchNamePreparer;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\InvalidArgumentException;

class ValidateTranslationBatchNameController implements EndpointInterface {

  private $batchNamePreparer;


  public function __construct( BatchNamePreparer $batchNamePreparer ) {
    $this->batchNamePreparer = $batchNamePreparer;
  }


  public function handle( $requestData = null ): array {
    $batchName = $requestData['batchName'] ?? '';

    if ( ! $batchName ) {
      throw new InvalidArgumentException( 'Invalid batch name provided.' );
    }

    $validBatchName = $this->batchNamePreparer->prepare( $batchName );

    return [ 'batchName' => $validBatchName ];
  }


}
