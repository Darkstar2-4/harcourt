<?php

use WPML\API\Sanitize;
use WPML\Element\API\Languages;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Lst;
use WPML\FP\Obj;
use WPML\FP\Relation;
use WPML\Setup\Option;
use WPML\TM\API\Translators;
use WPML\TM\ATE\Review\ApproveTranslations;
use WPML\TM\ATE\Review\Cancel;
use WPML\TM\ATE\Review\ReviewStatus;
use WPML\FP\Str;
use WPML\API\PostTypes;
use WPML\TM\Editor\Editor;
use WPML\TM\API\Jobs;
use WPML\TM\Menu\TranslationQueue\PostTypeFilters;
use function WPML\FP\pipe;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;

class WPML_Translations_Queue {

	private $sitepress;

	private $must_render_the_editor = false;

	private $translation_editor;

	private $editor;

	public function __construct( SitePress $sitepress, Editor $editor ) {
		$this->sitepress = $sitepress;
		$this->editor    = $editor;
	}

	public function init_hooks() {
		add_action( 'current_screen', array( $this, 'load' ) );
	}

	public function load() {
		if ( $this->must_open_the_editor() ) {
			$response = $this->editor->open( $_GET );

			\WPML\PostHog\Event\CaptureEvent::capture(
				( new EventInstanceService() )->getOpenTranslationEditorEvent(
					[
						'editor' => Obj::prop( 'editor', $response ),
						'job_id' => Obj::prop( 'job_id', $_GET )
					]
				)
			);

			if ( in_array( Obj::prop( 'editor', $response ), [ \WPML_TM_Editors::ATE, \WPML_TM_Editors::WP ] ) ) {
				wp_safe_redirect( Obj::prop('url', $response), 302, 'WPML' );
				return;
			} elseif (Relation::propEq( 'editor', WPML_TM_Editors::WPML, $response )) {
				$this->openClassicTranslationEditor( Obj::prop('jobObject', $response) );
            }
		}
	}

	private function openClassicTranslationEditor( $job_object ) {
		global $wpdb;

		\WPML\Translation\TranslationElements\MissingZlibNotice::maybeAddNotice( $job_object->get_id() );

		$this->must_render_the_editor = true;
		$this->translation_editor     = new WPML_Translation_Editor_UI(
			$wpdb,
			$this->sitepress,
			wpml_load_core_tm(),
			$job_object,
			new WPML_TM_Job_Action_Factory( wpml_tm_load_job_factory() ),
			new WPML_TM_Job_Layout( $wpdb, $this->sitepress->get_wp_api() )
		);
	}

	public function display() {
		if ( $this->must_render_the_editor ) {
			$this->translation_editor->render();

			return;
		}

		$jobs_url = admin_url( 'admin.php?page=' . WPML_TM_FOLDER . '/menu/main.php&sm=jobs' );
		?>
		<div class="wrap">
			<h2><?php echo __( 'Translations queue', 'sitepress' ); ?></h2>
			<p class="wpml-jobs-page-description translations-queue-description"><?php echo esc_html__( 'Content waiting for your review or translation. Use it to start or continue your work.', 'wpml-translation-management' ); ?></p>
			<p class="wpml-jobs-page-description">
				<?php
				printf(
					esc_html__( 'Need the full list for monitoring or troubleshooting? Go to %s.', 'sitepress' ),
					'<a href="' . esc_url( $jobs_url ) . '">' . esc_html__( 'Translation Jobs', 'sitepress' ) . '</a>'
				);
				?>
			</p>
			<div class="js-wpml-abort-review-dialog"></div>
			<div id='wpml-remote-jobs-container'></div>
		</div>
		<?php
	}

	private function must_open_the_editor() {
		return Obj::prop( 'job_id', $_GET ) > 0 || Obj::prop( 'trid', $_GET ) > 0;
	}

	public static function get_cookie_filters() {
		$filters = [];

		if ( isset( $_COOKIE['wp-translation_ujobs_filter'] ) ) {
			parse_str( $_COOKIE['wp-translation_ujobs_filter'], $filters );

			$filters = filter_var_array(
				$filters,
				[
					'type'   => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'from'   => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'to'     => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
					'status' => FILTER_SANITIZE_NUMBER_INT,
				]
			);

			$isTypeValid = Logic::anyPass(
                [
                    Str::startsWith( 'package_' ),
                    Str::includes( 'st-batch_strings' ),
                    pipe( Str::replace( 'post_', '' ), Lst::includes( Fns::__, PostTypes::getTranslatable() ) ),
                ]
            );

			$activeLanguageCodes = Obj::keys( Languages::getActive() );

			if (
				$filters['from'] && ! Lst::includes( $filters['from'], $activeLanguageCodes ) ||
				$filters['to'] && ! Lst::includes( $filters['to'], $activeLanguageCodes ) ||
				( $filters['type'] && ! $isTypeValid( $filters['type'] ) )
			) {
				$filters = [];
			}
		}

		return $filters;
	}
}
