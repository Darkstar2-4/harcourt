<?php

class WPML_Upgrade_Schema {

	private $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function does_table_exist( $table_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $this->has_results(
			$wpdb->get_results(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
			)
		);
	}

	public function does_column_exist( $table_name, $column_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $this->has_results(
			$wpdb->get_results(
				$wpdb->prepare( "SHOW COLUMNS FROM `{$table_name}` LIKE %s", $column_name )
			)
		);
	}

	public function does_index_exist( $table_name, $index_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $this->has_results(
			$wpdb->get_results(
				$wpdb->prepare( "SHOW INDEXES FROM `{$table_name}` WHERE key_name = %s", $index_name )
			)
		);
	}

	public function does_key_exist( $table_name, $key_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $this->has_results(
			$wpdb->get_results(
				$wpdb->prepare( "SHOW KEYS FROM `{$table_name}` WHERE key_name = %s", $key_name )
			)
		);
	}


	private function has_results( $results ) {
		return is_array( $results ) && count( $results );
	}

	public function add_column( $table_name, $column_name, $attribute_string ) {
		$wpdb        = $this->wpdb;
		$table_name  = $this->get_prefixed_table_name( $table_name );

		return $wpdb->query( "ALTER TABLE `{$table_name}` ADD `{$column_name}` {$attribute_string}" );
	}

	public function modify_column( $table_name, $column_name, $attribute_string ) {
		$wpdb        = $this->wpdb;
		$table_name  = $this->get_prefixed_table_name( $table_name );

		return $wpdb->query( "ALTER TABLE `{$table_name}` MODIFY COLUMN `{$column_name}` {$attribute_string}" );
	}

	public function add_index( $table_name, $index_name, $attribute_string ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `{$index_name}` {$attribute_string}" );
	}

	public function add_primary_key( $table_name, $key_columns ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );
		$key_columns = (array) $key_columns;

		return $wpdb->query( "ALTER TABLE `{$table_name}` ADD PRIMARY KEY (`" . implode( '`, `', $key_columns ) . '`)' );
	}

	public function drop_index( $table_name, $index_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `{$index_name}`" );
	}

	public function get_column_collation( $table_name, $column_name ) {
		$wpdb       = $this->wpdb;
		$table_name = $this->get_prefixed_table_name( $table_name );

		return $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s
				 	AND TABLE_NAME = %s
				 	AND COLUMN_NAME = %s',
				$wpdb->dbname,
				$table_name,
				$column_name
			)
		);
	}

	public function get_table_collation( $table_name ) {
		$wpdb = $this->wpdb;

		$table_data = $wpdb->get_row(
			$wpdb->prepare( 'SHOW TABLE status LIKE %s', $table_name )
		);

		if ( isset( $table_data->Collation ) ) {
			return $table_data->Collation;
		}

		return null;
	}

	public function get_default_collate() {
		$posts_table_collate = $this->get_table_collation( $this->wpdb->posts );

		if ( $posts_table_collate ) {
			return $posts_table_collate;
		} elseif ( ! empty( $this->wpdb->collate ) ) {
			return $this->wpdb->collate;
		}

		return null;
	}

	public function get_table_charset( $table_name ) {
		$wpdb = $this->wpdb;

		try {
			return $wpdb->get_var(
				$wpdb->prepare(
					'SELECT CCSA.character_set_name
					FROM information_schema.`TABLES` T,
					information_schema.`COLLATION_CHARACTER_SET_APPLICABILITY` CCSA
					WHERE CCSA.collation_name = T.table_collation
					AND T.table_schema = %s
					AND T.table_name = %s;',
					$wpdb->dbname,
					$table_name
				)
			);
		} catch ( Exception $e ) {
			return null;
		}
	}

	public function get_default_charset() {
		$post_table_charset = $this->get_table_charset( $this->wpdb->posts );

		if ( $post_table_charset ) {
			return $post_table_charset;
		} elseif ( ! empty( $this->wpdb->charset ) ) {
			return $this->wpdb->charset;
		}

		return null;
	}

	public function get_wpdb() {
		return $this->wpdb;
	}

	private function get_prefixed_table_name( $table_name ) {
		return $this->wpdb->prefix . $table_name;
	}

}
