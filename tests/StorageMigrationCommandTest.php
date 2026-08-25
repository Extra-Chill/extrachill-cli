<?php
/** Focused tests for the Link Pages migration CLI adapter. */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		$GLOBALS['migration_cli_formats'][] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class MigrationCliError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static function error( $message ) {
			throw new MigrationCliError( $message ); }
		public static function line( $message ) {
			self::$messages[] = $message; }
		public static function log( $message ) {
			self::$messages[] = $message; }
	}

	class MigrationCliAbility {
		public $inputs = array();
		public $result = array(
			'mode'        => 'plan',
			'fingerprint' => 'abc',
			'ready'       => true,
		);
		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result; }
	}

	$GLOBALS['migration_cli_ability'] = new MigrationCliAbility();
	function wp_get_ability( $name ) {
		return 'extrachill/migrate-link-page-storage' === $name ? $GLOBALS['migration_cli_ability'] : null; }
	function is_wp_error( $value ) {
		return false; }
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags ); }
	function ec_get_blog_id( $key ) {
		return 'artist' === $key ? 4 : 0; }
	function migration_cli_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message ); } }

	require_once dirname( __DIR__ ) . '/inc/Commands/LinkPages/StorageMigrationCommand.php';

	$command = new \ExtraChill\CLI\Commands\LinkPages\StorageMigrationCommand();
	$command(
		array(),
		array(
			'source'      => 'artist',
			'destination' => '13',
		)
	);
	migration_cli_assert_same(
		array(
			'mode'                => 'plan',
			'source_blog_id'      => 4,
			'destination_blog_id' => 13,
		),
		$GLOBALS['migration_cli_ability']->inputs[0],
		'Plan must be the dry-run default and map only ability inputs.'
	);
	migration_cli_assert_same( 'Dry run only. No options, database rows, files, routing, or source data were changed.', WP_CLI::$messages[0], 'Plan output must state that it is read-only.' );

	try {
		$command(
			array(),
			array(
				'source'      => '4',
				'destination' => '13',
				'apply'       => true,
			)
		);
		throw new \RuntimeException( 'Missing apply expectation was accepted.' );
	} catch ( MigrationCliError $error ) {
		migration_cli_assert_same( '--apply requires --expect=<fingerprint> from a prior plan.', $error->getMessage(), 'Apply must require the plan fingerprint.' );
	}

	$GLOBALS['migration_cli_ability']->result = array(
		'mode'   => 'validate',
		'status' => 'valid',
	);
	$command(
		array(),
		array(
			'validate' => 'journal-1',
			'format'   => 'json',
		)
	);
	migration_cli_assert_same(
		array(
			'mode'       => 'validate',
			'journal_id' => 'journal-1',
		),
		$GLOBALS['migration_cli_ability']->inputs[1],
		'Validate must map only its journal ID.'
	);
	migration_cli_assert_same(
		json_encode(
			array(
				'mode'   => 'validate',
				'status' => 'valid',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		),
		WP_CLI::$messages[1],
		'JSON must preserve the complete ability result.'
	);

	fwrite( STDOUT, "StorageMigrationCommand tests passed.\n" );
}
