<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
function devvn_ihotspot_donate_meta_box() {
	//post type
	$screens = array( 'points_image' );

	foreach ( $screens as $screen ) {
		add_meta_box(
			'devvn-ihotspot-donate-shortcode',
			__( 'Buy me a Coffee to keep me awake :)', 'devvn-image-hotspot' ),
			'devvn_ihotspot_donate_shortcode_callback',
			$screen,
			'side',
			'low'
		);
	}
}
add_action( 'add_meta_boxes', 'devvn_ihotspot_donate_meta_box' );
function devvn_ihotspot_donate_shortcode_callback(){
	$donate_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=CXLFN68QBQ6XU';
	$image_url = plugin_dir_url( __FILE__ ) . '../images/btn_donateCC_LG.gif';
	?>
	<a href="<?php echo esc_url( $donate_url ); ?>" title="<?php esc_attr_e( 'Donate', 'devvn-image-hotspot' ); ?>" target="_blank" rel="noopener noreferrer">
		<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php esc_attr_e( 'Donate', 'devvn-image-hotspot' ); ?>"/>
	</a>
	<?php
}