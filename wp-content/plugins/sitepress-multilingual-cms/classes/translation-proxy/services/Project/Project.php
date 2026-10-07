<?php

namespace WPML\TM\TranslationProxy\Services\Project;

class Project {
	public $id;

	public $accessKey;

	public $tsId;

	public $tsAccessKey;

	public $extraFields;

	public function toArray() {
		return [
			'id'            => $this->id,
			'access_key'    => $this->accessKey,
			'ts_id'         => $this->tsId,
			'ts_access_key' => $this->tsAccessKey,
			'extra_fields'  => $this->extraFields,
		];
	}

	public static function fromArray( array $data ) {
		$project = new Project();

		$project->id          = $data['id'];
		$project->accessKey   = $data['access_key'];
		$project->tsId        = $data['ts_id'];
		$project->tsAccessKey = $data['ts_access_key'];
		$project->extraFields = $data['extra_fields'];

		return $project;
	}

	public static function fromResponse( \stdClass $response ) {
		$project = new Project();

		$project->id          = $response->id;
		$project->accessKey   = $response->accesskey;
		$project->tsId        = $response->ts_id;
		$project->tsAccessKey = $response->ts_accesskey;

		return $project;
	}
}
