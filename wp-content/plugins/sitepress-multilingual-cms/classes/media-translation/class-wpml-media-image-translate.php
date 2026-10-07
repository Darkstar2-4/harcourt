<?php

use WPML\LIB\WP\Cache;
use WPML\Media\Classes\WPML_Media_Element_Translation_Factory;

class WPML_Media_Image_Translate {

	const URLS_TO_IDS_CACHE_KEY = 'urls-to-ids-cache-key';

	private $sitepress;

	private $attachment_by_url_factory;

	private $media_attachment_by_url_query;

	public function __construct(
		SitePress $sitepress,
		WPML_Media_Attachment_By_URL_Factory $attachment_by_url_factory,
		\WPML\Media\Factories\WPML_Media_Attachment_By_URL_Query_Factory $media_attachment_by_url_query_factory
	) {
		$this->sitepress                     = $sitepress;
		$this->attachment_by_url_factory     = $attachment_by_url_factory;
		$this->media_attachment_by_url_query = $media_attachment_by_url_query_factory->create();
		wp_cache_add_non_persistent_groups( self::URLS_TO_IDS_CACHE_KEY );
	}

	public function prefetchDataForFutureGetTranslatedImageCalls( $source_language, $items_to_translate ) {
		$this->media_attachment_by_url_query->prefetchAllIdsFromGuids(
			[ $source_language ],
			array_merge(
				array_map(
					function( $item ) {
						return WPML_Media_Attachment_By_URL::getUrl( $item['url'] );
					},
					$items_to_translate
				),
				array_map(
					function( $item ) {
						return WPML_Media_Attachment_By_URL::getUrlNotScaled( $item['url'] );
					},
					$items_to_translate
				)
			)
		);
		$this->media_attachment_by_url_query->prefetchAllIdsFromMetas(
			[ $source_language ],
			array_merge(
				array_map(
					function( $item ) {
						return WPML_Media_Attachment_By_URL::getUrlRelativePath( $item['url'] );
					},
					$items_to_translate
				),
				array_map(
					function( $item ) {
						return WPML_Media_Attachment_By_URL::getUrlRelativePathOriginal(
							WPML_Media_Attachment_By_URL::getUrlRelativePath( $item['url'] )
						);
					},
					$items_to_translate
				),
				array_map(
					function( $item ) {
						return WPML_Media_Attachment_By_URL::getUrlRelativePathScaled( $item['url'] );
					},
					$items_to_translate
				)
			)
		);
	}

	public function get_translated_image( $attachment_id, $language = null, $size = null ) {
		if ( ! $language ) {
			$language = $this->sitepress->get_current_language();
		}

		$image_url              = '';
		$attachment             = WPML_Media_Element_Translation_Factory::create( $attachment_id );
		$attachment_translation = $attachment->get_translation( $language );

		if ( $attachment_translation ) {
			$uploads_dir   = wp_get_upload_dir();
			$attachment_id = $attachment_translation->get_id();
			if ( null === $size ) {
				$image_url = $uploads_dir['baseurl'] . '/' . get_post_meta( $attachment_id, '_wp_attached_file', true );
			} else {
				$image_url = $this->get_sized_image_url( $attachment_id, $size, $uploads_dir );
			}
		}

		return $image_url;
	}

	public function get_translated_image_by_url( $img_src, $source_language, $target_language ) {

		$attachment_id = $this->get_attachment_id_by_url( $img_src, $source_language );

		if ( $attachment_id ) {
			$size = $this->get_image_size_from_url( $img_src, $attachment_id );
			try {
				$img_src = $this->get_translated_image( $attachment_id, $target_language, $size );
			} catch ( Exception $e ) {
				$img_src = false;
			}
		} else {
			$img_src = false;
		}

		return $img_src;
	}

	public function get_attachment_id_by_url( $img_src, $source_language = null ) {
		if ( ! $source_language ) {
			$source_language = $this->getLanguageByUrl( $img_src ) ?: $this->sitepress->get_current_language();
		}

		$attachment_by_url = $this->attachment_by_url_factory->create( $img_src, $source_language, $this->media_attachment_by_url_query );

		return (int) $attachment_by_url->get_id();
	}

	private function getLanguageByUrl( $url ) {
		$image_url = WPML_Media_Attachment_By_URL::getUrl( $url );

		$image_id = Cache::get( self::URLS_TO_IDS_CACHE_KEY, $image_url )->getOrElse( null );
		if ( ! $image_id ) {
			$image_id = attachment_url_to_postid( $image_url );
			Cache::set( self::URLS_TO_IDS_CACHE_KEY, $image_url, HOUR_IN_SECONDS, $image_id );
		}

		return $this->sitepress->get_language_for_element( $image_id, 'post_attachment' );
	}

	private function get_image_size_from_url( $url, $attachment_id ) {
		$media_sizes = new WPML_Media_Sizes();

		return $media_sizes->get_image_size_from_url( $url, $attachment_id );
	}

	private function get_sized_image_url( $attachment_id, $size, $uploads_dir ) {
		$image_url       = '';
		$meta_data       = wp_get_attachment_metadata( $attachment_id );
		$image_url_parts = array( $uploads_dir['baseurl'] );

		if ( is_array( $meta_data ) && array_key_exists( 'file', $meta_data ) ) {
			$file_subdirectory       = $meta_data['file'];
			$file_subdirectory_parts = explode( '/', $file_subdirectory );

			$filename          = array_pop( $file_subdirectory_parts );
			$image_url_parts[] = implode( '/', $file_subdirectory_parts );

			if ( array_key_exists( $size, $meta_data['sizes'] ) ) {
				$image_url_parts[] = $meta_data['sizes'][ $size ]['file'];
			} else {
				$image_url_parts[] = $filename;
			}

			$image_url = implode( '/', $image_url_parts );
		}

		return $image_url;
	}
}
