<?php

namespace OTGS\Installer\AdminNotices\Notices;

use OTGS\Installer\Recommendations\Storage;

class Dismissions {
	public static function dismissAccountNotice( $dismissed, $data ): array {
		$dismissed['repo'][ $data['repository'] ][ $data['noticeType'] ] = time();

		return $dismissed;
	}

	public static function dismissRecommendationNotice( $dismissed, $data ): array {
		Storage::dismissNotice( $data['noticePluginSlug'], $data['repository'] );

		return $dismissed;
	}
}
