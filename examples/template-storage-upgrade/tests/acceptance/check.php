<?php
/**
 * Real WordPress + MySQL/MariaDB integration. Creates and drops ONLY its own random test database.
 * ACP_TEST_WP_ROOT, ACP_TEST_DB_HOST, ACP_TEST_DB_USER, ACP_TEST_DB_PASSWORD are explicit inputs.
 * No site's wp-config.php is loaded, and no existing database is used.
 */
if ( ! getenv( 'ACP_TEST_WP_ROOT' ) || ! getenv( 'ACP_TEST_DB_HOST' ) ) {
	echo "SKIPPED: Set ACP_TEST_WP_ROOT and ACP_TEST_DB_HOST to run isolated database integration.\n";
	exit( 0 );
}

$root = dirname( __DIR__, 2 );
$wp_root = rtrim( str_replace( '\\', '/', getenv( 'ACP_TEST_WP_ROOT' ) ), '/' ) . '/';
if ( ! is_file( $wp_root . 'wp-settings.php' ) ) { throw new RuntimeException( 'WordPress core not found.' ); }
$host_parts = explode( ':', getenv( 'ACP_TEST_DB_HOST' ), 2 );
$db_user = getenv( 'ACP_TEST_DB_USER' ) ?: 'root';
$db_password = getenv( 'ACP_TEST_DB_PASSWORD' ) ?: '';
$admin = new mysqli( $host_parts[0], $db_user, $db_password, '', isset( $host_parts[1] ) ? (int) $host_parts[1] : 3306 );
$db_name = 'codex_acp_storage_' . bin2hex( random_bytes( 8 ) );
if ( ! preg_match( '/\Acodex_acp_storage_[a-f0-9]{16}\z/', $db_name ) ) { throw new RuntimeException( 'Unsafe test name.' ); }
$admin->query( 'CREATE DATABASE `' . $db_name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
register_shutdown_function( static function () use ( $admin, $db_name ) {
	$admin->query( 'DROP DATABASE IF EXISTS `' . $db_name . '`' );
} );

define( 'ABSPATH', $wp_root );
define( 'WP_INSTALLING', true );
define( 'DB_NAME', $db_name );
define( 'DB_USER', $db_user );
define( 'DB_PASSWORD', $db_password );
define( 'DB_HOST', getenv( 'ACP_TEST_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'WP_CACHE', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_CONTENT_DIR', getenv( 'ACP_TEST_CONTENT_DIR' ) ?: sys_get_temp_dir() . '/dastresi-ban-storage-wp-content' );
define( 'WP_CONTENT_URL', 'http://storage.test/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'WP_HOME', 'http://storage.test' );
define( 'WP_SITEURL', 'http://storage.test' );
define( 'MA_CO_ACP_VERSION', '2.0.0' );
define( 'MA_CO_ACP_PATH', $root . '/' );
define( 'MA_CO_ACP_URL', 'http://storage.test/wp-content/plugins/dastresi-ban/' );
// WordPress 5.8 expects mysqli's PHP 7.4 non-exception default, changed by PHP 8.1.
mysqli_report( MYSQLI_REPORT_OFF );
$table_prefix = 'acptest_';
$_SERVER['HTTP_HOST'] = 'storage.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( wp_get_db_schema() );
wp_cache_flush();
wp_installing( false );
update_option( 'home', WP_HOME );
update_option( 'siteurl', WP_SITEURL );
update_option( 'blog_charset', 'UTF-8' );

spl_autoload_register( static function ( $class ) use ( $root ) {
	$prefix = 'MA_CO_AccessControlPro\\';
	if ( 0 === strpos( $class, $prefix ) ) {
		$file = $root . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) { require_once $file; }
	}
} );

class StorageTestWpdb extends wpdb {
	public $before_publish;
	public $template_reads = 0;
	public $fail_match;
	public $fail_at = 1;
	public $matches = 0;
	public function query( $query ) {
		if ( preg_match( '/^SELECT template_id, data FROM/', $query ) ) { ++$this->template_reads; }
		if ( $this->before_publish && preg_match( '/^UPDATE .*options SET option_value =/', $query ) ) {
			$callback = $this->before_publish;
			$this->before_publish = null;
			$callback();
		}
		if ( $this->fail_match && preg_match( $this->fail_match, $query ) && ++$this->matches === $this->fail_at ) {
			$this->flush();
			$this->last_error = 'Injected storage failure';
			return false;
		}
		return parent::query( $query );
	}
	public function fail( $pattern, $at = 1 ) { $this->fail_match = $pattern; $this->fail_at = $at; $this->matches = 0; }
}
$wpdb = new StorageTestWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->set_prefix( $table_prefix );
$wpdb->suppress_errors( true );

use MA_CO_AccessControlPro\Database\DatabaseManager;
use MA_CO_AccessControlPro\Database\AccessStatusStorage as Storage;
use MA_CO_AccessControlPro\Database\AccessStatusMigration as Migration;
use MA_CO_AccessControlPro\Database\AccessStatusSchema as Schema;
use MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateRepository as Repository;
use MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusRenderer;
use MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusStudioService as Studio;
use MA_CO_AccessControlPro\Utilities\OptionMutex;

$passes = 0;
$assert = static function ( $ok, $message ) use ( &$passes ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	++$passes;
};
$reset = static function () use ( $wpdb ) {
	$wpdb->fail( null );
	$wpdb->before_publish = null;
	delete_transient( 'ma_co_acp_database_upgrade_retry' );
	( new DatabaseManager() )->drop_tables();
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ma\\_co\\_acp\\_%'" );
	wp_cache_flush();
	( new Storage() )->refresh();
};
$payload = static function () {
	$repository = new Repository();
	$state = [ 'schema_version' => Repository::SCHEMA_VERSION, 'factory_revision' => Repository::FACTORY_REVISION,
		'default_template' => 'custom-test1234', 'templates' => $repository->get_factory_templates() ];
	$custom = $state['templates']['classic'];
	$custom['id'] = 'custom-test1234'; $custom['name'] = 'قالب آزمایشی فارسی';
	$custom['origin'] = 'custom'; $custom['factory_id'] = ''; $custom['factory_revision'] = 0;
	$state['templates']['custom-test1234'] = $custom;
	$state['template_visibility']['custom-test1234'] = [ 'product_title' => false ];
	$state['shared_base']['content']['messages']['guest'] = 'پیام مهمان فارسی';
	return $state;
};

if ( defined( 'ACP_PUBLICATION_INTEGRATION' ) ) { return; }

$reset();
update_option( 'ma_co_acp_time_source', 'offset' );
$result = ( new DatabaseManager() )->install();
$assert( $result['success'], 'Fresh install: ' . implode( ', ', $result['errors'] ) );
$assert( count( $result['status']['tables'] ) === 5 && $result['status']['is_current'], 'Five verified tables and migrated state required.' );
$assert( get_option( 'ma_co_acp_time_source' ) === 'offset', 'Simple settings changed.' );
$assert( get_option( 'ma_co_acp_db_version' ) === DatabaseManager::DB_VERSION, 'Database version must be independent of plugin 2.0.0.' );
$assert( in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", Storage::META_OPTION ) ), [ 'no', 'off', 'auto-off' ], true ), 'Shared metadata must not autoload.' );

$reset();
// The published v1 has the same three tables, without the log_level column.
$manager = new DatabaseManager();
$manager->create_tables( false );
$wpdb->query( "ALTER TABLE {$wpdb->prefix}ma_co_acp_logs DROP COLUMN log_level" );
foreach ( Schema::tables() as $table ) { $wpdb->query( "DROP TABLE {$table}" ); }
$wpdb->insert( $wpdb->prefix . 'ma_co_acp_restrictions', [ 'post_id' => 81, 'enabled' => 1, 'data' => '{"who":"roles","roles":["subscriber"]}' ] );
$wpdb->insert( $wpdb->prefix . 'ma_co_acp_shortcode_locks', [ 'post_id' => 81, 'lock_id' => 'legacy-lock' ] );
$wpdb->insert( $wpdb->prefix . 'ma_co_acp_logs', [ 'post_id' => 81, 'event_type' => 'access_denied', 'reason' => 'نسخه مارکت' ] );
update_option( 'ma_co_acp_db_version', '1.0.0' );
$result = ( new DatabaseManager() )->run_manual_upgrade();
$assert( $result['success'], 'v1 upgrade failed: ' . implode( ', ', $result['errors'] ) );
foreach ( [ 'restrictions', 'shortcode_locks', 'logs' ] as $suffix ) {
	$assert( '1' === $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ma_co_acp_{$suffix} WHERE post_id=81" ), 'Published ' . $suffix . ' data lost.' );
}

$reset();
$raw = $payload();
$history = [ [ 'id' => 'legacy-snapshot', 'created_at' => 12345, 'user_id' => 7, 'user_name' => 'مدیر', 'reason' => 'save', 'hash' => Storage::hash( $raw ), 'state' => $raw ] ];
update_option( Repository::OPTION_NAME, $raw, false );
update_option( Studio::HISTORY_OPTION, $history, false );
update_option( 'ma_co_acp_db_version', '2.0.0' );
$wpdb->fail( '/^INSERT INTO `[^`]*ma_co_acp_access_status_templates`/', 2 );
$result = ( new DatabaseManager() )->run_manual_upgrade();
$assert( ! $result['success'] && get_option( 'ma_co_acp_db_version' ) === '2.0.0', 'Failed migration falsely advanced version.' );
$assert( ! ( new Storage() )->is_active(), 'Interrupted generation became authoritative.' );
$assert( get_option( Repository::OPTION_NAME ) === $raw && get_option( Studio::HISTORY_OPTION ) === $history, 'Legacy sources changed.' );
$assert( get_option( Storage::BACKUP_OPTION )['templates'] === $raw, 'Raw backup missing or normalized.' );
$wpdb->fail( null );
$result = ( new DatabaseManager() )->run_manual_upgrade();
$assert( $result['success'], 'Retry failed: ' . implode( ', ', $result['errors'] ) );
$repository = new Repository();
$state = $repository->get_state();
$assert( $state['default_template'] === 'custom-test1234' && $state['templates']['custom-test1234']['name'] === 'قالب آزمایشی فارسی', 'Stable ID/Persian data lost.' );
$assert( ( new Storage() )->history() === $history, 'Legacy history did not round-trip exactly.' );
$meta = get_option( Storage::META_OPTION );
$assert( ( new Migration() )->run() === true && get_option( Storage::META_OPTION ) === $meta, 'Migration is not idempotent.' );
$assert( ( new Storage() )->is_active(), 'Successful retry did not publish the migrated generation.' );
echo "$passes migration checks passed.\n";
if ( getenv( 'CODEXAWP_TRACE_INCLUDES' ) ) {
	file_put_contents( getenv( 'CODEXAWP_TRACE_INCLUDES' ), json_encode( get_included_files(), JSON_THROW_ON_ERROR ) );
}
