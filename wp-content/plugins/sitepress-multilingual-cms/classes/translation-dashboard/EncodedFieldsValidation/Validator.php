<?php

namespace WPML\TM\TranslationDashboard\EncodedFieldsValidation;

use WPML\Core\Component\Base64Detection\Application\Service\Base64DetectionService;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\Infrastructure\Dic;
use WPML\LIB\WP\Post;
use WPML\TM\TranslationDashboard\SentContentMessages;
use function WPML\FP\spreadArgs;

class Validator {
	private $base64Detector;
	private $package_helper;
	private $sentContentMessages;
	private $fieldTitle;
	private $pbFactory;

	public function __construct(
		\WPML_Element_Translation_Package $package_helper,
		SentContentMessages $sentContentMessages,
		FieldTitle $fieldTitle,
		\WPML_PB_Factory $pbFactory
	) {
		$this->base64Detector      = $this->getBase64DetectionService();
		$this->package_helper      = $package_helper;
		$this->sentContentMessages = $sentContentMessages;
		$this->fieldTitle          = $fieldTitle;
		$this->pbFactory           = $pbFactory;
	}


	private function getBase64DetectionService() {
		global $wpml_dic;

		return $wpml_dic->make( Base64DetectionService::class );
	}

	public function validateTMDashboardInput( $data ) {
		$postInvalidElements = [];
		if ( isset( $data['post'] ) ) {
			$postInvalidElements = $this->findPostsWithEncodedFields( $this->getCheckedIds( 'post', $data ) );
			$data                = $this->excludeInvalidElements( 'post', $data, Lst::pluck( 'elementId', $postInvalidElements ) );
		}

		$packageInvalidElements = [];
		if ( isset( $data['package'] ) ) {
			$packageInvalidElements = $this->findPackagesWithEncodedFields( $this->getCheckedIds( 'package', $data ) );
			$data                   = $this->excludeInvalidElements( 'package', $data, Lst::pluck( 'elementId', $packageInvalidElements ) );
		}

		$invalidElements = array_merge( $postInvalidElements, $packageInvalidElements );
		if ( count( $invalidElements ) ) {
			$this->sentContentMessages->postsWithEncodedFieldsHasBeenSkipped( $invalidElements );
		}

		return $data;
	}

	public function getInvalidPostAndPackageIds( $postIds, $packageIds ) {
		$invalidPostIds    = [];
		$invalidPackageIds = [];
		$postIds           = array_unique( $postIds );
		$packageIds        = array_unique( $packageIds );

		if ( is_array( $postIds ) && ! empty( $postIds ) ) {
			$invalidPostIds = $this->findElementsIdsWithEncodedFields( 'post', $postIds );
		}

		if ( is_array( $packageIds ) && ! empty( $packageIds ) ) {
			$invalidPackageIds = $this->findElementsIdsWithEncodedFields( 'package', $packageIds );
		}

		return [ $invalidPostIds, $invalidPackageIds ];
	}

	private function findElementsIdsWithEncodedFields( $type, $elementsIds ) {
		$elementsIdsWithEncodedFields = [];

		$extractElementId = function ( ErrorEntry $errorEntry ) {
			return $errorEntry->elementId;
		};

		if ( 'post' === $type ) {
			$elementsIdsWithEncodedFields = array_map(
				$extractElementId,
				$this->findPostsWithEncodedFields( $elementsIds )
			);
		} elseif ( 'package' === $type ) {
			$elementsIdsWithEncodedFields = array_map(
				$extractElementId,
				$this->findPackagesWithEncodedFields( $elementsIds )
			);
		}

		return $elementsIdsWithEncodedFields;
	}

	private function getCheckedIds( $type, $data ) {
		return \wpml_collect( Obj::propOr( [], $type, $data ) )
			->filter( Obj::prop( 'checked' ) )
			->keys()
			->toArray();
	}

	private function excludeInvalidElements( $type, $data, $invalidElementIds ) {
		return (array) Obj::over( Obj::lensProp( $type ), function ( $elements ) use ( $invalidElementIds ) {
			return \wpml_collect( $elements )
				->map( function ( $element, $elementId ) use ( $invalidElementIds ) {
					if ( Lst::includes( $elementId, $invalidElementIds ) ) {
						return Obj::removeProp( 'checked', $element );
					}

					return $element;
				} )
				->toArray();
		}, $data );
	}

	private function findPostsWithEncodedFields( $postIds ) {
		$appendPackage = function ( \WP_Post $post ) {
			$package = $this->package_helper->create_translation_package( $post->ID, true );

			return [ $post, $package ];
		};

		$isFieldEncoded = function ( $field, $slug ) {
			$decodedFieldData = base64_decode( $field['data'] );

			return array_key_exists( 'format', $field )
					&& ! in_array( $slug, [ 'title', 'body' ], true )
					&& 'base64' === $field['format']
					&& (
						$this->base64Detector->isBase64EncodedText( $decodedFieldData ) ||
						$this->base64Detector->containsBase64EncodedText( $decodedFieldData )
					);
		};

		$getInvalidFieldData = function ( $field, $slug ) {
			return [
				'title'   => $this->fieldTitle->get( $slug ),
				'content' => base64_decode( $field['data'] ),
			];
		};

		$tryToGetError = function ( \WP_Post $post, $package ) use ( $isFieldEncoded, $getInvalidFieldData ) {
			$invalidFields = \wpml_collect( $package['contents'] )
				->filter( $isFieldEncoded )
				->map( $getInvalidFieldData )
				->values()
				->toArray();

			if ( $invalidFields ) {
				return new ErrorEntry( $post->ID, $package['title'], $invalidFields );
			}

			return null;
		};

		return \wpml_collect( $postIds )
			->map( Post::get() )
			->filter()
			->map( $appendPackage )
			->map( spreadArgs( $tryToGetError ) )
			->filter()
			->toArray();

	}

	private function findPackagesWithEncodedFields( $packageIds ) {
		$getInvalidFieldData = function ( $field, $slug ) {
			return [
				'title'   => $this->fieldTitle->get( $slug ),
				'content' => $field,
			];
		};

		$isEncodedContent = function ( $content ) {
			return $this->base64Detector->isBase64EncodedText( $content ) ||
					$this->base64Detector->containsBase64EncodedText( $content );
		};

		$tryToGetError = function ( $package ) use ( $getInvalidFieldData, $isEncodedContent ) {
			$invalidFields = \wpml_collect( Obj::propOr( [], 'string_data', $package ) )
				->filter( $isEncodedContent )
				->map( $getInvalidFieldData )
				->values()
				->toArray();

			if ( $invalidFields ) {
				return new ErrorEntry( $package->ID, $package->title, $invalidFields );
			}

			return null;
		};

		return \wpml_collect( $packageIds )
			->map( function ( $packageId ) {
				return $this->pbFactory->get_wpml_package( $packageId );
			} )
			->filter( Obj::prop( 'ID' ) )
			->map( $tryToGetError )
			->filter()
			->toArray();

	}
}
