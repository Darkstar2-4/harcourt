<?php

class WPML_TM_Word_Count_Records {

	private $post_records;

	private $package_records;

	private $string_records;

	public function __construct(
		WPML_TM_Word_Count_Post_Records $post_records,
		?WPML_ST_Word_Count_Package_Records $package_records = null,
		?WPML_ST_Word_Count_String_Records $string_records = null
	) {
		$this->post_records    = $post_records;
		$this->package_records = $package_records;
		$this->string_records  = $string_records;
	}

	public function get_all_post_ids_without_word_count() {
		return $this->post_records->get_all_ids_without_word_count();
	}

	public function get_post_word_count( $post_id ) {
		return new WPML_TM_Count( $this->post_records->get_word_count( $post_id ) );
	}

	public function set_post_word_count( $post_id, WPML_TM_Count $word_count ) {
		return $this->post_records->set_word_count( $post_id, $word_count->to_string() );
	}

	public function get_all_package_ids() {
		if ( $this->package_records ) {
			return $this->package_records->get_all_package_ids();
		}

		return array();
	}

	public function get_packages_ids_without_word_count() {
		if ( $this->package_records ) {
			return $this->package_records->get_packages_ids_without_word_count();
		}

		return array();
	}

	public function get_packages_word_counts( $post_id ) {
		$counts = array();

		if ( $this->package_records ) {
			$raw_counts = $this->package_records->get_word_counts( $post_id );

			foreach ( $raw_counts as $raw_count ) {
				$counts[] = new WPML_TM_Count( $raw_count );
			}
		}

		return $counts;
	}

	public function set_package_word_count( $package_id, WPML_TM_Count $word_count ) {
		if ( $this->package_records ) {
			$this->package_records->set_word_count( $package_id, $word_count->to_string() );
		}
	}

	public function get_package_word_count( $package_id ) {
		if ( $this->package_records ) {
			return new WPML_TM_Count( $this->package_records->get_word_count( $package_id ) );
		}

		return new WPML_TM_Count();
	}

	public function get_strings_total_words() {
		if ( $this->string_records ) {
			return $this->string_records->get_total_words();
		}

		return 0;
	}

	public function get_all_string_values_without_word_count() {
		if ( $this->string_records ) {
			return $this->string_records->get_all_values_without_word_count();
		}

		return array();
	}

	public function get_string_words_to_translate_per_lang( $lang, $package_id = null ) {
		if ( $this->string_records ) {
			return $this->string_records->get_words_to_translate_per_lang( $lang, $package_id );
		}

		return 0;
	}


	public function get_string_value_and_language( $string_id ) {
		if ( $this->string_records ) {
			return $this->string_records->get_value_and_language( $string_id );
		}

		return (object) array(
			'value'    => null,
			'language' => null,
		);
	}

	public function set_string_word_count( $id, $word_count ) {
		if ( $this->string_records ) {
			$this->string_records->set_word_count( $id, $word_count );
		}
	}

	public function get_string_word_count( $id ) {
		if ( $this->string_records ) {
			return $this->string_records->get_word_count( $id );
		}

		return 0;
	}

	public function reset_all( array $requested_types ) {
		if ( $this->package_records && isset( $requested_types['package_kinds'] ) ) {
			$this->package_records->reset_all( $requested_types['package_kinds'] );
		}

		if ( isset( $requested_types['post_types'] ) ) {
			$this->post_records->reset_all( $requested_types['post_types'] );
		}
	}

	public function get_package_ids_from_kind_slugs( array $kinds ) {
		if ( $this->package_records ) {
			return $this->package_records->get_ids_from_kind_slugs( $kinds );
		}

		return array();
	}

	public function get_package_ids_from_post_types( array $post_types ) {
		if ( $this->package_records ) {
			return $this->package_records->get_ids_from_post_types( $post_types );
		}

		return array();
	}

	public function get_strings_ids_from_package_ids( array $package_ids ) {
		if ( $this->string_records ) {
			return $this->string_records->get_ids_from_package_ids( $package_ids );
		}

		return array();
	}

	public function get_post_source_ids_from_types( array $package_ids ) {
		return $this->post_records->get_source_ids_from_types( $package_ids );
	}

	public function count_items_by_type( $group, $type ) {
		if ( $this->package_records && 'package_kinds' === $group ) {
			return $this->package_records->count_items_by_kind_not_part_of_posts( $type );
		} elseif ( 'post_types' === $group ) {
			return $this->post_records->count_source_items_by_type( $type );
		}

		return 0;
	}

	public function count_word_counts_by_type( $group, $type ) {
		if ( $this->package_records && 'package_kinds' === $group ) {
			return $this->package_records->count_word_counts_by_kind( $type );
		} elseif ( 'post_types' === $group ) {
			return $this->post_records->count_word_counts_by_type( $type );
		}

		return 0;
	}

	public function get_word_counts_by_type( $group, $type ) {
		if ( $this->package_records && 'package_kinds' === $group ) {
			$counts = $this->package_records->get_word_counts_by_kind( $type );
			return $this->build_count_composite_from_raw_counts( $counts );
		} elseif ( 'post_types' === $group ) {
			$counts = $this->post_records->get_word_counts_by_type( $type );
			return $this->build_count_composite_from_raw_counts( $counts );
		}

		return new WPML_TM_Count_Composite();
	}

	private function build_count_composite_from_raw_counts( array $raw_counts ) {
		$count_composite = new WPML_TM_Count_Composite();

		foreach ( $raw_counts as $raw_count ) {
			$count = new WPML_TM_Count( $raw_count );
			$count_composite->add_count( $count );
		}

		return $count_composite;
	}
}
