<?php

namespace OTGS\Installer\Templates\Repository;

class Refunded {

	public static function render( $model ) {
		?>
		<div class="otgs-installer-registered otgs-installer-expired clearfix">
			<div class="notice inline otgs-installer-notice otgs-installer-notice-refund otgs-installer-notice-refund--cards">
				<?php static::renderContent( $model ); ?>
			</div>
		</div>
		<?php
	}

	public static function renderContent( $model ) {
		$withProduct = function ( $str ) use ( $model ) {
			return sprintf( $str, $model->productName );
		};

		$headerTitle = sprintf( __( '%s registration needs your attention', 'installer' ), $model->productName );
		$headerIntro = sprintf(
			__( 'Your %1$s order was refunded, so this site no longer has an active license. Your translations are safe — pick the option below that matches your situation to keep %1$s working.', 'installer' ),
			$model->productName
		);

		$card1Title = __( 'I need to use a different wpml.org account', 'installer' );
		$card1Desc  = __( 'Use this if you bought WPML on a different account, you\'re taking over a site from someone else, or ownership is being transferred. We\'ll remove the current site key so you can register a new one.', 'installer' );

		$card2Title    = __( "I've already bought WPML again", 'installer' );
		$card2Desc     = __( 'If you re-purchased WPML on the same wpml.org account, sync this site to refresh its license.', 'installer' );
		$card2Button   = __( 'Check my order status', 'installer' );
		$card2Footnote = __( 'Bought it on a different account? See option 1 above.', 'installer' );

		$card3Title = __( 'I want to renew WPML now', 'installer' );
		?>
		<div class="otgs-installer-refund-header">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div class="otgs-installer-refund-header-text">
				<h2><?php echo esc_html( $headerTitle ); ?></h2>
				<p><?php echo esc_html( $headerIntro ); ?></p>
			</div>
		</div>

		<div class="otgs-installer-refund-cards">

			<div class="otgs-installer-refund-card">
				<div class="otgs-installer-refund-card-number" aria-hidden="true">1</div>
				<div class="otgs-installer-refund-card-body">
					<h3><?php echo esc_html( $card1Title ); ?></h3>
					<p><?php echo esc_html( $card1Desc ); ?></p>
					<?php if ( $model->shouldDisplayUnregisterLink ) :
						$hardcoded       = \WP_Installer::get_repository_hardcoded_site_key( $model->repoId );
						$unregisterLabel = sprintf( __( 'Unregister current %s site-key.', 'installer' ), $model->productName );
						if ( $hardcoded ) :
							$hardcodedTitle = sprintf(
								esc_attr__( 'Site-key was set by %s, most likely in wp-config.php. Please remove the constant before attempting to unregister.', 'installer' ),
								'OTGS_INSTALLER_SITE_KEY_' . strtoupper( $model->repoId )
							);
							?>
							<a class="remove_site_key_js button"
							   href="#"
							   data-repository="<?php echo esc_attr( $model->repoId ); ?>"
							   data-nonce="<?php echo esc_attr( $model->removeSiteKeyNonce ); ?>"
							   disabled="disabled"
							   title="<?php echo $hardcodedTitle; ?>"
							>
								<?php echo esc_html( $unregisterLabel ); ?>
							</a>
						<?php else : ?>
							<button type="button" class="button js-otgs-unregister-toggle">
								<?php echo esc_html( $unregisterLabel ); ?>
							</button>

							<div class="otgs-installer-refund-confirm" style="display:none;">
								<p>
									<strong><?php esc_html_e( 'Remove the current site key?', 'installer' ); ?></strong><br />
									<?php esc_html_e( 'This unregisters this site from the refunded WPML account so you can enter a new key. Your translations are not affected.', 'installer' ); ?>
								</p>
								<button type="button" class="button js-otgs-unregister-cancel">
									<?php esc_html_e( 'Cancel', 'installer' ); ?>
								</button>
								<a class="remove_site_key_js button button-primary"
								   href="#"
								   data-repository="<?php echo esc_attr( $model->repoId ); ?>"
								   data-nonce="<?php echo esc_attr( $model->removeSiteKeyNonce ); ?>"
								>
									<?php esc_html_e( 'Yes, unregister', 'installer' ); ?>
								</a>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="otgs-installer-refund-card">
				<div class="otgs-installer-refund-card-number" aria-hidden="true">2</div>
				<div class="otgs-installer-refund-card-body">
					<h3><?php echo esc_html( $card2Title ); ?></h3>
					<p><?php echo esc_html( $card2Desc ); ?></p>
					<a class="update_site_key_js button button-primary"
					   href="#"
					   data-repository="<?php echo esc_attr( $model->repoId ); ?>"
					   data-nonce="<?php echo esc_attr( $model->updateSiteKeyNonce ); ?>"
					>
						<?php echo esc_html( $card2Button ); ?>
					</a>
					<div class="installer-error-box" style="display:none;"></div>
					<p class="description"><?php echo esc_html( $card2Footnote ); ?></p>
				</div>
			</div>

			<div class="otgs-installer-refund-card">
				<div class="otgs-installer-refund-card-number" aria-hidden="true">3</div>
				<div class="otgs-installer-refund-card-body">
					<h3><?php echo esc_html( $card3Title ); ?></h3>
					<?php EndUsers::render( $withProduct, $model ); ?>
				</div>
			</div>

		</div>
		<?php
	}
}
