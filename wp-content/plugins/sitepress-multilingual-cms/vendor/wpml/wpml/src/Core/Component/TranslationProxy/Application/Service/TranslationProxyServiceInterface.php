<?php

namespace WPML\Core\Component\TranslationProxy\Application\Service;

interface TranslationProxyServiceInterface {


  public function sendCommitRequest();


  public function getTPUrl(): string;


}
