<?php

namespace WPML\Core\Component\Translation\Domain\Priority;

class JobPriority {

    private $jobId;

    private $tier;

    private $rank;

    private $position;


  public function __construct( int $jobId, Tier $tier, Rank $rank, int $position = 0 ) {
      $this->jobId    = $jobId;
      $this->tier     = $tier;
      $this->rank     = $rank;
      $this->position = $position;
  }


  public function getJobId(): int {
      return $this->jobId;
  }


  public function getTier(): Tier {
      return $this->tier;
  }


  public function getRank(): Rank {
      return $this->rank;
  }


  public function getPosition(): int {
      return $this->position;
  }


  public function withPosition( int $position ): self {
      return new self( $this->jobId, $this->tier, $this->rank, $position );
  }


  public function compareTo( JobPriority $other ): int {
      $tierComparison = $this->tier->getValue() <=> $other->getTier()->getValue();
    if ( $tierComparison !== 0 ) {
        return $tierComparison;
    }

      return $this->rank->compareTo( $other->getRank() );
  }


  public function toArray(): array {
      return [
          'tier' => $this->tier->getValue(),
          'rank' => $this->rank->getValues(),
      ];
  }


}
