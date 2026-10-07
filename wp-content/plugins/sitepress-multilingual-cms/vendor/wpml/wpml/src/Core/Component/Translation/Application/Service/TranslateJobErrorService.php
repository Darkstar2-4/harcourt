<?php
namespace WPML\Core\Component\Translation\Application\Service;

use WPML\Core\Component\Translation\Domain\Entity\JobError;
use WPML\Core\Component\Translation\Domain\Repository\JobErrorRepositoryInterface;

class TranslateJobErrorService {

  private $repository;


  public function __construct( JobErrorRepositoryInterface $repository ) {
    $this->repository = $repository;
  }


  public function logError(
    int $jobId,
    int $ateJobId,
    string $errorType,
    string $errorMessage,
    array $errorData = []
  ) {

    $existing = $this->repository->findByJobId( $jobId );

    if ( $existing ) {
      if ( $existing->getErrorType() === $errorType && $existing->getErrorMessage() === $errorMessage ) {
        $this->repository->incrementCounter( $jobId );
        return;
      }

      $this->repository->delete( $jobId );
    }

    $jobError = new JobError(
      $jobId,
      $ateJobId,
      $errorType,
      $errorMessage,
      $errorData
    );

    $this->repository->insert( $jobError );
  }


  public function deleteError( int $jobId ) {
    $this->repository->delete( $jobId );
  }


  public function getCount(): int {
    return $this->repository->count();
  }


}
