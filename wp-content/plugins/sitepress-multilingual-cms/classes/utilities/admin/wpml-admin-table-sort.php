<?php

class WPML_Admin_Table_Sort {

	private $primary_column;

	private $url_args;

	private $current_url;

	private $orderby_param;

	private $order_param;

	public function __construct( $orderby_param = 'orderby', $order_param = 'order' ) {
		$this->orderby_param = $orderby_param;
		$this->order_param   = $order_param;
	}


	public function set_primary_column( $primary_column ) {
		$this->primary_column = $primary_column;
	}

	public function get_column_url( $column ) {
		$query_args = array(
			$this->orderby_param => $column,
			$this->order_param   => 'desc',
		);

		if ( $this->get_current_orderby() === $column && $this->get_current_order() === 'desc' ) {
			$query_args[ $this->order_param ] = 'asc';
		}

		return add_query_arg( $query_args, $this->get_current_url() );
	}

	public function get_column_classes( $column ) {
		$classes = 'manage-column column-' . $column;

		if ( $this->is_primary( $column ) ) {
			$classes .= ' column-primary';
		}

		if ( $this->get_current_orderby() === $column ) {
			$classes .= ' sorted ' . $this->get_current_order();
		} else {
			$classes .= ' sortable asc';
		}

		return $classes;
	}

	private function is_primary( $column ) {
		return $this->primary_column === $column;
	}

	private function get_current_orderby() {
		$url_args = $this->get_url_args();
		return isset( $url_args[ $this->orderby_param ] ) ? $url_args[ $this->orderby_param ] : null;
	}

	private function get_current_order() {
		$url_args = $this->get_url_args();
		return isset( $url_args[ $this->order_param ] ) ? $url_args[ $this->order_param ] : null;
	}

	public function get_current_sorters() {
		return array(
			$this->orderby_param => $this->get_current_orderby(),
			$this->order_param   => $this->get_current_order(),
		);
	}

	private function get_url_args() {
		if ( ! $this->url_args ) {
			$this->url_args = array();
			$url_query      = wpml_parse_url( $this->get_current_url(), PHP_URL_QUERY );
			parse_str( $url_query, $this->url_args );
		}

		return $this->url_args;
	}

	private function get_current_url() {
		if ( ! $this->current_url ) {
			$this->current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
			$this->current_url = remove_query_arg( 'paged', $this->current_url );
		}

		return $this->current_url;
	}
}
