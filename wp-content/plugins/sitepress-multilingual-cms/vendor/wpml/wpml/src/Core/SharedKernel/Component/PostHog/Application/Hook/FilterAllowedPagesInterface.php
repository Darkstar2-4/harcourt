<?php

namespace WPML\Core\SharedKernel\Component\PostHog\Application\Hook;

interface FilterAllowedPagesInterface {


  public function filter( array $allowedPages ): array;


}
