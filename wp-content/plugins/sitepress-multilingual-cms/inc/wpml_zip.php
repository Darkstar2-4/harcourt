<?php
/**
 * Class to create and manage a Zip file.
 *
 * Initially inspired by CreateZipFile by Rochak Chauhan  www.rochakchauhan.com (http://www.phpclasses.org/browse/package/2322.html)
 * and
 * http://www.pkware.com/documents/casestudies/APPNOTE.TXT Zip file specification.
 *
 * License: GNU LGPL 2.1.
 *
 * @author A. Grandt <php@grandt.com>
 * @copyright 2009-2014 A. Grandt
 * @license GNU LGPL 2.1
 * @link http://www.phpclasses.org/package/6110
 * @link https://github.com/Grandt/PHPZip
 * @version 1.62
 */
class wpml_zip {
	const VERSION = 1.62;

	const ZIP_LOCAL_FILE_HEADER        = "\x50\x4b\x03\x04";
	const ZIP_CENTRAL_FILE_HEADER      = "\x50\x4b\x01\x02";
	const ZIP_END_OF_CENTRAL_DIRECTORY = "\x50\x4b\x05\x06\x00\x00\x00\x00";

	const EXT_FILE_ATTR_DIR  = 010173200020;
	const EXT_FILE_ATTR_FILE = 020151000040;

	const ATTR_VERSION_TO_EXTRACT = "\x14\x00";
	const ATTR_MADE_BY_VERSION    = "\x1E\x03";

	const EXTRA_FIELD_NEW_UNIX_GUID = "\x75\x78\x0B\x00\x01\x04\xE8\x03\x00\x00\x04\x00\x00\x00\x00";

	const S_IFIFO  = 0010000;
	const S_IFCHR  = 0020000;
	const S_IFDIR  = 0040000;
	const S_IFBLK  = 0060000;
	const S_IFREG  = 0100000;
	const S_IFLNK  = 0120000;
	const S_IFSOCK = 0140000;


	const S_ISUID = 0004000;
	const S_ISGID = 0002000;
	const S_ISTXT = 0001000;

	const S_IRWXU = 0000700;
	const S_IRUSR = 0000400;
	const S_IWUSR = 0000200;
	const S_IXUSR = 0000100;
	const S_IRWXG = 0000070;
	const S_IRGRP = 0000040;
	const S_IWGRP = 0000020;
	const S_IXGRP = 0000010;
	const S_IRWXO = 0000007;
	const S_IROTH = 0000004;
	const S_IWOTH = 0000002;
	const S_IXOTH = 0000001;
	const S_ISVTX = 0001000;



	const S_DOS_A = 0000040;
	const S_DOS_D = 0000020;
	const S_DOS_V = 0000010;
	const S_DOS_S = 0000004;
	const S_DOS_H = 0000002;
	const S_DOS_R = 0000001;

	private $zipMemoryThreshold = 1048576;

	private $zipData       = null;
	private $zipFile       = null;
	private $zipComment    = null;
	private $cdRec         = array();
	private $offset        = 0;
	private $isFinalized   = false;
	private $addExtraField = true;

	private $streamChunkSize   = 65536;
	private $streamFilePath    = null;
	private $streamTimestamp   = null;
	private $streamFileComment = null;
	private $streamFile        = null;
	private $streamData        = null;
	private $streamFileLength  = 0;
	private $streamExtFileAttr = null;
	public static $temp = null;

	function __construct( $useZipFile = false ) {
		if ( $useZipFile ) {
			$this->zipFile = tmpfile();
		} else {
			$this->zipData = '';
		}
	}

	function __destruct() {
		if ( is_resource( $this->zipFile ) ) {
			fclose( $this->zipFile );
		}
		$this->zipData = null;
	}

	public function setComment( $newComment = null ) {
		if ( $this->isFinalized ) {
			return false;
		}
		$this->zipComment = $newComment;

		return true;
	}

	public function setZipFile( $fileName ) {
		if ( is_file( $fileName ) ) {
			unlink( $fileName );
		}
		$fd = fopen( $fileName, 'x+b' );

		if ( ! $fd ) {
			return false;
		}

		if ( is_resource( $this->zipFile ) ) {
			rewind( $this->zipFile );
			while ( ! feof( $this->zipFile ) ) {
				fwrite( $fd, (string) fread( $this->zipFile, $this->streamChunkSize ) );
			}

			fclose( $this->zipFile );
		} else {
			fwrite( $fd, $this->zipData );
			$this->zipData = null;
		}
		$this->zipFile = $fd;

		return true;
	}

	public function addDirectory( $directoryPath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_DIR ) {
		if ( $this->isFinalized ) {
			return false;
		}
		$directoryPath = str_replace( '\\', '/', $directoryPath );
		$directoryPath = rtrim( $directoryPath, '/' );

		if ( strlen( $directoryPath ) > 0 ) {
			$this->buildZipEntry( $directoryPath . '/', $fileComment, "\x00\x00", "\x00\x00", $timestamp, "\x00\x00\x00\x00", 0, 0, $extFileAttr );
			return true;
		}
		return false;
	}

	public function addFile( $data, $filePath, $timestamp = 0, $fileComment = null, $compress = true, $extFileAttr = self::EXT_FILE_ATTR_FILE ) {
		if ( $this->isFinalized ) {
			return false;
		}

		if ( is_resource( $data ) && get_resource_type( $data ) == 'stream' ) {
			$this->addLargeFile( $data, $filePath, $timestamp, $fileComment, $extFileAttr );
			return false;
		}

		$gzData     = '';
		$gzType     = "\x08\x00";
		$gpFlags    = "\x00\x00";
		$dataLength = strlen( $data );
		$fileCRC32  = pack( 'V', crc32( $data ) );

		if ( $compress ) {
			$gzTmp  = gzcompress( $data );
			if ( ! $gzTmp ) {
				return false;
			}
			$gzTmp = substr( $gzTmp, 0, strlen( $gzTmp ) - 4 );
			if ( ! $gzTmp ) {
				return false;
			}
			$gzData = substr( $gzTmp, 2 );
			$gzLength = strlen( $gzData );
		} else {
			$gzLength = $dataLength;
		}

		if ( $gzLength >= $dataLength ) {
			$gzLength = $dataLength;
			$gzData   = $data;
			$gzType   = "\x00\x00";
			$gpFlags  = "\x00\x00";
		}

		if ( ! is_resource( $this->zipFile ) && ( $this->offset + $gzLength ) > $this->zipMemoryThreshold ) {
			$this->zipflush();
		}

		$this->buildZipEntry( $filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr );

		$this->zipwrite( $gzData );

		return true;
	}

	public function addLargeFile( $dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE ) {
		if ( $this->isFinalized ) {
			return false;
		}

		if ( is_string( $dataFile ) && is_file( $dataFile ) ) {
			$this->processFile( $dataFile, $filePath, $timestamp, $fileComment, $extFileAttr );
		} elseif ( is_resource( $dataFile ) && get_resource_type( $dataFile ) == 'stream' ) {
			$fh = $dataFile;
			$this->openStream( $filePath, $timestamp, $fileComment, $extFileAttr );

			while ( ! feof( $fh ) ) {
				$data = fread( $fh, $this->streamChunkSize );
				if ( $data ) {
					$this->addStreamData( $data );
				}
			}
			$this->closeStream();
		}
		return true;
	}

	public function openStream( $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE ) {
		if ( ! function_exists( 'sys_get_temp_dir' ) ) {
			throw new Exception( 'Zip ' . self::VERSION . ' requires PHP version 5.2.1 or above if large files are used.' );
		}

		if ( $this->isFinalized ) {
			return false;
		}

		$this->zipflush();

		if ( strlen( $this->streamFilePath ) > 0 ) {
			$this->closeStream();
		}

		$this->streamFile        = self::getTemporaryFile();
		$this->streamData        = fopen( $this->streamFile, 'wb' );
		$this->streamFilePath    = $filePath;
		$this->streamTimestamp   = $timestamp;
		$this->streamFileComment = $fileComment;
		$this->streamFileLength  = 0;
		$this->streamExtFileAttr = $extFileAttr;

		return true;
	}

	public function addStreamData( $data ) {
		if ( $this->isFinalized || strlen( $this->streamFilePath ) == 0 ) {
			return false;
		}

		$length = fwrite( $this->streamData, $data, strlen( $data ) );
		if ( $length != strlen( $data ) ) {
			throw new Exception( 'File IO: Error writing; Length mismatch: Expected ' . strlen( $data ) . ' bytes, wrote ' . ( $length === false ? 'NONE!' : $length ) );
		}
		$this->streamFileLength += $length;

		return $length;
	}

	public function closeStream() {
		if ( $this->isFinalized || strlen( $this->streamFilePath ) == 0 ) {
			return false;
		}

		fflush( $this->streamData );
		fclose( $this->streamData );

		$this->processFile( $this->streamFile, $this->streamFilePath, $this->streamTimestamp, $this->streamFileComment, $this->streamExtFileAttr );

		$this->streamData        = null;
		$this->streamFilePath    = null;
		$this->streamTimestamp   = null;
		$this->streamFileComment = null;
		$this->streamFileLength  = 0;
		$this->streamExtFileAttr = null;

		unlink( $this->streamFile );

		$this->streamFile = null;

		return true;
	}

	private function processFile( $dataFile, $filePath, $timestamp = 0, $fileComment = null, $extFileAttr = self::EXT_FILE_ATTR_FILE ) {
		if ( $this->isFinalized ) {
			return false;
		}

		$tempzip = self::getTemporaryFile();

		$zip = new ZipArchive();
		if ( $zip->open( $tempzip ) === true ) {
			$zip->addFile( $dataFile, 'file' );
			$zip->close();
		}

		$file_handle = fopen( $tempzip, 'rb' );
		if ( ! $file_handle ) {
			return false;
		}

		$stats       = fstat( $file_handle );
		$eof         = $stats['size'] - 72;

		fseek( $file_handle, 6 );

		$gpFlags = fread( $file_handle, 2 );
		$gzType  = fread( $file_handle, 2 );
		fread( $file_handle, 4 );
		$fileCRC32  = fread( $file_handle, 4 );
		$v          = unpack( 'Vval', (string) fread( $file_handle, 4 ) );
		$gzLength   = $v['val'];
		$v          = unpack( 'Vval', (string) fread( $file_handle, 4 ) );
		$dataLength = $v['val'];

		$this->buildZipEntry( $filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr );

		fseek( $file_handle, 34 );
		$pos = 34;

		while ( ! feof( $file_handle ) && $pos < $eof ) {
			$datalen = $this->streamChunkSize;
			if ( $pos + $this->streamChunkSize > $eof ) {
				$datalen = $eof - $pos;
			}
			$data = fread( $file_handle, $datalen );
			$pos += $datalen;

			$this->zipwrite( $data );
		}

		fclose( $file_handle );

		unlink( $tempzip );
	}

	public function finalize() {
		if ( ! $this->isFinalized ) {
			if ( isset( $this->streamFilePath ) && strlen( $this->streamFilePath ) > 0 ) {
				$this->closeStream();
			}
			$cd = implode( '', $this->cdRec );

			$cdRecSize = pack( 'v', sizeof( $this->cdRec ) );
			$cdRec     = $cd . self::ZIP_END_OF_CENTRAL_DIRECTORY
			. $cdRecSize . $cdRecSize
			. pack( 'VV', strlen( $cd ), $this->offset );
			if ( ! empty( $this->zipComment ) ) {
				$cdRec .= pack( 'v', strlen( $this->zipComment ) ) . $this->zipComment;
			} else {
				$cdRec .= "\x00\x00";
			}

			$this->zipwrite( $cdRec );

			$this->isFinalized = true;
			$this->cdRec       = null;

			return true;
		}
		return false;
	}

	public function getZipData() {
		if ( ! $this->isFinalized ) {
			$this->finalize();
		}
		if ( ! is_resource( $this->zipFile ) ) {
			return $this->zipData;
		} else {
			rewind( $this->zipFile );
			$filestat = fstat( $this->zipFile );
			return fread( $this->zipFile, $filestat['size'] > 0 ? $filestat['size'] : 0 );
		}
	}

	function sendZip( $fileName = null, $contentType = 'application/zip', $utf8FileName = null, $inline = false ) {
		if ( ! $this->isFinalized ) {
			$this->finalize();
		}
		$headerFile = null;
		$headerLine = null;
		if ( headers_sent( $headerFile, $headerLine ) ) {
			throw new Exception( "Unable to send file '$fileName'. Headers have already been sent from '$headerFile' in line $headerLine" );
		}
		if ( ob_get_contents() !== false && strlen( ob_get_contents() ) ) {
			throw new Exception( "Unable to send file '$fileName'. Output buffer contains the following text (typically warnings or errors):\n" . ob_get_contents() );
		}
		if ( @ini_get( 'zlib.output_compression' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' );
		}
		header( 'Pragma: public' );
		header( 'Last-Modified: ' . @gmdate( 'D, d M Y H:i:s T' ) );
		header( 'Expires: 0' );
		header( 'Accept-Ranges: bytes' );
		header( 'Connection: close' );
		header( 'Content-Type: ' . $contentType );
		$cd = 'Content-Disposition: ';
		if ( $inline ) {
			$cd .= 'inline';
		} else {
			$cd .= 'attached';
		}
		if ( $fileName ) {
			$cd .= '; filename="' . $fileName . '"';
		}
		if ( $utf8FileName ) {
			$cd .= "; filename*=UTF-8''" . rawurlencode( $utf8FileName );
		}
		header( $cd );
		header( 'Content-Length: ' . $this->getArchiveSize() );
		if ( ! is_resource( $this->zipFile ) ) {
			echo $this->zipData;
		} else {
			rewind( $this->zipFile );
			while ( ! feof( $this->zipFile ) ) {
				echo fread( $this->zipFile, $this->streamChunkSize );
			}
		}
		return true;
	}

	public function getArchiveSize() {
		if ( ! is_resource( $this->zipFile ) ) {
			return strlen( $this->zipData );
		}
		$filestat = fstat( $this->zipFile );

		return $filestat['size'];
	}

	private function getDosTime( $timestamp = 0 ) {
		$timestamp = (int) $timestamp;
		$oldTZ     = @date_default_timezone_get();
		date_default_timezone_set( 'UTC' );
		$date = ( $timestamp == 0 ? getdate() : getdate( $timestamp ) );
		date_default_timezone_set( $oldTZ );
		if ( $date['year'] >= 1980 ) {
			return pack(
				'V',
				( ( $date['mday'] + ( $date['mon'] << 5 ) + ( ( $date['year'] - 1980 ) << 9 ) ) << 16 ) |
					( ( $date['seconds'] >> 1 ) + ( $date['minutes'] << 5 ) + ( $date['hours'] << 11 ) )
			);
		}
		return "\x00\x00\x00\x00";
	}

	private function buildZipEntry( $filePath, $fileComment, $gpFlags, $gzType, $timestamp, $fileCRC32, $gzLength, $dataLength, $extFileAttr ) {
		$filePath          = str_replace( '\\', '/', $filePath );
		$fileCommentLength = ( empty( $fileComment ) ? 0 : strlen( $fileComment ) );
		$timestamp         = (int) $timestamp;
		$timestamp         = ( $timestamp == 0 ? time() : $timestamp );

		$dosTime = $this->getDosTime( $timestamp );
		$tsPack  = pack( 'V', $timestamp );

		if ( $gpFlags && strlen( $gpFlags ) !== 2 ) {
			$gpFlags = "\x00\x00";
		}

		$isFileUTF8    = mb_check_encoding( $filePath, 'UTF-8' ) && ! mb_check_encoding( $filePath, 'ASCII' );
		$isCommentUTF8 = ! empty( $fileComment ) && mb_check_encoding( $fileComment, 'UTF-8' ) && ! mb_check_encoding( $fileComment, 'ASCII' );

		$localExtraField   = '';
		$centralExtraField = '';

		if ( $this->addExtraField ) {
			$localExtraField   .= "\x55\x54\x09\x00\x03" . $tsPack . $tsPack . self::EXTRA_FIELD_NEW_UNIX_GUID;
			$centralExtraField .= "\x55\x54\x05\x00\x03" . $tsPack . self::EXTRA_FIELD_NEW_UNIX_GUID;
		}

		if ( $isFileUTF8 || $isCommentUTF8 ) {
			$flag     = 0;
			$gpFlagsV = unpack( 'vflags', (string) $gpFlags );
			if ( isset( $gpFlagsV['flags'] ) ) {
				$flag = $gpFlagsV['flags'];
			}
			$gpFlags = pack( 'v', $flag | ( 1 << 11 ) );

			if ( $isFileUTF8 ) {
				$utfPathExtraField = "\x75\x70"
					. pack( 'v', ( 5 + strlen( $filePath ) ) )
					. "\x01"
					. pack( 'V', crc32( $filePath ) )
					. $filePath;

				$localExtraField   .= $utfPathExtraField;
				$centralExtraField .= $utfPathExtraField;
			}
			if ( $isCommentUTF8 ) {
				$centralExtraField .= "\x75\x63"
					. pack( 'v', ( 5 + strlen( $fileComment ) ) )
					. "\x01"
					. pack( 'V', crc32( $fileComment ) )
					. $fileComment;
			}
		}

		$header = $gpFlags . $gzType . $dosTime . $fileCRC32
			. pack( 'VVv', $gzLength, $dataLength, strlen( $filePath ) );

		$zipEntry = self::ZIP_LOCAL_FILE_HEADER
			. self::ATTR_VERSION_TO_EXTRACT
			. $header
			. pack( 'v', strlen( $localExtraField ) )
			. $filePath
			. $localExtraField;

		$this->zipwrite( $zipEntry );

		$cdEntry = self::ZIP_CENTRAL_FILE_HEADER
			. self::ATTR_MADE_BY_VERSION
			. ( $dataLength === 0 ? "\x0A\x00" : self::ATTR_VERSION_TO_EXTRACT )
			. $header
			. pack( 'v', strlen( $centralExtraField ) )
			. pack( 'v', $fileCommentLength )
			. "\x00\x00"
			. "\x00\x00"
			. pack( 'V', $extFileAttr )
			. pack( 'V', $this->offset )
			. $filePath
			. $centralExtraField;

		if ( ! empty( $fileComment ) ) {
			$cdEntry .= $fileComment;
		}

		$this->cdRec[] = $cdEntry;
		$this->offset += strlen( $zipEntry ) + $gzLength;
	}

	private function zipwrite( $data ) {
		if ( ! is_resource( $this->zipFile ) ) {
			$this->zipData .= $data;
		} else {
			fwrite( $this->zipFile, $data );
			fflush( $this->zipFile );
		}
	}

	private function zipflush() {
		if ( ! is_resource( $this->zipFile ) ) {
			$this->zipFile = tmpfile();
			$this->zipFile && fwrite( $this->zipFile, $this->zipData );
			$this->zipData = null;
		}
	}

	private static function getTemporaryFile() {
		if ( is_callable( self::$temp ) ) {
			$temporaryFile = @call_user_func( self::$temp );
			if ( is_string( $temporaryFile ) && strlen( $temporaryFile ) && is_writable( $temporaryFile ) ) {
				return $temporaryFile;
			}
		}
		$temporaryDirectory = ( is_string( self::$temp ) && strlen( self::$temp ) ) ? self::$temp : sys_get_temp_dir();
		return tempnam( $temporaryDirectory, 'Zip' );
	}
}

