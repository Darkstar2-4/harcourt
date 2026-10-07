<?php

namespace Smush\Core\Png2Jpg;

use Exception;
use Imagick;
use Smush\Core\File_System;
use Smush\Core\Server_Utils;
use Smush\Core\Helper;

class Png2Jpg_Helper {
	private static $safe_bytes_per_pixel = 9; // 8 bytes per pixel for RGBA at 16-bit quantum depth (Imagick), +1 buffer.
	private static $large_png_size = 3840;//4k.
	private $logger;
	/**
	 * @var File_System
	 */
	private $fs;

	/**
	 * @var Server_Utils
	 */
	private $server_utils;

	public function __construct() {
		$this->logger       = Helper::logger()->png2jpg();
		$this->fs           = new File_System();
		$this->server_utils = new Server_Utils();
	}

	/**
	 * @param $file_path string
	 * @param $width int
	 * @param $height int
	 *
	 * @return bool
	 */
	public function is_transparent( $file_path, $width, $height ) {
		$can_scan_pixels = $this->use_editor_for_transparency_check( $width, $height );

		if ( $can_scan_pixels && $this->supports_imagick() ) {
			try {
				return $this->has_transparent_pixel_imagick( $file_path );
			} catch ( Exception $exception ) {
				$this->logger->error( 'Imagick: Error in checking PNG transparency ' . $exception->getMessage() );
				// Fall through to GD or file-contents check.
			}
		}

		if ( $can_scan_pixels && $this->supports_gd() ) {
			try {
				return $this->has_transparent_pixel_gd( $file_path, $width, $height );
			} catch ( Exception $exception ) {
				$this->logger->error( 'GD: Error in checking PNG transparency ' . $exception->getMessage() );
				// Fall through to file-contents check.
			}
		}

		return $this->file_contents_have_transparency( $file_path );
	}

	private function has_transparent_pixel_imagick( $file_path ) {
		$imagick = new Imagick( $file_path );
		if ( ! $imagick->getImageAlphaChannel() ) {
			return false;
		}
		$alpha_range = $imagick->getImageChannelRange( Imagick::CHANNEL_ALPHA );
		$quantum     = $imagick->getQuantumRange()['quantumRangeLong'] ?? null;

		// minima < quantum means at least one pixel has less than full opacity.
		return null !== $quantum && $alpha_range['minima'] < $quantum;
	}

	private function has_transparent_pixel_gd( $file_path, $width, $height ) {
		$image = imagecreatefrompng( $file_path );
		if ( ! $image ) {
			return false;
		}

		// >= 0 means a transparent color index is set (palette/indexed-color PNG).
		if ( imagecolortransparent( $image ) >= 0 ) {
			$this->destroy_gd_image( $image );
			return true;
		}

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$color = imagecolorat( $image, $x, $y );
				$rgba  = imagecolorsforindex( $image, $color );
				// GD alpha is inverted: 0 = fully opaque, 127 = fully transparent.
				if ( isset( $rgba['alpha'] ) && $rgba['alpha'] > 0 ) {
					$this->destroy_gd_image( $image );
					return true;
				}
			}
		}

		$this->destroy_gd_image( $image );

		return false;
	}

	/**
	 * Free a GD image resource immediately on PHP < 8.5.
	 * On PHP 8.5+, GdImage is GC-managed and imagedestroy() is deprecated/no-op.
	 *
	 * @param \GdImage|resource $image
	 */
	private function destroy_gd_image( $image ) {
		if ( PHP_VERSION_ID < 80500 ) {
			imagedestroy( $image );
		}
	}

	private function use_editor_for_transparency_check( $width, $height ) {
		$required_memory = $width * $height * self::$safe_bytes_per_pixel;
		return $width <= self::$large_png_size
			&& $height <= self::$large_png_size
			&& $this->server_utils->has_memory_available( $required_memory );
	}

	private function file_contents_have_transparency( $file_path ) {
		// Simple check.
		// Src: http://camendesign.com/code/uth1_is-png-32bit.
		if ( ord( $this->fs->file_get_contents( $file_path, false, null, 25, 1 ) ) & 4 ) {
			$this->logger->info( sprintf( 'File [%s] is a PNG 32-bit.', $file_path ) );

			return true;
		}

		// Check for a transparent pixel line by line
		// Src: https://stackoverflow.com/a/43996262
		$handle = @fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return false;
		}

		$contents     = '';
		$contain_plte = false;
		$contain_trns = false;
		try {
			while ( ! feof( $handle ) ) {
				$new_line = fread( $handle, 8192 );
				// Added previous line to avoid split a string while chunking.
				$contents .= $new_line;

				$contain_plte = $contain_plte || stripos( $contents, 'PLTE' ) !== false;
				$contain_trns = $contain_trns || stripos( $contents, 'tRNS' ) !== false;

				if ( $contain_plte && $contain_trns ) {
					$this->logger->info( sprintf( 'File [%s] is an PNG 8-bit.', $file_path ) );

					return true;
				}

				// Reset the content to save memory.
				$contents = $new_line;
			}

			return false;
		} finally {
			@fclose( $handle );
		}
	}

	/**
	 * Check if Imagick is available or not
	 *
	 * @return bool True/False Whether Imagick is available or not
	 */
	public function supports_imagick() {
		if ( ! class_exists( '\Imagick' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if GD is loaded
	 *
	 * @return bool True/False Whether GD is available or not
	 */
	public function supports_gd() {
		if ( ! function_exists( 'gd_info' ) ) {
			return false;
		}

		return true;
	}
}