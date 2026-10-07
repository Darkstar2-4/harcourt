<?php

namespace WPML\PB\FullSiteEditing;

use WPML\Element\API\PostTranslations;
use WPML\FP\Obj;
use WPML\PB\Gutenberg\Integration;

class TemplateTranslationHooks implements \IWPML_Backend_Action, \IWPML_Frontend_Action, \IWPML_REST_Action, Integration {


	public function add_hooks() {
		add_filter( 'wpml_tm_translation_job_data', [ $this, 'doNotTranslateTitle' ], 20, 2 );
		add_filter( 'wpml_pre_save_pro_translation', [ $this, 'copyOriginalTitleToTranslation' ], 20, 2 );
		add_action( 'rest_after_insert_wp_template', [ $this, 'syncPostName' ], 20, 1 );
		add_action( 'rest_after_insert_wp_template_part', [ $this, 'syncPostName' ], 20, 1 );
	}

	public function doNotTranslateTitle( array $package, $post ) {
		return TemplateLocalizer::isTemplate( Obj::prop( 'post_type', $post ) )
			? Obj::assocPath( [ 'contents', 'title', 'translate' ], 0, $package )
			: $package;
	}

	public function copyOriginalTitleToTranslation( $postData, $job ) {
		if ( ! TemplateLocalizer::isJobType( Obj::prop( 'original_post_type', $job ) ) ) {
			return $postData;
		}

		$post = \get_post( $job->original_doc_id );
		if ( ! $post ) {
			return $postData;
		}

		$postData['post_title'] = $post->post_title;
		$postData['post_name']  = $post->post_name;
		$targetLang             = property_exists( $job, 'language_code' ) ? $job->language_code : null;
		if ( $targetLang ) {
			$postData['post_name'] = TemplateLocalizer::getLocalizedTemplateSlug( $post->post_name, $targetLang );
		}

		return $postData;
	}

	public function syncPostName( $post ) {
		$translations = PostTranslations::get( $post->ID );
		global $wpdb;
		wpml_collect( $translations )
			->reject( Obj::prop( 'original' ) )
			->each(
				function ( $translation ) use (
					$post,
					$wpdb
				) {
					$expected = TemplateLocalizer::getLocalizedTemplateSlug( $post->post_name, $translation->language_code );
					$wpdb->update(
						$wpdb->prefix . 'posts',
						[ 'post_name' => $expected ],
						[ 'ID' => $translation->element_id ]
					);
				}
			);
	}
}
