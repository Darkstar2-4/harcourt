<?php

namespace WPML\Core\Component\Translation\Domain\Priority;

class OrderingPayload {

    const MODE_WPML_PRIORITY_V1 = 'wpml_priority_v1';

    private $mode;

    private $homePostId;

    private $positions;

    private $meta;


  public function __construct(
        string $mode,
        $homePostId,
        array $positions,
        array $meta
    ) {
      $this->mode       = $mode;
      $this->homePostId = $homePostId;
      $this->positions  = $positions;
      $this->meta       = $meta;
  }


  public function getMode(): string {
      return $this->mode;
  }


  public function getHomePostId() {
      return $this->homePostId;
  }


  public function getPositions(): array {
      return $this->positions;
  }


  public function getMeta(): array {
      return $this->meta;
  }


  public function toArray(): array {
      $stringKeys = array_map( 'strval', array_keys( $this->positions ) );
      $positionsWithStringKeys = array_combine( $stringKeys, array_values( $this->positions ) ) ?: [];

      $metaStringKeys = array_map( 'strval', array_keys( $this->meta ) );
      $metaWithStringKeys = array_combine( $metaStringKeys, array_values( $this->meta ) ) ?: [];

      return [
          'mode'         => $this->mode,
          'home_post_id' => $this->homePostId,
          'positions'    => $positionsWithStringKeys,
          'meta'         => $metaWithStringKeys,
      ];
  }


}
