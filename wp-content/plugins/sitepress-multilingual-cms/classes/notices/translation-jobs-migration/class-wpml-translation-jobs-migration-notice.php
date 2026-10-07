<?php

abstract class WPML_Translation_Jobs_Migration_Notice {

	const NOTICE_GROUP_ID = 'translation-jobs';
	const TEMPLATE        = 'translation-jobs-migration.twig';

	private $admin_notices;

	private $template_service;

	public function __construct( WPML_Notices $admin_notices, IWPML_Template_Service $template_service ) {
		$this->admin_notices    = $admin_notices;
		$this->template_service = $template_service;
	}

	public function add_notice() {
		$notice = $this->admin_notices->create_notice( $this->get_notice_id(), $this->get_notice_content(), self::NOTICE_GROUP_ID );
		$notice->set_css_class_types( 'notice-error' );
		$this->admin_notices->add_notice( $notice );
	}

	public function remove_notice() {
		$this->admin_notices->remove_notice( self::NOTICE_GROUP_ID, $this->get_notice_id() );
	}

	public function exists() {
		return (bool) $this->admin_notices->get_notice( $this->get_notice_id(), self::NOTICE_GROUP_ID );
	}

	private function get_notice_content() {
		return $this->template_service->show( $this->get_model(), self::TEMPLATE );
	}

	abstract protected function get_model();

	abstract protected function get_notice_id();
}
