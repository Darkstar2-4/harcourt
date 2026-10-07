<?php

require_once WPML_PLUGIN_PATH . '/inc/functions-debug-information.php';
$debug_info = get_debug_info();
$debug_data = $debug_info->run();

?>
<div class="wrap">
	<h1><?php echo __( 'Debug information', 'sitepress' ); ?></h1>
	<?php

	$message = filter_input( INPUT_GET, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_NULL_ON_FAILURE );

	if ( $message ) {
		?>
		<div class="updated message fade">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php } ?>
	<div id="text-copied-div" class="updated message fade" style="display: none">
		<p id="text-copied-p">
			<?php echo esc_html_e( 'Copied to clipboard', 'sitepress' ); ?>
		</p>
	</div>
	<div id="poststuff">
		<div id="wpml-debug-info" class="postbox">
			<div class="inside">
				<p>
					<?php esc_html_e( 'This information allows our support team to see the versions of WordPress, plugins and theme on your site.', 'sitepress' ); ?>
					<br/>
					<?php esc_html_e( 'Provide this information if requested in our support forum. No passwords or other confidential information is included.', 'sitepress' ); ?>
				</p>
				<br/>
				<?php
				echo '<textarea style="font-size:10px;width:100%;height:150px;" rows="16" readonly="readonly" id="debug-info-textarea">';
				echo esc_html( $debug_info->do_json_encode( $debug_data ) );
				echo '</textarea>';
				?>
				<input value="<?php echo esc_attr_e( 'Copy to clipboard', 'sitepress' ); ?>" class="button-primary wpml-button base-btn" type="button" id="copy-debug-info-btn" />
			</div>
		</div>
	</div>

	<?php do_action( 'icl_menu_footer' ); ?>
</div>

<script>
jQuery( function( $ ) {
	$('#copy-debug-info-btn').on('click', function () {
		const text = $('#debug-info-textarea').val();

		navigator.clipboard.writeText(text)
			.then(() => {
				const $msg = $('#text-copied-div');

				$msg.stop(true, true).fadeIn(200);

				setTimeout(() => {
					$msg.fadeOut(2000);
				}, 1500);
			})
			.catch(err => console.error(err));
	});
});
</script>
