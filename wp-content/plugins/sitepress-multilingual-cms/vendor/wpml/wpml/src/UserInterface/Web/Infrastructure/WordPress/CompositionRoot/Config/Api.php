<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config;

use WPML\UserInterface\Web\Core\SharedKernel\Config\Endpoint\Endpoint;
use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\ApiInterface;

class Api implements ApiInterface {


  public function registerRoute(
    Endpoint $endpoint,
    $handle,
    $authorisation
  ) {

    if ( $endpoint->isAjax() ) {
      $this->registerAjaxEndpoint( $endpoint, $handle, $authorisation );
      return;
    }

    $this->registerRestEndpoint(
      $endpoint->namespaceWithVersion(),
      $endpoint->path(),
      $endpoint->method(),
      $handle,
      $authorisation
    );
  }


  private function registerAjaxEndpoint(
    Endpoint $endpoint,
    $handle,
    $authorisation
  ) {
    $routeWithoutSlashes = \str_replace( '/', '_', $endpoint->route() );

    add_action(
      'wp_ajax_wpml_api_' . $routeWithoutSlashes,
      function() use ( $handle, $authorisation ) {
        $authorisationResult = $authorisation();

        if ( $authorisationResult === false || $authorisationResult === null ) {
          $authorisationResult = new \WP_Error(
            'rest_forbidden',
            __( 'Sorry, you are not allowed to do that.' ),
            [ 'status' => rest_authorization_required_code() ]
          );
        }

        if ( is_wp_error( $authorisationResult ) ) {
          $errorResponse = rest_convert_error_to_response( $authorisationResult );
          return wp_send_json_error( $authorisationResult, $errorResponse->get_status() );
        }

        $json = file_get_contents( 'php://input' );

        $params = $json ? json_decode( $json, true ) : [];
        $params = is_array( $params ) ? $params : [];

        $params = array_merge( $params, $_GET );

        $jsonResponse = $handle( $params );

        http_response_code( $jsonResponse->status );
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        echo \json_encode( $jsonResponse->data );

        wp_die();
      }
    );
  }


  private function registerRestEndpoint(
    string $name,
    string $path,
    string $method,
    callable $handle,
    callable $authorisation
  ) {
    register_rest_route(
      $name,
      $path,
      [
        'methods' => $method,
        'callback' =>
        function( \WP_REST_Request $request ) use ( $handle ) {
          return $handle( $request->get_params() );
        },
        'permission_callback' => $authorisation,
      ]
    );
  }


  public function getFullUrl( Endpoint $endpoint ): string {
    $route = $endpoint->route();
    if ( $endpoint->isAjax() ) {
      $routeWithoutSlashes = \str_replace( '/', '_', $route );
      return admin_url( 'admin-ajax.php' ) . '?action=wpml_api_' . $routeWithoutSlashes;
    }
    $restUrl = get_rest_url( null, $route );
    if ( get_option( 'permalink_structure' ) === '' ) {
      $restUrl = add_query_arg( 'rest_route', '/' . ltrim( $route, '/' ), home_url( '/' ) );
    }
    return $restUrl;
  }


  public function nonce( $name = null ): string {
    $name = $name ?? 'wp_rest';

    return \wp_create_nonce( $name );
  }


  public function validateRequest( string $capability ): bool {
    if ( $capability === '__return_true' ) {
      return true;
    }

    return \current_user_can( $this->capabilityPlusAdmin( $capability ) );
  }


  public function capabilityPlusAdmin( string $capability ): string {
    if ( current_user_can( WPML_CAP_MANAGE_OPTIONS ) ) {
      return WPML_CAP_MANAGE_OPTIONS;
    }

    return $capability;
  }


  public function responseJsonSuccess( $data ) {
      return \rest_ensure_response( new \WP_REST_Response( $data, 200 ) );
  }


  public function responseJsonError( $data ) {
      return \rest_ensure_response( new \WP_REST_Response( $data, 500 ) );
  }


  public function responseJsonWithStatusCode( $data, $status_code ) {
      return \rest_ensure_response( new \WP_REST_Response( $data, $status_code ) );
  }


  public function isRestRequest(): bool {
    return defined( 'REST_REQUEST' );
  }


}
