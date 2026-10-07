<?php

class WPML_PO_Import_Review_Table {

	public function render( $strings, $use_po_translations ) {
		$k = - 1;
		foreach ( $strings as $str ) :
			$k ++;
			?>
			<tr>
				<td><input class="icl_st_row_cb js-icl-st-row-cb" type="checkbox" name="icl_strings_selected[]"
					<?php
					if ( $str['exists'] || $use_po_translations !== true ) :
						?>
						checked="checked"<?php endif; ?> value="<?php echo $k; ?>" /></td>
				<td>
					<input type="text" name="icl_strings[]" value="<?php echo esc_attr( $str['string'] ); ?>" readonly="readonly" style="width:100%;" size="100" />
					<?php if ( $use_po_translations === true ) : ?>
					<input type="text" name="icl_translations[]" value="<?php echo esc_attr( $str['translation'] ); ?>" readonly="readonly" style="width:100%;
																						   <?php
																							if ( $str['fuzzy'] ) :
																								?>
 ;background-color:#ffecec<?php endif; ?>" size="100" />
					<input type="hidden" name="icl_fuzzy[]" value="<?php echo $str['fuzzy']; ?>" />
					<input type="hidden" name="icl_name[]" value="<?php echo esc_attr( $str['name'] ); ?>" />
					<input type="hidden" name="icl_context[]" value="<?php echo esc_attr( $str['context'] ); ?>" />
					<?php endif; ?>
					<?php if ( $str['name'] != md5( $str['string'] ) ) : ?>
						<i><?php printf( esc_html__( 'Name: %s', 'wpml-string-translation' ), esc_html( $str['name'] ) ); ?></i><br/>
					<?php endif ?>
				</td>
			</tr>
			<?php
		endforeach;
	}
}
