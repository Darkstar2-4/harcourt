<?php
/**!
* Setup
*/

add_action( 'acf/init', 'momentumst_register_options_page' );
if ( ! function_exists( 'momentumst_register_options_page' ) ) {
	function momentumst_register_options_page() {
		if ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_page();
		}
	}
}

if ( ! function_exists('momentumst_setup') ) {
	function momentumst_setup() {
		add_editor_style('theme/css/editor-style.css');
		add_theme_support('title-tag');
		add_theme_support('custom-logo');
		add_theme_support('post-thumbnails');

		update_option('thumbnail_size_w', 285); /* internal max-width of col-3 */
		update_option('small_size_w', 350); /* internal max-width of col-4 */
		update_option('medium_size_w', 730); /* internal max-width of col-8 */
		update_option('large_size_w', 1110); /* internal max-width of col-12 */

		if ( ! isset($content_width) ) {
			$content_width = 1100;
		}

		add_theme_support( 'post-formats', array(
			'aside',
			'gallery',
			'link',
			'image',
			'quote',
			'status',
			'video',
			'audio',
			'chat'
		) );

		add_theme_support('automatic-feed-links');
	}
}
add_action('init', 'momentumst_setup');

if ( ! function_exists( 'momentumst_avatar_attributes' ) ) {
	function momentumst_avatar_attributes($avatar_attributes) {
		$display_name = get_the_author_meta( 'display_name' );
		$avatar_attributes = str_replace('alt=\'\'', 'alt=\'Avatar for '.$display_name.'\' title=\'Gravatar for '.$display_name.'\'',$avatar_attributes);
		return $avatar_attributes;
	}
}
add_filter('get_avatar','momentumst_avatar_attributes');

if ( ! function_exists( 'momentumst_author_avatar' ) ) {
	function momentumst_author_avatar() {

		echo get_avatar('', $size = '96');
	}
}

if ( ! function_exists( 'momentumst_author_description' ) ) {
	function momentumst_author_description() {
		echo get_the_author_meta('user_description');
	}
}

if ( ! function_exists( 'momentumst_post_date' ) ) {
	function momentumst_post_date() {
		if ( in_array( get_post_type(), array( 'post', 'attachment' ) ) ) {
			$time_string = '<time itemprop="datePublished" class="entry-date published" datetime="%1$s" content="%2$s" >%2$s</time>';

			if ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) {
				$time_string = '<time itemprop="datePublished" class="entry-date published" datetime="%1$s" content="%2$s">%2$s</time> <time itemprop="dateModified" class="updated" datetime="%3$s" content="%4$s">(updated %4$s)</time>';
			}

			$time_string = sprintf( $time_string,
			esc_attr( get_the_date( 'c' ) ),
			get_the_date(),
			esc_attr( get_the_modified_date( 'c' ) ),
			get_the_modified_date()
		);

		echo $time_string;
	}
}
}

if ( ! function_exists('momentumst_excerpt_more') ) {
	function momentumst_excerpt_more() {
		return '&hellip;</p><p><a class="btn btn-primary" href="'. get_permalink() . '">' . __('Continue reading', 'momentumst') . ' <i class="fas fa-arrow-right"></i>' . '</a></p>';
	}
}
add_filter('excerpt_more', 'momentumst_excerpt_more');

function content($limit) {
	$content = explode(' ', get_the_content(), $limit);
	if (count($content)>=$limit) {
		array_pop($content);
		$content = implode(" ",$content).'...';
	} else {
		$content = implode(" ",$content);
	}
	$content = preg_replace('/[.+]/','', $content);
	$content = apply_filters('the_content', $content);
	$content = str_replace(']]>', ']]&gt;', $content);
	return $content;
}

function excerpt($limit) {
	$excerpt = explode(' ', get_the_excerpt(), $limit);
	if (count($excerpt)>=$limit) {
		array_pop($excerpt);
		$excerpt = implode(" ",$excerpt).'...';
	} else {
		$excerpt = implode(" ",$excerpt);
	}
	$excerpt = preg_replace('`[[^]]*]`','',$excerpt);
	return $excerpt;
}

function more_posts() {
	global $wp_query;
	return $wp_query->current_post + 1 < $wp_query->post_count;
}

function sm_custom_meta() {
    add_meta_box( 'sm_meta', __( 'Featured Posts', 'sm-textdomain' ), 'sm_meta_callback', 'post' );
}
function sm_meta_callback( $post ) {
    $featured = get_post_meta( $post->ID );
    ?>

	<p>
    <div class="sm-row-content">
        <label for="meta-checkbox">
            <input type="checkbox" name="meta-checkbox" id="meta-checkbox" value="yes" <?php if ( isset ( $featured['meta-checkbox'] ) ) checked( $featured['meta-checkbox'][0], 'yes' ); ?> />
            <?php _e( 'Feature this post', 'sm-textdomain' )?>
        </label>

    </div>
</p>

    <?php
}
add_action( 'add_meta_boxes', 'sm_custom_meta' );

/** * Saves the custom meta input */
function sm_meta_save( $post_id ) {

	// Checks save status
	$is_autosave = wp_is_post_autosave( $post_id );
	$is_revision = wp_is_post_revision( $post_id );
	$is_valid_nonce = ( isset( $_POST[ 'sm_nonce' ] ) && wp_verify_nonce( $_POST[ 'sm_nonce' ], basename( __FILE__ ) ) ) ? 'true' : 'false';

	// Exits script depending on save status
	if ( $is_autosave || $is_revision || !$is_valid_nonce ) {
		return;
	}

	// Checks for input and saves
	if( isset( $_POST[ 'meta-checkbox' ] ) ) {
		update_post_meta( $post_id, 'meta-checkbox', 'yes' );
	} else {
		update_post_meta( $post_id, 'meta-checkbox', '' );
	}

}
add_action( 'save_post', 'sm_meta_save' );

add_image_size( 'blog-featured', 600, 600, array( 'center', 'top' ) );
add_image_size( 'blog-thumb', 435, 220, array( 'center', 'top' ) );
