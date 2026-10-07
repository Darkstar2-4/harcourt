<?php

namespace WPML\TM\ATE\TranslateEverything\TranslatableData;

use \wpdb;


class DataPreSetup {
	const POSTS_CHUNK_SIZE = 100;
	const TERMS_CHUNK_SIZE = 10000;

	const KEY_POST_TYPES = 'post_types';
	const KEY_TAXONOMIES = 'taxonomies';

	private $db;

	public function listTranslatableData() {
		return [
			self::KEY_TAXONOMIES => [
				'category',
				'post_tag',
				'product_cat',
				'product_tag',
			],
			self::KEY_POST_TYPES => [
				'post',
				'page',
				'product',
			],
		];
	}

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	public function fetch( Stack $stack ) {
		switch ( $stack->type() ) {
			case self::KEY_POST_TYPES:
				return $this->posts( $stack );
			case self::KEY_TAXONOMIES:
				return $this->terms( $stack );
		}

		throw new \InvalidArgumentException(
			'The stack type "' . $stack->type() . '" is not supported.'
		);
	}

	private function posts( Stack $stack ) {
		$posts = $this->db->get_results(
			$this->db->prepare(
				"SELECT
					ID,
					post_content,
					post_title,
					post_excerpt
				FROM {$this->db->posts}
				WHERE `post_status` = 'publish'
				AND `post_type` = '%s'
				ORDER BY ID
				LIMIT %d, %d",
				$stack->name(),
				$stack->count(),
				self::POSTS_CHUNK_SIZE
			)
		);

		return $this->fillStack(
			$stack,
			$posts,
			function( $post ) {
				return $post->post_title .
					$post->post_content .
					$post->post_excerpt;
			},
			self::POSTS_CHUNK_SIZE
		);
	}


	private function terms( Stack $stack ) {
		$terms = $this->db->get_results(
			$this->db->prepare(
				"SELECT name
				FROM {$this->db->terms} as t
				LEFT JOIN {$this->db->term_taxonomy} as tt
				ON t.term_id = tt.term_id
					WHERE tt.taxonomy = '%s'
					AND tt.count > 0
				LIMIT %d, %d",
				$stack->name(),
				$stack->count(),
				self::TERMS_CHUNK_SIZE
			)
		);

		return $this->fillStack(
			$stack,
			$terms,
			function( $term ) {
				return $term->name;
			},
			self::TERMS_CHUNK_SIZE
		);
	}

	private function fillStack( Stack $stack, $dataSet, $dataExtract, $chunkSize ) {
		if ( ! is_array( $dataSet ) || count( $dataSet ) === 0 ) {
			$stack->completed();
			return $stack;
		}

		if ( count( $dataSet ) < $chunkSize ) {
			$stack->completed();
		}

		$stack->addCount( count( $dataSet ) );

		foreach ( $dataSet as $data ) {
			$stack->addWords( apply_filters( 'wpml_word_count_words', 0, $dataExtract( $data ) ) );
		}

		return $stack;
	}
}

