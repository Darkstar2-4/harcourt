<?php

use WPML\TM\Menu\McSetup\CfMetaBoxOption;

abstract class WPML_TM_MCS_Custom_Field_Settings_Menu {

	protected $settings_factory;

	private $unlock_button_ui;

	private $query_factory;

	private $query;

	private $custom_fields_keys;

	private $has_more;

	private $load_all_fields = false;

	private $highest_page_loaded;

	private $custom_field_options;

	const ITEMS_PER_PAGE = 20;

	public function __construct(
		WPML_Custom_Field_Setting_Factory $settings_factory,
		WPML_UI_Unlock_Button $unlock_button_ui,
		WPML_Custom_Field_Setting_Query_Factory $query_factory
	) {
		$this->settings_factory = $settings_factory;
		$this->unlock_button_ui = $unlock_button_ui;
		$this->query_factory    = $query_factory;

		$this->custom_field_options = array(
			WPML_IGNORE_CUSTOM_FIELD    => __( "Don't translate", 'wpml-translation-management' ),
			WPML_COPY_CUSTOM_FIELD      => __( 'Copy from original to translation', 'wpml-translation-management' ),
			WPML_COPY_ONCE_CUSTOM_FIELD => __( 'Copy once', 'wpml-translation-management' ),
			WPML_TRANSLATE_CUSTOM_FIELD => __( 'Translate', 'wpml-translation-management' ),
		);
	}

	public function init_data( array $args = array() ) {
		if ( null === $this->custom_fields_keys ) {
			$args = array_merge(
				array(
					'hide_system_fields'  => ! $this->settings_factory->show_system_fields,
					'items_per_page'      => self::ITEMS_PER_PAGE,
					'page'                => 1,
					'highest_page_loaded' => 1,
				),
				$args
			);

			$this->load_all_fields     = (int) $args['page'] < 0;
			$this->highest_page_loaded = (int) $args['highest_page_loaded'];

			$this->custom_fields_keys = $this->get_query()->get( array_merge( $args, [ 'items_per_page' => $args['items_per_page'] + 1 ] ) );

			$this->has_more = $this->load_all_fields
				? false
				: count( $this->custom_fields_keys ) > $args['items_per_page'];

			if ( $this->has_more ) {
				array_pop( $this->custom_fields_keys );
			}

			natcasesort( $this->custom_fields_keys );
		}
	}

	public function render() {
		ob_start();
		?>
		<div class="wpml-section wpml-section-<?php echo esc_attr( $this->kind_shorthand() ); ?>-translation"
			 id="ml-content-setup-sec-<?php echo esc_attr( $this->kind_shorthand() ); ?>">
			<div class="wpml-section-header">
				<h3><?php echo esc_html( $this->get_title() ); ?></h3>
				<p>
					<?php
					$toggle_system_fields = array(
						'url'  => htmlspecialchars(
							add_query_arg(
								array(
									'show_system_fields' => ! $this->settings_factory->show_system_fields,
								),
								admin_url( 'admin.php?page=' . WPML_TM_FOLDER . WPML_Translation_Management::PAGE_SLUG_SETTINGS ) . '#ml-content-setup-sec-' . $this->kind_shorthand()
							)
						),
						'text' => $this->settings_factory->show_system_fields ?
							__( 'Hide system fields', 'wpml-translation-management' ) :
							__( 'Show system fields', 'wpml-translation-management' ),
					);
					?>
					<a href="<?php echo esc_url( $toggle_system_fields['url'] ); ?>"><?php echo esc_html( $toggle_system_fields['text'] ); ?></a>
				</p>

			</div>
			<div class="wpml-section-content wpml-section-content-wide">
				<form
						id="icl_<?php echo esc_attr( $this->kind_shorthand() ); ?>_translation"
						data-type="<?php echo esc_attr( $this->kind_shorthand() ); ?>"
						name="icl_<?php echo esc_attr( $this->kind_shorthand() ); ?>_translation"
						class="wpml-custom-fields-settings" action="">
				<?php wp_nonce_field( 'icl_' . $this->kind_shorthand() . '_translation_nonce', '_icl_nonce' ); ?>
					<?php
					if ( empty( $this->custom_fields_keys ) ) {
						?>
						<p class="no-data-found">
							<?php echo esc_html( $this->get_no_data_message() ); ?>
						</p>
						<?php
					} else {
						if ( esc_attr( $this->kind_shorthand() ) === 'cf' ) {
							?>
                            <label><input class="wpml-checkbox-native" type="checkbox" <?php if ( CfMetaBoxOption::get() ) : ?> checked="checked"
							              <?php endif; ?>id="show_cf_meta_box" name="translate_media"
                                          value="1"/>&nbsp;<?php esc_html_e( 'Show "Multilingual Content Setup" meta box on post edit screen.', 'sitepress' ); ?>
                            </label>
							<?php
						}
						?>
                        <div class="wpml-flex-table wpml-translation-setup-table wpml-margin-top-sm">

							<?php echo $this->render_heading(); ?>

                            <div class="wpml-flex-table-body">
								<?php
								$this->render_body();
								?>
                            </div>
                        </div>

						<?php
						$this->render_pagination( self::ITEMS_PER_PAGE, 1 );
						?>

						<p class="buttons-wrap">
							<span class="icl_ajx_response"
								  id="icl_ajx_response_<?php echo esc_attr( $this->kind_shorthand() ); ?>"></span>
							<input type="submit" class="button-primary wpml-button base-btn"
								   value="<?php echo esc_attr__( 'Save', 'wpml-translation-management' ); ?>"/>
						</p>
						<?php
					}
					?>
				</form>
			</div>
			<!-- .wpml-section-content -->
		</div> <!-- .wpml-section -->
		<?php

		return ob_get_clean();
	}

	abstract protected function kind_shorthand();

	abstract protected function get_title();

	abstract protected function get_meta_type();

	abstract protected function get_setting( $key );

	private function render_radio( $cf_key, $html_disabled, $status, $ref_status ) {
		ob_start();
		?>
		<input class="wpml-radio-native" type="radio" name="<?php echo $this->get_radio_name( $cf_key ); ?>"
			   value="<?php echo esc_attr( $ref_status ); ?>"
			   aria-label="<?php echo esc_attr( $this->custom_field_options[ $ref_status ] ); ?> <?php echo esc_attr( $cf_key ); ?>"
			<?php echo $html_disabled; ?>
			<?php
				if ( $status == $ref_status ) :
					?>
					checked<?php endif; ?> />
			<?php

		return ob_get_clean();
	}

	private function get_radio_name( $cf_key ) {
		return 'cf[' . esc_attr( base64_encode( $cf_key ) ) . ']';
	}

	private function get_unlock_name( $cf_key ) {
		return 'cf_unlocked[' . esc_attr( base64_encode( $cf_key ) ) . ']';
	}

	private function render_heading() {
		ob_start();
		?>
		<div class="wpml-flex-table-header wpml-flex-table-sticky">
			<?php $this->render_search(); ?>
			<div class="wpml-flex-table-row">
				<div class="wpml-flex-table-cell name">
					<?php echo esc_html( $this->get_column_header( 'name' ) ); ?>
				</div>
				<div class="wpml-flex-table-cell text-center">
					<span id="do_not_translate">
						<?php echo esc_html__( "Don't translate", 'wpml-translation-management' ); ?>
					</span>
				</div>
				<div class="wpml-flex-table-cell text-center">
					<span id="copy_from_original">
						<?php echo esc_html_x( 'Copy', 'Verb', 'wpml-translation-management' ); ?>
					</span>
				</div>
				<div class="wpml-flex-table-cell text-center">
					<span id="copy_once">
						<?php echo esc_html__( 'Copy once', 'wpml-translation-management' ); ?>
					</span>
				</div>
				<div class="wpml-flex-table-cell text-center">
					<span id="translate">
						<?php echo esc_html__( 'Translate', 'wpml-translation-management' ); ?>
					</span>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	public function render_search( $search_string = '' ) {
		$search = new WPML_TM_MCS_Search_Factory();
		echo $search->create( $search_string )->render();
	}

	public function render_body() {
		foreach ( $this->custom_fields_keys as $cf_key ) {
			$setting       = $this->get_setting( $cf_key );
			$status        = $setting->status();
			$html_disabled = $setting->get_html_disabled();

			?>
			<div class="wpml-flex-table-row">
				<div class="wpml-flex-table-cell name">
					<?php
					$override = false;
					if ( ! apply_filters( 'wpml_custom_field_settings_override_lock_render', $override, $setting ) ) {
						$this->unlock_button_ui->render( $setting->is_read_only(), $setting->is_unlocked(), $this->get_radio_name( $cf_key ), $this->get_unlock_name( $cf_key ) );
					}

					echo esc_html( $cf_key );
					?>
				</div>
				<?php
				foreach ( $this->custom_field_options as $ref_status => $title ) {
					?>
					<div class="wpml-flex-table-cell text-center">
						<?php
						echo $this->render_radio( $cf_key, $html_disabled, $status, $ref_status );
						?>
					</div>
					<?php
				}
				?>
			</div>
			<?php
		}
	}

	public function render_pagination( $items_per_page, $current_page ) {
		$pagination = new WPML_TM_MCS_Pagination_Render_Factory( $items_per_page );

		if ( $this->load_all_fields ) {
			echo $pagination->create( count( $this->custom_fields_keys ), $current_page )->render();
			return;
		}

		$current_fields_count = count( $this->custom_fields_keys );
		$total_items_so_far   = 1 === $current_page && $current_fields_count < $items_per_page
			? $current_fields_count
			: $items_per_page * $this->highest_page_loaded + ( $this->has_more ? 1 : 0 );

		echo $pagination->create( $total_items_so_far, $current_page )->render();
	}

	abstract public function get_no_data_message();

	abstract public function get_column_header( $id );

	private function get_query() {
		if ( null === $this->query ) {
			$this->query = $this->query_factory->create( $this->get_meta_type() );
		}

		return $this->query;
	}
}
