<?php

namespace WPML\Infrastructure\WordPress\SharedKernel\Server\Application\Endpoint\RestStatus;

use Throwable;
use WPML\Core\Port\Endpoint\EndpointInterface;

use function WPML\PHP\Logger\error;

class RestStatusController implements EndpointInterface {


  public function handle( $requestData = null ): array {
    try {
      $getParametersValid = $this->validateGetParameters( $requestData );

      return [
        'success' => true,
        'data'    => [
          'status'         => $getParametersValid ? 'valid' : 'invalid',
          'get_parameters' => $getParametersValid ? 'valid' : 'invalid',
        ]
      ];
    } catch ( Throwable $e ) {
      error(
        'Error checking REST API status: ' . $e->getMessage() . ' | File: '
        . $e->getFile() . ' | Line: ' . $e->getLine() . ' | Trace: '
        . $e->getTraceAsString()
      );

      return [
        'success' => false,
        'message' => $e->getMessage()
      ];
    }
  }


  private function validateGetParameters( $requestData ): bool {
    return is_array( $requestData ) && count( $requestData ) > 0;
  }


}
