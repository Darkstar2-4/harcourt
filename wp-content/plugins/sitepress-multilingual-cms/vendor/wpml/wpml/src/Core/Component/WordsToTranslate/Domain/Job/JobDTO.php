<?php

namespace WPML\Core\Component\WordsToTranslate\Domain\Job;

class JobDTO {

  private $id;

  private $wordsToTransalte;

  private $automaticTranslationCosts;

  private $previousAteJobIds;


  public function __construct(
    $id,
    $wordsToTranslate,
    $automaticTranslationCosts,
    $previousAteJobId = []
  ) {
    $this->id = $id;
    $this->wordsToTransalte = $wordsToTranslate;
    $this->automaticTranslationCosts = $automaticTranslationCosts;
    $this->previousAteJobIds = $previousAteJobId;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getWordsToTranslate(): int {
    return $this->wordsToTransalte;
  }


  public function getAutomaticTranslationCosts() {
    return $this->automaticTranslationCosts;
  }


  public function getPreviousAteJobIds() {
    return $this->previousAteJobIds;
  }


}
