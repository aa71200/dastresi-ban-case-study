<?php

namespace MA_CO_AccessControlPro\Database;

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

/**
 * Loads the database schema once and compares it with the plugin contract.
 *
 * The legacy implementation queried every column and index independently.
 * This inspector deliberately keeps the public status payload unchanged while
 * reducing inspection to at most three queries per table.
 */
final class SchemaInspector {

	/** @var array|null */
	private $status;

	/**
	 * Inspect the required schema.
	 *
	 * @param array  $required_tables  Table names keyed by logical name.
	 * @param array  $required_columns Required column names by logical table.
	 * @param array  $required_indexes Required index definitions by logical table.
	 * @param string $installed_version Installed database version.
	 * @param string $target_version Target database version.
	 * @param string $plugin_version Plugin version.
	 * @return array
	 */
	public function inspect( array $required_tables, array $required_columns, array $required_indexes, $installed_version, $target_version, $plugin_version ) {
		if ( null !== $this->status ) {
			return $this->status;
		}

		$tables          = [];
		$columns         = [];
		$indexes         = [];
		$missing_tables  = [];
		$missing_columns = [];
		$missing_indexes = [];

		foreach ( $required_tables as $table_key => $table_name ) {
			$snapshot = $this->load_table_snapshot( $table_name );
			$exists   = $snapshot['exists'];

			$tables[ $table_key ] = [
				'name'   => $table_name,
				'exists' => $exists,
			];

			if ( ! $exists ) {
				$missing_tables[] = $table_name;
			}

			foreach ( $required_columns[ $table_key ] ?? [] as $column_name ) {
				$column_exists = isset( $snapshot['columns'][ $column_name ] );

				$columns[ $table_key ][ $column_name ] = [
					'name'   => $column_name,
					'table'  => $table_name,
					'exists' => $column_exists,
				];

				if ( ! $column_exists ) {
					$missing_columns[] = $table_name . '.' . $column_name;
				}
			}

			foreach ( $required_indexes[ $table_key ] ?? [] as $index_name => $definition ) {
				$index_columns = isset( $definition['columns'] ) ? array_values( (array) $definition['columns'] ) : [];
				$unique        = ! empty( $definition['unique'] );
				$index_exists  = $this->index_matches( $snapshot['indexes'][ $index_name ] ?? [], $index_columns, $unique );

				$indexes[ $table_key ][ $index_name ] = [
					'name'    => $index_name,
					'table'   => $table_name,
					'columns' => $index_columns,
					'unique'  => $unique,
					'exists'  => $index_exists,
				];

				if ( ! $index_exists ) {
					$missing_indexes[] = $table_name . '.' . $index_name;
				}
			}
		}

		$this->status = [
			'installed_version' => $installed_version,
			'target_version'    => $target_version,
			'plugin_version'    => $plugin_version,
			'is_current'        => version_compare( $installed_version, $target_version, '>=' ) && empty( $missing_tables ) && empty( $missing_columns ) && empty( $missing_indexes ),
			'tables'            => $tables,
			'columns'           => $columns,
			'indexes'           => $indexes,
			'missing_tables'    => $missing_tables,
			'missing_columns'   => $missing_columns,
			'missing_indexes'   => $missing_indexes,
		];

		return $this->status;
	}

	/**
	 * Load one table using a fixed maximum of three queries.
	 *
	 * @param string $table_name Table name.
	 * @return array
	 */
	private function load_table_snapshot( $table_name ) {
		global $wpdb;

		$pattern = $wpdb->esc_like( $table_name );
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );

		if ( ! $exists ) {
			return [
				'exists'  => false,
				'columns' => [],
				'indexes' => [],
			];
		}

		$escaped_table = esc_sql( $table_name );
		$column_rows   = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $escaped_table . '`', ARRAY_A );
		$index_rows    = $wpdb->get_results( 'SHOW INDEX FROM `' . $escaped_table . '`', ARRAY_A );

		return [
			'exists'  => true,
			'columns' => $this->map_columns( is_array( $column_rows ) ? $column_rows : [] ),
			'indexes' => $this->group_indexes( is_array( $index_rows ) ? $index_rows : [] ),
		];
	}

	/**
	 * @param array $rows SHOW COLUMNS rows.
	 * @return array
	 */
	private function map_columns( array $rows ) {
		$columns = [];

		foreach ( $rows as $row ) {
			$name = (string) ( $row['Field'] ?? '' );

			if ( '' !== $name ) {
				$columns[ $name ] = true;
			}
		}

		return $columns;
	}

	/**
	 * @param array $rows SHOW INDEX rows.
	 * @return array
	 */
	private function group_indexes( array $rows ) {
		$indexes = [];

		foreach ( $rows as $row ) {
			$name = (string) ( $row['Key_name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$indexes[ $name ][] = $row;
		}

		return $indexes;
	}

	/**
	 * @param array $rows Expected index rows.
	 * @param array $columns Expected columns.
	 * @param bool  $unique Whether the index must be unique.
	 * @return bool
	 */
	private function index_matches( array $rows, array $columns, $unique ) {
		if ( empty( $rows ) ) {
			return false;
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return (int) ( $left['Seq_in_index'] ?? 0 ) <=> (int) ( $right['Seq_in_index'] ?? 0 );
			}
		);

		$actual_columns = array_map(
			static function ( $row ) {
				return (string) ( $row['Column_name'] ?? '' );
			},
			$rows
		);
		$is_unique = isset( $rows[0]['Non_unique'] ) && 0 === (int) $rows[0]['Non_unique'];

		return $columns === $actual_columns && (bool) $unique === $is_unique;
	}
}
