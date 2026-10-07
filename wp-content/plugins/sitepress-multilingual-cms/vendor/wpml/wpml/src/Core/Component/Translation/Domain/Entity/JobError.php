<?php

namespace WPML\Core\Component\Translation\Domain\Entity;

class JobError {

  private $jobId;

  private $ateJobId;

  private $errorType;

  private $errorMessage;

  private $errorData;

  private $counter;


  public function __construct(
    int $jobId,
    int $ateJobId,
    string $errorType,
    string $errorMessage,
    array $errorData = [],
    int $counter = 1
  ) {
    $this->jobId = $jobId;
    $this->ateJobId = $ateJobId;
    $this->errorType = $errorType;
    $this->errorMessage = $errorMessage;
    $this->errorData = $errorData;
    $this->counter = $counter;
  }


  public function getJobId(): int {
    return $this->jobId;
  }


  public function getAteJobId(): int {
    return $this->ateJobId;
  }


  public function getErrorType(): string {
    return $this->errorType;
  }


  public function getErrorMessage(): string {
    return $this->errorMessage;
  }


  public function getErrorData(): array {
    return $this->errorData;
  }


  public function getCounter(): int {
    return $this->counter;
  }


}
