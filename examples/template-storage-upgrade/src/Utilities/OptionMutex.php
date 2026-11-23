<?php
/**
 * Atomic expiring mutex backed by wp_options.
 *
 * @package MA_CO_AccessControlPro\Utilities
 */

namespace MA_CO_AccessControlPro\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small reusable compare-and-swap mutex for request-scoped mutations.
 *
 * The database path deliberately bypasses the option cache for ownership
 * checks. Fallbacks exist only for constrained test/bootstrap environments
 * where a complete wpdb implementation is unavailable.
 */
final class OptionMutex {

	private $option_name;
	private $ttl;
	private $attempts;
	private $retry_delay;
	private $busy_code;
	private $busy_message;

	public function __construct( $option_name, $ttl, $attempts, $retry_delay, $busy_code, $busy_message ) {
		$this->option_name = sanitize_key( (string) $option_name );
		$this->ttl         = max( 1, absint( $ttl ) );
		$this->attempts    = max( 1, absint( $attempts ) );
		$this->retry_delay = absint( $retry_delay );
		$this->busy_code   = sanitize_key( (string) $busy_code );
		$this->busy_message = (string) $busy_message;
	}

	/**
	 * Acquire the mutex.
	 *
	 * @param array<string,mixed> $error_data Optional WP_Error context.
	 * @return array{option_name:string,option_value:string}|\WP_Error
	 */
	public function acquire( array $error_data = [] ) {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ma-co-acp-', true );

		for ( $attempt = 0; $attempt < $this->attempts; ++$attempt ) {
			$option_value = (string) wp_json_encode(
				[
					'token'      => $token,
					'expires_at' => time() + $this->ttl,
				]
			);

			if ( add_option( $this->option_name, $option_value, '', 'no' ) ) {
				return $this->descriptor( $option_value );
			}

			$stored_value = $this->get_value_fresh();
			$stored_lock  = is_string( $stored_value ) ? json_decode( $stored_value, true ) : null;
			$is_expired   = ! is_array( $stored_lock )
				|| ! isset( $stored_lock['expires_at'] )
				|| (int) $stored_lock['expires_at'] <= time();

			if ( null === $stored_value ) {
				$this->clear_cache();
				if ( add_option( $this->option_name, $option_value, '', 'no' ) ) {
					return $this->descriptor( $option_value );
				}
			} elseif ( $is_expired && $this->delete_if_value( $stored_value ) && add_option( $this->option_name, $option_value, '', 'no' ) ) {
				return $this->descriptor( $option_value );
			}

			if ( $attempt + 1 < $this->attempts && $this->retry_delay > 0 ) {
				usleep( $this->retry_delay );
			}
		}

		return new \WP_Error( $this->busy_code, $this->busy_message, $error_data );
	}

	/** @param array<string,mixed> $mutex Descriptor returned by acquire(). */
	public function release( array $mutex ) {
		$option_name  = sanitize_key( (string) ( $mutex['option_name'] ?? '' ) );
		$option_value = isset( $mutex['option_value'] ) ? (string) $mutex['option_value'] : '';
		if ( $this->option_name !== $option_name || '' === $option_value ) {
			return;
		}

		$this->delete_if_value( $option_value );
	}

	/** @return array{option_name:string,option_value:string} */
	private function descriptor( $option_value ) {
		return [
			'option_name'  => $this->option_name,
			'option_value' => (string) $option_value,
		];
	}

	/** @return string|null */
	private function get_value_fresh() {
		global $wpdb;

		if ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$this->option_name
				)
			);

			return null === $value ? null : (string) $value;
		}

		$value = get_option( $this->option_name, null );
		return null === $value || false === $value ? null : (string) $value;
	}

	/** @return bool */
	private function delete_if_value( $option_value ) {
		global $wpdb;

		if ( is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$this->option_name,
					(string) $option_value
				)
			);
			if ( 1 === (int) $deleted ) {
				$this->clear_cache();
				return true;
			}
			return false;
		}

		$current = get_option( $this->option_name, null );
		if ( (string) $current !== (string) $option_value || ! function_exists( 'delete_option' ) ) {
			return false;
		}

		$deleted = delete_option( $this->option_name );
		if ( $deleted ) {
			$this->clear_cache();
		}
		return (bool) $deleted;
	}

	private function clear_cache() {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $this->option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
