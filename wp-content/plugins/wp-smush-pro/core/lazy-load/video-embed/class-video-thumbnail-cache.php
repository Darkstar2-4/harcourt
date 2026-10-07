<?php

namespace Smush\Core\Lazy_Load\Video_Embed;

use Smush\Core\Background\Mutex;
use Smush\Core\Threads\JSON_Record;

class Video_Thumbnail_Cache {
	private const MAX_ITEMS    = 1000;// ~ 0.2 MB of transient data.
	private const INDEX_OPTION = 'wp-smush-video-thumbnail-cache-index';
	private const MUTEX_KEY    = 'wp-smush-video-thumbnail-cache';

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @var JSON_Record
	 */
	private $index;

	/**
	 * @var Mutex
	 */
	private $mutex;

	/**
	 * @var int
	 */
	private $max_items;

	public static function get_instance() {
		if ( ! self::$instance ) {
			$max_items = defined( 'WP_SMUSH_VIDEO_THUMBNAIL_CACHE_MAX_ITEMS' )
				? WP_SMUSH_VIDEO_THUMBNAIL_CACHE_MAX_ITEMS
				: self::MAX_ITEMS;

			self::$instance = new self( $max_items );
		}

		return self::$instance;
	}

	public function __construct( $max_items = self::MAX_ITEMS ) {
		$this->index     = new JSON_Record( self::INDEX_OPTION );
		$this->mutex     = new Mutex( self::MUTEX_KEY );
		$this->max_items = min(
			self::MAX_ITEMS,
			max( 1, (int) $max_items )
		);

		$this->mutex
			->set_timeout( 1 )
			->set_break_on_timeout( true );
	}

	public function add( $video_id, $provider, $thumb_width, $thumb_height, $video_thumbnail ) {
		$transient_key = $this->get_transient_key(
			$video_id,
			$provider,
			$thumb_width,
			$thumb_height
		);

		$this->mutex->execute(
			function () use ( $transient_key, $video_thumbnail ) {
				$this->add_with_lock( $transient_key, $video_thumbnail );
			}
		);
	}

	public function get( $video_id, $provider, $thumb_width, $thumb_height ) {
		$transient_key        = $this->get_transient_key( $video_id, $provider, $thumb_width, $thumb_height );
		$video_thumbnail_data = get_transient( $transient_key );
		if ( ! $video_thumbnail_data ) {
			return null;
		}

		$video_thumbnail = new Video_Thumbnail();
		$video_thumbnail->from_array( $video_thumbnail_data );

		return $video_thumbnail;
	}

	private function add_with_lock( $transient_key, $video_thumbnail ) {
		$video_thumbnail_data = $video_thumbnail->to_array();

		/*
		 * Another request may have populated this transient while the current
		 * request was fetching the thumbnail. Refresh it without consuming a
		 * second cache slot.
		 */
		if ( false !== get_transient( $transient_key ) ) {
			set_transient( $transient_key, $video_thumbnail_data );
			return;
		}

		$index   = $this->get_index();
		$slot    = $index['next_slot'];
		$old_key = $index['slots'][ $slot ] ?? null;

		if ( ! set_transient( $transient_key, $video_thumbnail_data ) ) {
			return;
		}

		$index['slots'][ $slot ] = $transient_key;
		$index['next_slot']      = $slot >= $this->max_items ? 1 : $slot + 1;

		if ( false === $this->save_index( $index ) ) {
			delete_transient( $transient_key );
			return;
		}

		if ( $old_key && $old_key !== $transient_key ) {
			delete_transient( $old_key );
		}
	}

	private function get_index() {
		$default_index = array(
			'next_slot' => 1,
			'slots'     => array(),
		);

		/*
		 * Keep the index out of WordPress's autoloaded options. JSON_Record
		 * updates the existing row and therefore preserves this setting.
		 */
		add_option(
			self::INDEX_OPTION,
			wp_json_encode( $default_index ),
			'',
			false
		);

		$index = $this->index->get();
		if ( ! is_array( $index ) ) {
			return $default_index;
		}

		$index['next_slot'] = (int) ( $index['next_slot'] ?? 1 );
		$index['slots']     = isset( $index['slots'] ) && is_array( $index['slots'] )
			? $index['slots']
			: array();

		foreach ( $index['slots'] as $stored_slot => $transient_key ) {
			$normalized_slot = (int) $stored_slot;

			if (
				$normalized_slot < 1
				|| $normalized_slot > $this->max_items
			) {
				if ( is_string( $transient_key ) && $transient_key ) {
					delete_transient( $transient_key );
				}

				unset( $index['slots'][ $stored_slot ] );
			}
		}

		if ( $index['next_slot'] < 1 || $index['next_slot'] > $this->max_items ) {
			$index['next_slot'] = 1;
		}

		return $index;
	}

	private function save_index( $index ) {
		return $this->index->unsafe_set( $index );
	}

	private function get_transient_key( $video_id, $provider, $thumb_width, $thumb_height ) {
		return sprintf(
			'wp-smush-video-thumbnail-%s-%s-%d-%d',
			$provider,
			$video_id,
			(int) $thumb_width,
			(int) $thumb_height
		);
	}
}