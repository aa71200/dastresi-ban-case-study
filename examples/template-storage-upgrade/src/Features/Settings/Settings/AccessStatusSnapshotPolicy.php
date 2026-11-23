<?php
/**
 * Central retention policy for access-status Studio snapshots.
 *
 * @package MA_CO_AccessControlPro\Features\Settings\Settings
 */

namespace MA_CO_AccessControlPro\Features\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One source of truth for snapshot retention across settings, UI and cleanup.
 */
final class AccessStatusSnapshotPolicy {

	const OPTION_NAME       = 'ma_co_acp_access_status_snapshot_retention_days';
	const STARTED_AT_OPTION = 'ma_co_acp_access_status_snapshot_retention_started_at';
	const DEFAULT_DAYS      = 30;
	const LEGACY_GRACE_DAYS = 7;
	const MIN_DAYS     = 1;
	const MAX_DAYS     = 3650;

	/** Make the default explicit without autoloading another settings option. */
	public static function ensure_default_option() {
		add_option( self::OPTION_NAME, self::DEFAULT_DAYS, '', 'no' );
		add_option( self::STARTED_AT_OPTION, time(), '', 'no' );
	}

	/** @return int */
	public static function get_days() {
		return self::sanitize_days( get_option( self::OPTION_NAME, self::DEFAULT_DAYS ) );
	}

	/** @param mixed $days Raw retention value. @return int */
	public static function sanitize_days( $days ) {
		$days = absint( $days );
		if ( $days < self::MIN_DAYS ) {
			return self::DEFAULT_DAYS;
		}
		return min( self::MAX_DAYS, $days );
	}

	/** @param mixed $created_at Snapshot creation timestamp. @return int */
	public static function expires_at( $created_at ) {
		$created_at = absint( $created_at );
		if ( $created_at <= 0 ) { return 0; }
		$expires_at = $created_at + ( self::get_days() * DAY_IN_SECONDS );
		$started_at = absint( get_option( self::STARTED_AT_OPTION, 0 ) );
		if ( $started_at > 0 && $created_at < $started_at ) {
			$expires_at = max( $expires_at, $started_at + ( self::LEGACY_GRACE_DAYS * DAY_IN_SECONDS ) );
		}
		return $expires_at;
	}

	/** Cutoff for bulk database pruning; zero while pre-feature snapshots are in their grace window. @return int */
	public static function cleanup_cutoff() {
		$started_at = absint( get_option( self::STARTED_AT_OPTION, 0 ) );
		if ( $started_at > 0 && time() < $started_at + ( self::LEGACY_GRACE_DAYS * DAY_IN_SECONDS ) ) { return 0; }
		return time() - ( self::get_days() * DAY_IN_SECONDS );
	}

	/** @param mixed $created_at Snapshot creation timestamp. @return bool */
	public static function is_expired( $created_at ) {
		$expires_at = self::expires_at( $created_at );
		return $expires_at > 0 && $expires_at <= time();
	}
}
