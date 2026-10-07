<?php

class WPML_Page_Builders_Media_Find_Usage_Noop implements IWPML_PB_Media_Find_And_Translate {
	public function get_used_media_in_post() {
		return [];
	}

	public function translate_image_url( $url, $lang, $source_lang, $tag_name = '' ) {
		return $url;
	}
	public function translate_id( $id, $lang ) {
		return $id;
	}

	public function prefetch_media_urls( array $urls, $source_lang ) {
	}

	public function reset_translated_ids() {
	}

	public function get_translated_ids() {
		return [];
	}
}
