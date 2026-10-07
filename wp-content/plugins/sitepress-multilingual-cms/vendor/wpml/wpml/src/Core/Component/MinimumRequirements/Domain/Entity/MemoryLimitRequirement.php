<?php

namespace WPML\Core\Component\MinimumRequirements\Domain\Entity;

use WPML\Core\Component\MinimumRequirements\Domain\Value\RequirementsConfig;
use WPML\Core\SharedKernel\Component\Server\Domain\ServerInfoInterface;
use WPML\Core\SharedKernel\Component\Server\Domain\Service\ByteSizeConverter;

class MemoryLimitRequirement extends RequirementBase {

  private $serverInfo;

  private $converter;


  public function __construct(
    ServerInfoInterface $server_info, ByteSizeConverter $byte_size_converter
  ) {
    $this->serverInfo = $server_info;
    $this->converter  = $byte_size_converter;
  }


  public function getId(): int {
    return 1;
  }


  public function getTitle(): string {
    return __( 'Memory limit', 'wpml' );
  }


  public function getMessages(): array {
    return [

      [
        'type'    => 'p',
        'message' => sprintf(
          __(
            'Your PHP memory limit is currently %s. '
            .
            ' WPML requires at least %s to function properly.',
            'wpml'
          ),
          '<strong>' .
          $this->converter->toBytes( $this->getOriginalPHPMemoryLimit() )
          / ( 1024 * 1024 ) . 'M</strong>',
          '<strong>' . RequirementsConfig::MINIMUM_MEMORY . '</strong>'
        )
      ],
      [
        'type'    => 'p',
        'message' => sprintf(
          __(
            'To increase the memory limit, add this to the top of your %swp-config.php%s file:',
            'wpml'
          ),
          '<strong>',
          '</strong>'
        )
      ],
      [
        'type'    => 'code',
        'message' => "/** Memory Limit */\ndefine( 'WP_MEMORY_LIMIT', '"
                     . RequirementsConfig::MINIMUM_MEMORY
                     . "' );\ndefine( 'WP_MAX_MEMORY_LIMIT', '"
                     . RequirementsConfig::WP_MAX_MEMORY_LIMIT . "' );",
      ]
    ];
  }


  protected function doIsValid(): bool {
    if ( $this->isMemoryLimitValid( $this->getOriginalPHPMemoryLimit() ) ) {
      return true;
    }

    return $this->isMemoryLimitValid( $this->getWPMaxMemoryLimit() )
           && $this->isMemoryLimitValid( $this->getWPMemoryLimit() );
  }


  protected function getRequirementType(): string {
    return 'MEMORY_LIMIT';
  }


  private function getWPMemoryLimit() {
    return $this->serverInfo->getConstant( 'WP_MEMORY_LIMIT', '40M' );
  }


  private function getOriginalPHPMemoryLimit(): string {
    return (string) $this->serverInfo->getOriginalIniGet( 'memory_limit' );
  }


  private function getWPMaxMemoryLimit() {
    return $this->serverInfo->getConstant(
      'WP_MAX_MEMORY_LIMIT',
      '256M'
    );
  }


  private function isMemoryLimitValid( $memoryLimit ): bool {
    if ( ! is_string( $memoryLimit ) && ! is_int( $memoryLimit ) ) {
      return false;
    }

    if ( (int) $memoryLimit === - 1 ) {
      return true;
    }

    return $this->converter->toBytes( $memoryLimit )
           >= $this->converter->toBytes( RequirementsConfig::MINIMUM_MEMORY );
  }


}
