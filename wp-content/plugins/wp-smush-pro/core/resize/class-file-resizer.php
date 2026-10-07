<?php

namespace Smush\Core\Resize;

use Smush\Core\File_System;
use Smush\Core\Helper;
use Smush\Core\Upload_Dir;
use WDEV_Logger;
use WP_Error;
use WP_Image_Editor;

class File_Resizer {
	/**
	 * @var WP_Image_Editor[]
	 */
	private $implementations;
	/**
	 * @var WDEV_Logger
	 */
	private $logger;
	/**
	 * @var WP_Error
	 */
	private $errors;
	/**
	 * @var File_System
	 */
	private $fs;
	/**
	 * @var Upload_Dir
	 */
	private $upload_dir;
	/**
	 * @var int
	 */
	private $new_filesize;
	/**
	 * @var int
	 */
	private $new_width;
	/**
	 * @var int
	 */
	private $new_height;
	/**
	 * @var string
	 */
	private $file_path;
	/**
	 * @var int
	 */
	private $original_filesize;
	private $target_width;
	private $target_height;
	/**
	 * @var array|false
	 */
	private $resized_data;

	public function __construct( $file_path, $original_filesize, $target_width, $target_height ) {
		$this->logger     = Helper::logger()->resize();
		$this->errors     = new WP_Error();
		$this->fs         = new File_System();
		$this->upload_dir = new Upload_Dir();

		$this->file_path         = $file_path;
		$this->original_filesize = $original_filesize;
		$this->target_width      = $target_width;
		$this->target_height     = $target_height;
	}

	/**
	 * @return bool
	 */
	public function resize() {
		$this->include_implementations();
		foreach ( $this->get_implementations() as $implementation ) {
			$data = $this->try_with_implementation( $implementation );
			if ( ! empty( $data['file'] ) ) {
				break;
			}
		}

		$original_path = $this->file_path;
		if ( empty( $data['file'] ) ) {
			/* translators: 1: Original path, 2: Image id. */
			$this->add_error( 'resize_failed', sprintf( __( 'Cannot resize image [%1$s].', 'wp-smushit' ), $this->upload_dir->get_human_readable_path( $original_path ) ) );

			return false;
		}

		$new_path = path_join( dirname( $original_path ), $data['file'] );
		if ( ! $this->fs->file_exists( $new_path ) ) {
			/* translators: %s: Resized path */
			$this->add_error( 'resized_image_not_found', sprintf( __( 'The resized image [%s] does not exist.', 'wp-smushit' ), $this->upload_dir->get_human_readable_path( $new_path ) ) );

			return false;
		}

		$new_filesize = ! empty( $data['filesize'] )
			? $data['filesize']
			: $this->fs->filesize( $new_path );
		if (
			$new_filesize > $this->original_filesize
			&& ! apply_filters( 'wp_smush_resize_allow_larger_resized_file', false )
		) {
			$this->delete_file( $new_path );

			$this->errors->add(
				'no_savings',
				__( 'Skipped: Smushed file is larger than the original file.', 'wp-smushit' )
			);

			$this->logger->error(
				sprintf(
				/* translators: 1: Resized path, 2: Resized file size, 3: Original path, 4: Original file size */
					__( 'The resized image [%1$s](%2$s) is larger than the original image [%3$s](%4$s).', 'wp-smushit' ),
					$this->upload_dir->get_human_readable_path( $new_path ),
					size_format( $new_filesize ),
					$this->upload_dir->get_human_readable_path( $original_path ),
					size_format( $this->original_filesize )
				)
			);

			return false;
		}

		$copied = $this->fs->copy( $new_path, $original_path );
		if ( ! $copied ) {
			$this->add_error(
				'copy_failed',
				sprintf(
				/* translators: 1: Resized path, 2: Original path. */
					__( 'Failed to copy from [%1$s] to [%2$s]', 'wp-smushit' ),
					$this->upload_dir->get_human_readable_path( $new_path ),
					$this->upload_dir->get_human_readable_path( $original_path )
				)
			);

			return false;
		}

		// Delete intermediate file.
		$this->delete_file( $new_path );

		$this->set_new_filesize( $new_filesize )
		     ->set_new_width( $data['width'] )
		     ->set_new_height( $data['height'] );

		return true;
	}

	private function delete_file( $file_path ) {
		if ( $this->fs->file_exists( $file_path ) ) {
			$this->fs->unlink( $file_path );
		}
	}

	public function get_errors() {
		return $this->errors;
	}

	private function add_error( $code, $message ) {
		// Log the error
		$this->logger->error( $message );
		// Add the error
		$this->errors->add( $code, $message );
	}

	private function get_implementations() {
		if ( is_null( $this->implementations ) ) {
			$this->implementations = $this->prepare_implementations();
		}

		return $this->implementations;
	}

	private function prepare_implementations() {
		$implementations = array(
			'WP_Image_Editor_Imagick',
			'WP_Image_Editor_GD',
		);
		$supported       = array();
		foreach ( $implementations as $implementation ) {
			if ( class_exists( $implementation ) && call_user_func( array( $implementation, 'test' ) ) ) {
				$supported[] = $implementation;
			}
		}

		return $supported;
	}

	private function try_with_implementation( $implementation ) {
		$editors_callback = function () use ( $implementation ) {
			return array( $implementation );
		};
		add_filter( 'wp_image_editors', $editors_callback );
		$return = $this->image_make_intermediate_size( $this->file_path, $this->target_width, $this->target_height );
		remove_filter( 'wp_image_editors', $editors_callback );

		return $return;
	}

	private function include_implementations() {
		// Calling this method includes the necessary files
		_wp_image_editor_choose();
	}

	/**
	 * @return int
	 */
	public function get_new_filesize() {
		return $this->new_filesize;
	}

	/**
	 * @param int $new_filesize
	 *
	 * @return File_Resizer
	 */
	private function set_new_filesize( $new_filesize ) {
		$this->new_filesize = (int) $new_filesize;

		return $this;
	}

	/**
	 * @return int
	 */
	public function get_new_width() {
		return $this->new_width;
	}

	/**
	 * @param int $new_width
	 *
	 * @return File_Resizer
	 */
	private function set_new_width( $new_width ) {
		$this->new_width = (int) $new_width;

		return $this;
	}

	/**
	 * @return int
	 */
	public function get_new_height() {
		return $this->new_height;
	}

	/**
	 * @param int $new_height
	 *
	 * @return File_Resizer
	 */
	private function set_new_height( $new_height ) {
		$this->new_height = (int) $new_height;

		return $this;
	}

	/**
	 * This is almost identical to the WP function of the same name. Just a few lines have been changed to pass a custom destination file name.
	 * A custom dest filename is needed to make sure that the resized file does not overwrite an existing thumbnail size.
	 * If an existing size gets overwritten then this could be a problem because we delete files during resizing. @see self::delete_file
	 */
	private function image_make_intermediate_size( $file, $width, $height, $crop = false ) {
		if ( $width || $height ) {
			$editor = wp_get_image_editor( $file );

			if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( $width, $height, $crop ) ) ) {
				return false;
			}

			$extension      = pathinfo( $file, PATHINFO_EXTENSION );
			$dest_file_name = $editor->generate_filename( "smush-resize-{$width}x$height", null, $extension );
			$resized_file   = $editor->save( $dest_file_name );

			if ( ! is_wp_error( $resized_file ) && $resized_file ) {
				unset( $resized_file['path'] );
				return $resized_file;
			}
		}
		return false;
	}
}