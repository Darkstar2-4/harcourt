<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\ResendUnsolvableJobs;

use WPML\Core\Component\Translation\Application\Service\ResendUnsolvableJobsService;
use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\PHP\Exception\Exception;
use WPML\PHP\Exception\InvalidArgumentException;

class ResendUnsolvableJobsController implements EndpointInterface {

  private $resendService;


  public function __construct( ResendUnsolvableJobsService $resendService ) {
    $this->resendService = $resendService;
  }


  public function handle( $requestData = null ): array {
    $requestData = $requestData ?: [];

    $jobIds    = $requestData['jobIds'] ?? null;
    $batchName = $requestData['batchName'] ?? null;

    if ( $jobIds === null ) {
      throw new InvalidArgumentException( 'jobIds array is required.' );
    }

    if ( ! is_array( $jobIds ) ) {
      throw new InvalidArgumentException( 'jobIds must be an array.' );
    }

    if ( empty( $jobIds ) ) {
      return [
        'success' => false,
        'data'    => 'No job IDs provided.',
      ];
    }

    try {
      $result = $this->resendService->resend( $jobIds, $batchName );

      return [
        'success' => true,
        'data'    => $result,
      ];
    } catch ( Exception $e ) {
      return [
        'success' => false,
        'data'    => 'Error resending jobs: ' . $e->getMessage(),
      ];
    }
  }


}
