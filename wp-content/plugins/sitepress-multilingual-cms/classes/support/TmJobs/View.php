<?php

namespace WPML\Support\TmJobs;

use WPML\TM\Jobs\Log\Hooks;

class View {

	private $logCount;

	public function __construct( int $logCount ) {
		$this->logCount = $logCount;
	}

	public function renderSupportSection() {
		?>
		<div class="wrap">
			<h2 id="tmjobs-log">
				<?php esc_html_e( 'Translation Management', 'wpml-translation-management' ); ?>
			</h2>
			<p>
				<a href="<?php echo admin_url( 'admin.php?page=' . Hooks::SUBMENU_HANDLE ); ?>">
					<?php echo sprintf( esc_html__( 'Logs (%d)', 'wpml-translation-management' ), $this->logCount ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
