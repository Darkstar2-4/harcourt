<?php

$widget_icl_lang_sel_widget = get_option( 'widget_icl_lang_sel_widget' );
if ( is_admin() && $widget_icl_lang_sel_widget ) {
	$widget_icl_lang_sel_widget['_multiwidget'] = 1;
	$sitepress_settings                         = get_option( 'icl_sitepress_settings' );
	$icl_widget_title_show                      = isset( $sitepress_settings['icl_widget_title_show'] ) ? $sitepress_settings['icl_widget_title_show'] : false;

	foreach ( $widget_icl_lang_sel_widget as $idx => $data ) {
		if ( is_array( $data ) && ! isset( $data['title_show'] ) ) {
			$widget_icl_lang_sel_widget[ $idx ]['title_show'] = $icl_widget_title_show;
		}
	}

	update_option( 'widget_icl_lang_sel_widget', $widget_icl_lang_sel_widget );
}
$sidebars = get_option( 'sidebars_widgets' );

$fixed = false;
foreach ( $sidebars as $sidebar_id => $widgets ) {
	if ( is_array( $widgets ) ) {
		foreach ( $widgets as $index => $widget_id ) {
			if ( $widget_id == 'icl_lang_sel_widget' ) {
				$sidebars[ $sidebar_id ][ $index ] = 'icl_lang_sel_widget-1';
				$fixed                             = true;
				break;
			}
		}
	}
	if ( $fixed ) {
		break;
	}
}

if ( $fixed ) {
	update_option( 'sidebars_widgets', $sidebars );
}
