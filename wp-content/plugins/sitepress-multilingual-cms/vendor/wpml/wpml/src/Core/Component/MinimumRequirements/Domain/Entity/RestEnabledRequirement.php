<?php

namespace WPML\Core\Component\MinimumRequirements\Domain\Entity;

use WPML\Core\SharedKernel\Component\Server\Domain\CheckRestIsEnabledInterface;

class RestEnabledRequirement extends RequirementBase {

  private $restApiStatus;


  public function __construct( CheckRestIsEnabledInterface $restApiService ) {
    $this->restApiStatus = $restApiService;
  }


  public function getId(): int {
    return 4;
  }


  public function getTitle(): string {
    return __( 'WordPress REST API', 'wpml' );
  }


  public function getMessages(): array {
    $endpoint = $this->restApiStatus->getEndpoint();

    return [
      [
        'type'    => 'p',
        'message' => __(
          'The WordPress REST API must be enabled for WPML to work correctly.',
          'wpml'
        ),
      ],
      [
        'type'    => 'alert',
        'message' => sprintf(
          __(
            'The REST API test endpoint %s is not responding correctly.',
            'wpml'
          ),
          '<strong>' . $endpoint . '</strong>'
        ),
      ],
      [
        'type'    => 'p',
        'message' => __(
          '<strong>Common causes:</strong>',
          'wpml'
        ),
      ],
      [
        'type'    => 'p',
        'message' => __(
          '• A security or firewall plugin is blocking REST API requests<br>'
          .
          '• The REST API has been disabled via the <code>rest_enabled</code> filter<br>'
          .
          '• Server configuration (Apache/Nginx) is interfering with query parameters<br>'
          .
          '• Custom rewrite rules are conflicting with WordPress routing',
          'wpml'
        ),
      ],
      [
        'type'    => 'alert',
        'message' => sprintf(
          __(
            '<strong>Troubleshooting:</strong> Try accessing %s directly in your browser. '
            .
            'It should return a JSON response with <code>"status":"valid"</code>'.
            ' and <code>"get_parameters":"valid"</code>.',
            'wpml'
          ),
          '<a href="'.  $endpoint . '" target="_blank">'
          . __( 'the test endpoint', 'wpml' ) . '</a>'
        ),
      ]
    ];
  }


  protected function doIsValid(): bool {
    return $this->restApiStatus->isEnabled();
  }


  protected function getRequirementType(): string {
    return 'REST_API';
  }


}
