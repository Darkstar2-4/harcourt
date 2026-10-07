<?php

class WPML_TM_Validate_HTML {

	private $html = '';

	private $tags = array();

	private $error_count = 0;

	public function get_html() {
		return $this->html;
	}

	public function validate( $html ) {
		$html = $this->hide_wp_bugs( $html );
		$html = $this->hide_cdata( $html );
		$html = $this->hide_comments( $html );
		$html = $this->hide_self_closing_tags( $html );
		$html = $this->hide_scripts( $html );
		$html = $this->hide_styles( $html );

		$processed_html = '';
		$html_arr       = array(
			'processed' => $processed_html,
			'next'      => $html,
		);
		while ( '' !== $html_arr['next'] ) {
			$html_arr = $this->validate_next( $html_arr['next'] );
			if ( $html_arr ) {
				$processed_html .= $html_arr['processed'];
			}
		}

		$html = $processed_html;

		$html = $this->restore_styles( $html );
		$html = $this->restore_scripts( $html );
		$html = $this->restore_self_closing_tags( $html );
		$html = $this->restore_comments( $html );
		$html = $this->restore_wp_bugs( $html );

		$this->html = $html;

		return $this->error_count;
	}

	private function validate_next( $html ) {
		$regs = array();

		$pattern = '<\s*?([a-z]+|/[a-z]+)((?:.|\s)*?)>';
		mb_eregi( $pattern, $html, $regs );

		if ( $regs ) {
			$full_tag  = $regs[0];
			$pos       = mb_strpos( $html, $full_tag );
			$next_html = mb_substr( $html, $pos + mb_strlen( $full_tag ) );

			$tag    = $regs[1];
			$result = true;
			if ( '/' === mb_substr( $tag, 0, 1 ) ) {
				$result = $this->close_tag( mb_substr( $tag, 1 ) );
			} else {
				$this->open_tag( $tag );
			}

			if ( $result ) {
				$processed_html = mb_substr( $html, 0, $pos + mb_strlen( $full_tag ) );
			} else {
				$processed_html = mb_substr( $html, 0, (int) $pos ) . '<!-- wpml:html_fragment ' . $full_tag . ' -->';
			}

			return array(
				'processed' => $processed_html,
				'next'      => $next_html,
			);
		}

		return array(
			'processed' => $html,
			'next'      => '',
		);
	}

	private function hide_wp_bugs( $html ) {
		$html = str_replace( '< !--', '<    !--', $html );
		$pattern = '<([0-9]{1})';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_wp_bug_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_wp_bug_callback( $matches ) {
		return '<!-- wpml:wp_bug ' . $matches[0] . ' -->';
	}

	private function restore_wp_bugs( $html ) {
		$pattern  = '<!-- wpml:wp_bug (.*?) -->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'restore_bug_callback' ), $html, 'msri' );
		$html     = $filtered === false ? $html : $filtered;
		$html     = str_replace( '<    !--', '< !--', $html );

		return $html;
	}

	public function restore_bug_callback( $matches ) {
		return $matches[1];
	}

	private function hide_comments( $html ) {
		$pattern  = '<!--(.*?)-->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_comment_callback' ), $html, 'msri' );
		$html     = $filtered === false ? $html : $filtered;

		$pattern  = '<!((?!-- wpml:).*?)>';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_declaration_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_comment_callback( $matches ) {
		return '<!-- wpml:html_comment ' . base64_encode( $matches[0] ) . ' -->';
	}

	public function hide_declaration_callback( $matches ) {
		return '<!-- wpml:html_declaration ' . base64_encode( $matches[0] ) . ' -->';
	}

	private function restore_comments( $html ) {
		$pattern  = '<!-- wpml:html_comment (.*?) -->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'restore_encoded_content_callback' ), $html, 'msri' );
		$html     = $filtered === false ? $html : $filtered;

		$pattern  = '<!-- wpml:html_declaration (.*?) -->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'restore_encoded_content_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function restore_encoded_content_callback( $matches ) {
		return base64_decode( $matches[1] );
	}

	private function hide_self_closing_tags( $html ) {
		$self_closing_tags = array(
			'area',
			'base',
			'basefont',
			'br',
			'col',
			'command',
			'embed',
			'frame',
			'hr',
			'img',
			'input',
			'isindex',
			'link',
			'meta',
			'param',
			'source',
			'track',
			'wbr',
			'command',
			'keygen',
			'menuitem',
			'path',
			'polyline',
		);
		foreach ( $self_closing_tags as $self_closing_tag ) {
			$pattern  = '<\s*?' . $self_closing_tag . '((?:.|\s)*?)(>|/>)';
			$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_sct_callback' ), $html, 'msri' );
			$html = $filtered === false ? $html : $filtered;
		}

		$pattern = '<\s*?[^>]*/>';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_sct_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_sct_callback( $matches ) {
		return '<!-- wpml:html_self_closing_tag ' . str_replace( array( '<', '>' ), '', $matches[0] ) . ' -->';
	}

	private function restore_self_closing_tags( $html ) {
		$pattern = '<!-- wpml:html_self_closing_tag (.*?) -->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'restore_sct_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function restore_sct_callback( $matches ) {
		return '<' . $matches[1] . '>';
	}

	public function restore_html( $html ) {
		$html = $this->restore_cdata( $html );
		$html = $this->restore_html_fragments( $html );
		return $html;
	}
	private function restore_html_fragments( $html ) {
		$pattern  = '<!-- wpml:html_fragment (.*?) -->';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'restore_html_fragment_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function restore_html_fragment_callback( $matches ) {
		return $matches[1];
	}

	private function hide_scripts( $html ) {
		$pattern  = '<\s*?script\s*?>((?:.|\s)*?)</script\s*?>';
		$filtered = mb_ereg_replace_callback( $pattern, array( $this, 'hide_script_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_script_callback( $matches ) {
		return '<!-- wpml:script ' . base64_encode( $matches[0] ) . ' -->';
	}

	private function restore_scripts( $html ) {
		$pattern = '<!-- wpml:script (.*?) -->';
		$filtered    = mb_ereg_replace_callback( $pattern, array( $this, 'restore_encoded_content_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	private function hide_cdata( $html ) {
		$pattern = '<!\[CDATA\[((?:.|\s)*?)\]\]>';
		$filtered    = mb_ereg_replace_callback( $pattern, array( $this, 'hide_cdata_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_cdata_callback( $matches ) {
		return '<!-- wpml:cdata ' . base64_encode( $matches[0] ) . ' -->';
	}

	private function restore_cdata( $html ) {
		$pattern = '<!-- wpml:cdata (.*?) -->';
		$filtered    = mb_ereg_replace_callback( $pattern, array( $this, 'restore_encoded_content_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	private function hide_styles( $html ) {
		$pattern = '<\s*?style\s*?>((?:.|\s)*?)</style\s*?>';
		$filtered    = mb_ereg_replace_callback( $pattern, array( $this, 'hide_style_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	public function hide_style_callback( $matches ) {
		return '<!-- wpml:style ' . base64_encode( $matches[0] ) . ' -->';
	}

	private function restore_styles( $html ) {
		$pattern = '<!-- wpml:style (.*?) -->';
		$filtered    = mb_ereg_replace_callback( $pattern, array( $this, 'restore_encoded_content_callback' ), $html, 'msri' );

		return $filtered === false ? $html : $filtered;
	}

	private function open_tag( $tag ) {
		$tag = mb_strtolower( $tag );
		array_push( $this->tags, $tag );
	}

	private function close_tag( $tag ) {
		$tag      = mb_strtolower( $tag );
		$last_tag = end( $this->tags );
		if ( $last_tag === $tag ) {
			array_pop( $this->tags );

			return true;
		} else {
			$this->error_count ++;
			if ( in_array( $tag, $this->tags, true ) ) {
				do {
					array_pop( $this->tags );
				} while ( $this->tags && end( $this->tags ) !== $tag );
				array_pop( $this->tags );
			}

			return false;
		}
	}
}
