<?php

namespace OTGS\Installer\AdminNotices;

class Config {

	protected $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	protected function hasItem( array $messages, $item, $type ) {
		foreach ( $messages['repo'] as $repo => $ids ) {
			foreach ( $ids as $id => $noticeType ) {
				$index = is_array( $noticeType ) ? $id : $noticeType;

				if ( isset( $this->config['repo'][ $repo ][ $index ][ $type ] )
				     && in_array( $item, $this->config['repo'][ $repo ][ $index ][ $type ], true ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
