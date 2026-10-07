<?php
namespace WPML\Core\BackgroundTask\Model;

use WPML\Collect\Support\Collection;
use WPML\FP\Left;
use WPML\FP\Right;

interface TaskEndpointInterface {

	public function isValidTask( $task_id );

	public function isDisplayed();

	public function getType();

	public function getMaxRetries();

	public function getLockTime();

	public function getTotalRecords( Collection $data );

	public function getDescription( Collection $data );

	public function run( Collection $data );
}
