<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\PostHog;

use WPML\UserInterface\Web\Core\Component\PostHog\Application\PostHogShouldRecordController;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\MethodType;

class EndpointDataProvider {

  const ID = 'wpmlPostHogShouldMakeExternalRequest';

  const PATH = '/wpml-ph-make-external-request';

  const HANDLER = PostHogShouldRecordController::class;

  const METHOD = MethodType::POST;
}
