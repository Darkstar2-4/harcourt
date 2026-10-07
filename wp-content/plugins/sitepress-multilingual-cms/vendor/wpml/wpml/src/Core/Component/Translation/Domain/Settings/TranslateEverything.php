<?php

namespace WPML\Core\Component\Translation\Domain\Settings;

class TranslateEverything {

  private $isEnabled;

  private $hasEverBeenEnabled;

  private $completedPosts = [];

  private $completedPackages = [];

  private $completedStrings = [];


  public function __construct( bool $isEnabled, bool $hasEverBeenEnabled = false ) {
    $this->isEnabled          = $isEnabled;
    $this->hasEverBeenEnabled = $hasEverBeenEnabled;
  }


  public function isEnabled(): bool {
    return $this->isEnabled;
  }


  public function enable(): self {
    $this->isEnabled          = true;
    $this->hasEverBeenEnabled = true;

    return $this;
  }


  public function disable(): self {
    $this->isEnabled = false;

    return $this;
  }


  public function hasEverBeenEnabled(): bool {
    return $this->hasEverBeenEnabled;
  }


  public function getCompletedPackages(): array {
    return $this->completedPackages;
  }


  public function setCompletedPackages( array $completedPackages ) {
    $this->completedPackages = $completedPackages;
  }


  public function removeCompletedPackages( array $packageTypes ) {
    foreach ( $packageTypes as $packageType ) {
      unset( $this->completedPackages[ $packageType ] );
      unset( $this->completedPackages[ ucfirst( $packageType ) ] );
    }
  }


  public function getCompletedPosts(): array {
    return $this->completedPosts;
  }


  public function setCompletedPosts( array $completedPosts ) {
    $this->completedPosts = $completedPosts;
  }


  public function removeCompletedPosts( array $postTypes ) {
    foreach ( $postTypes as $postType ) {
      unset( $this->completedPosts[ $postType ] );
    }
  }


  public function getCompletedStrings(): array {
    return $this->completedStrings;
  }


  public function setCompletedStrings( array $completedStrings ) {
    $this->completedStrings = $completedStrings;
  }


}
