<?php
/**
 * Focused behavior tests for the ability-backed revenue CLI adapters.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class RevenueCommandTestError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();
		public static $printed = array();

		public static function error( $message ) {
			throw new RevenueCommandTestError( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function warning( $message ) {
			self::$messages[] = 'Warning: ' . $message;
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function print_value( $value, $args ) {
			self::$printed[] = compact( 'value', 'args' );
		}
	}

	class RevenueCommandTestAbility {
		public $inputs = array();
		public $result;

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result;
		}
	}

	$revenue_command_test_abilities = array();

	function wp_get_ability( $name ) {
		global $revenue_command_test_abilities;
		return $revenue_command_test_abilities[ $name ] ?? null;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function revenue_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function revenue_command_test_assert_true( $actual, $message ) {
		if ( ! $actual ) {
			throw new \RuntimeException( $message );
		}
	}
}

namespace WP_CLI\Utils {
	$revenue_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $revenue_command_test_formats;
		$revenue_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/RevenueCommand.php';

	use ExtraChill\CLI\Commands\Analytics\RevenueCommand;

	global $revenue_command_test_abilities, $revenue_command_test_formats;

	$report = new RevenueCommandTestAbility(
		array(
			'success'  => true,
			'results'  => array(
				array( 'slug' => '/one/', 'views' => 100, 'revenue' => 4.5, 'rpm' => 45, 'cpm' => 8, 'viewability' => 0.7, 'fillRate' => 0.8, 'impressionsPerPageview' => 2.1, 'period' => '2026-05' ),
			),
			'periods'  => array(
				array(
					'period'     => '2026-05',
					'start_date' => '2026-05-01',
					'end_date'   => '2026-05-31',
					'provenance' => array(
						'site'   => array( 'relay_id' => 'SW50ZXJuYWxTaXRlOjQy' ),
						'period' => array( 'canonical' => array( 'start' => '2026/05/02', 'end' => '2026/05/30' ) ),
					),
				),
			),
		)
	);
	$ingest = new RevenueCommandTestAbility(
		array(
			'success'  => true,
			'rows'     => 1,
			'resolved' => 1,
			'identity' => array( 'import_batch' => 'rev-mediavine-42-2026-05' ),
		)
	);
	$revenue_command_test_abilities = array(
		'datamachine/mediavine-reports' => $report,
		'extrachill/ingest-revenue'     => $ingest,
	);

	$command = new RevenueCommand();
	$command->fetch( array(), array( 'start' => '2026-05-01', 'end' => '2026-05-31', 'period' => '2026-05', 'site-id' => '42', 'dry-run' => true ) );
	revenue_command_test_assert_same(
		array( array( 'action' => 'backfill', 'periods' => array( array( 'period' => '2026-05', 'start_date' => '2026-05-01', 'end_date' => '2026-05-31' ) ), 'site_id' => '42' ) ),
		$report->inputs,
		'Fetch must request the documented Mediavine backfill contract.'
	);
	revenue_command_test_assert_same(
		array( array(
			'rows' => array( array( 'slug' => '/one/', 'views' => 100, 'revenue' => 4.5, 'rpm' => 45, 'cpm' => 8, 'viewability' => 0.7, 'fill_rate' => 0.8, 'impressions_per_pageview' => 2.1 ) ),
			'hostname' => 'extrachill.com', 'source' => 'mediavine', 'source_site' => 'SW50ZXJuYWxTaXRlOjQy', 'period' => '2026-05', 'period_start' => '2026-05-02', 'period_end' => '2026-05-30', 'dry_run' => true,
		) ),
		$ingest->inputs,
		'Fetch must hand source rows and report provenance to the ingestion ability for deterministic replacement.'
	);
	$command->fetch( array(), array( 'start' => '2026-05-01', 'end' => '2026-05-31', 'period' => '2026-05', 'site-id' => '42', 'dry-run' => true ) );
	revenue_command_test_assert_same( $ingest->inputs[0], $ingest->inputs[1], 'Repeated fetches must invoke ingestion with the identical deterministic snapshot inputs.' );
	$source = file_get_contents( dirname( __DIR__ ) . '/inc/Commands/Analytics/RevenueCommand.php' );
	revenue_command_test_assert_true( false === strpos( $source, 'extrachill_analytics_revenue_import_csv' ), 'Revenue command must not call the legacy direct importer.' );
	revenue_command_test_assert_true( false === strpos( $source, 'wp_tempnam' ), 'Revenue command must not serialize report rows to temporary CSV files.' );

	$pages = new RevenueCommandTestAbility(
		array(
			'success' => true, 'window' => '2026-05',
			'pages'   => array( array( 'cohort' => 'resolved', 'post_id' => 9, 'title' => 'A post', 'url' => 'https://example.com/a', 'categories' => array( 'news' ), 'format' => 'news', 'route_family' => '', 'views' => 100, 'revenue' => 4.5, 'derived_rpm' => 45, 'source_rpm' => 44, 'cpm' => 8, 'viewability' => 0.7, 'fill_rate' => 0.8, 'impressions_per_pageview' => 2.1, 'zero_views' => false, 'benchmark_opportunity' => true ) ),
		)
	);
	$revenue_command_test_abilities['extrachill/get-content-revenue-pages'] = $pages;
	$command->pages( array(), array( 'period' => '2026-05', 'cohort' => 'all', 'min-views' => 100, 'sort-by' => 'cpm', 'order' => 'asc', 'limit' => 5 ) );
	revenue_command_test_assert_same(
		array( array( 'period' => '2026-05', 'period_start' => '', 'period_end' => '', 'import_batch' => '', 'blog_id' => 0, 'hostname' => '', 'cohort' => 'all', 'min_views' => 100, 'sort_by' => 'cpm', 'order' => 'asc', 'limit' => 5 ) ),
		$pages->inputs,
		'Pages must forward the documented ability inputs without recomputation.'
	);
	revenue_command_test_assert_same( 'table', $revenue_command_test_formats[0]['format'], 'Pages defaults to table output.' );
	revenue_command_test_assert_same( 45, $revenue_command_test_formats[0]['items'][0]['derived_rpm'], 'Pages table preserves ability-derived metrics.' );
	$command->pages( array(), array( 'format' => 'json' ) );
	revenue_command_test_assert_same( 'json', \WP_CLI::$printed[0]['args']['format'], 'Pages preserves JSON output.' );

	$diagnostics = new RevenueCommandTestAbility(
		array(
			'success' => true, 'window' => 'all history', 'overall_status' => 'pass',
			'checks'  => array( array( 'check' => 'freshness', 'status' => 'pass', 'evidence' => array( 'current' ), 'totals' => array( 'rows' => 1 ) ) ),
		)
	);
	$revenue_command_test_abilities['extrachill/get-content-revenue-diagnostics'] = $diagnostics;
	$command->diagnose( array(), array() );
	$diagnostics->result['overall_status'] = 'warning';
	$diagnostics->result['checks'][0]['status'] = 'warning';
	$command->diagnose( array(), array() );
	revenue_command_test_assert_same( 3, count( $revenue_command_test_formats ), 'Diagnostics pass and warning states must both remain successful.' );
	$diagnostics->result['overall_status'] = 'fail';
	try {
		$command->diagnose( array(), array( 'format' => 'json' ) );
		throw new \RuntimeException( 'Failing diagnostics did not exit nonzero.' );
	} catch ( RevenueCommandTestError $error ) {
		revenue_command_test_assert_same( 'Revenue diagnostics reported failures.', $error->getMessage(), 'Only the ability fail status must produce the diagnostics CLI error.' );
	}

	unset( $revenue_command_test_abilities['extrachill/get-content-revenue-pages'] );
	try {
		$command->pages( array(), array() );
		throw new \RuntimeException( 'Missing pages ability did not terminate the command.' );
	} catch ( RevenueCommandTestError $error ) {
		revenue_command_test_assert_same( 'extrachill/get-content-revenue-pages ability not found. Is Extra Chill Analytics active and up to date?', $error->getMessage(), 'Missing ability errors must name the canonical owner.' );
	}

	fwrite( STDOUT, "RevenueCommand tests passed.\n" );
}
