<?php
/**
 * Analytics Revenue CLI Command.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Thin presentation adapters for the analytics revenue abilities.
 */
class RevenueCommand {
	/**
	 * Fetch Mediavine period reports and replace their deterministic snapshots.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<date>]
	 * : Window start (Y-m-d). Used with --end and --period for one period.
	 * [--end=<date>]
	 * : Window end (Y-m-d). Used with --start and --period for one period.
	 * [--period=<period>]
	 * : Period label, such as 2026-05.
	 * [--periods=<json>]
	 * : JSON array of {period, start_date, end_date} entries for a backfill.
	 * [--site-id=<id>]
	 * : Mediavine site ID.
	 * [--hostname=<host>]
	 * : WordPress hostname used by the ingestion ability for route resolution.
	 * ---
	 * default: extrachill.com
	 * ---
	 * [--dry-run]
	 * : Fetch and plan deterministic replacement without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill analytics revenue fetch --start=2026-05-01 --end=2026-05-31 --period=2026-05
	 *     wp extrachill analytics revenue fetch --periods='[{"period":"2026-04","start_date":"2026-04-01","end_date":"2026-04-30"}]'
	 *
	 * @subcommand fetch
	 * @when after_wp_load
	 */
	public function fetch( $args, $assoc_args ) {
		$reports = $this->ability( 'datamachine/mediavine-reports', 'Data Machine Business' );
		$ingest  = $this->ability( 'extrachill/ingest-revenue', 'Extra Chill Analytics' );
		$periods = $this->fetch_periods( $assoc_args );
		$dry_run = isset( $assoc_args['dry-run'] );

		$input = array( 'action' => 'backfill', 'periods' => $periods );
		if ( isset( $assoc_args['site-id'] ) && '' !== $assoc_args['site-id'] ) {
			$input['site_id'] = (string) $assoc_args['site-id'];
		}

		WP_CLI::log( sprintf( 'Fetching %d period(s) from Mediavine...', count( $periods ) ) );
		$report = $reports->execute( $input );
		$this->assert_success( $report, 'Mediavine fetch failed.' );

		$summaries = array();
		foreach ( (array) ( $report['periods'] ?? array() ) as $summary ) {
			if ( ! empty( $summary['error'] ) ) {
				WP_CLI::warning( sprintf( 'Period %s failed: %s', $summary['period'] ?? '(window)', $summary['error'] ) );
				continue;
			}
			$summaries[ (string) ( $summary['period'] ?? '' ) ] = $summary;
		}

		$rows_by_period = array();
		foreach ( (array) ( $report['results'] ?? array() ) as $row ) {
			$rows_by_period[ (string) ( $row['period'] ?? '' ) ][] = $row;
		}
		if ( empty( $rows_by_period ) ) {
			WP_CLI::error( 'Mediavine returned no rows for the requested period(s).' );
		}

		foreach ( $rows_by_period as $period => $rows ) {
			$summary = $summaries[ $period ] ?? array();
			$result  = $ingest->execute(
				array(
					'rows'         => $this->ingest_rows( $rows ),
					'hostname'     => $assoc_args['hostname'] ?? 'extrachill.com',
					'source'       => 'mediavine',
					'source_site'  => $this->source_site( $summary, $report ),
					'period'       => $period,
					'period_start' => $this->report_date( $summary, 'start_date', 'start' ),
					'period_end'   => $this->report_date( $summary, 'end_date', 'end' ),
					'dry_run'      => $dry_run,
				)
			);
			$this->assert_success( $result, sprintf( 'Revenue ingestion failed for period %s.', $period ) );
			$identity = (array) ( $result['identity'] ?? array() );
			WP_CLI::log(
				sprintf(
					'  %s - snapshot %s - rows %d - resolved %d - unresolved %d - inserted %d - replaced %d%s',
					$period ?: 'all-time',
					$identity['import_batch'] ?? '(unknown)',
					(int) ( $result['rows'] ?? 0 ),
					(int) ( $result['resolved'] ?? 0 ),
					(int) ( $result['unresolved'] ?? 0 ),
					(int) ( $result['inserted'] ?? 0 ),
					(int) ( $result['replaced'] ?? 0 ),
					$dry_run ? ' (DRY RUN)' : ''
				)
			);
		}

		if ( ! $dry_run ) {
			WP_CLI::success( 'Mediavine fetch complete. Re-fetching a period replaces its deterministic snapshot.' );
		}
	}

	/**
	 * List page-level Mediavine delivery metrics.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * [--period-start=<date>]
	 * [--period-end=<date>]
	 * [--batch=<label>]
	 * [--blog-id=<id>]
	 * [--hostname=<host>]
	 * [--cohort=<cohort>]
	 * : resolved, unresolved, or all.
	 * ---
	 * default: resolved
	 * ---
	 * [--min-views=<n>]
	 * [--sort-by=<metric>]
	 * : views, revenue, derived_rpm, source_rpm, cpm, viewability, fill_rate, impressions_per_pageview, dollars_per_page, or benchmark_opportunity.
	 * [--order=<direction>]
	 * : desc or asc.
	 * [--limit=<n>]
	 * [--format=<format>]
	 * : table, json, or csv.
	 *
	 * @subcommand pages
	 * @when after_wp_load
	 */
	public function pages( $args, $assoc_args ) {
		$ability = $this->ability( 'extrachill/get-content-revenue-pages', 'Extra Chill Analytics' );
		$result  = $ability->execute(
			array(
				'period'       => $assoc_args['period'] ?? '',
				'period_start' => $assoc_args['period-start'] ?? '',
				'period_end'   => $assoc_args['period-end'] ?? '',
				'import_batch' => $assoc_args['batch'] ?? '',
				'blog_id'      => (int) ( $assoc_args['blog-id'] ?? 0 ),
				'hostname'     => $assoc_args['hostname'] ?? '',
				'cohort'       => $assoc_args['cohort'] ?? 'resolved',
				'min_views'    => (int) ( $assoc_args['min-views'] ?? 0 ),
				'sort_by'      => $assoc_args['sort-by'] ?? 'derived_rpm',
				'order'        => $assoc_args['order'] ?? 'desc',
				'limit'        => (int) ( $assoc_args['limit'] ?? 50 ),
			)
		);
		$this->assert_success( $result, 'Revenue pages query failed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'table' !== $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		WP_CLI::log( sprintf( 'Content Revenue Pages - %s', $result['window'] ?? '' ) );
		Utils\format_items( 'table', (array) ( $result['pages'] ?? array() ), $this->page_fields() );
	}

	/**
	 * Roll up ability-provided revenue metrics by format or category.
	 *
	 * ## OPTIONS
	 *
	 * [--group-by=<axis>]
	 * : format, category, or both.
	 * [--period=<period>]
	 * [--period-start=<date>]
	 * [--period-end=<date>]
	 * [--batch=<label>]
	 * [--hostname=<host>]
	 * [--limit=<n>]
	 * [--format=<format>]
	 * : table, json, or csv.
	 *
	 * @subcommand rollup
	 * @when after_wp_load
	 */
	public function rollup( $args, $assoc_args ) {
		$ability = $this->ability( 'extrachill/get-content-revenue', 'Extra Chill Analytics' );
		$result  = $ability->execute(
			array(
				'group_by'     => $assoc_args['group-by'] ?? 'format',
				'period'       => $assoc_args['period'] ?? '',
				'period_start' => $assoc_args['period-start'] ?? '',
				'period_end'   => $assoc_args['period-end'] ?? '',
				'import_batch' => $assoc_args['batch'] ?? '',
				'hostname'     => $assoc_args['hostname'] ?? 'extrachill.com',
			)
		);
		$this->assert_success( $result, 'Revenue rollup failed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'table' !== $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		$limit = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		foreach ( (array) ( $result['rollups'] ?? array() ) as $axis => $buckets ) {
			WP_CLI::log( 'by_format' === $axis ? 'By content format:' : 'By category:' );
			Utils\format_items( 'table', array_slice( $buckets, 0, $limit ), array( 'bucket', 'pages', 'views', 'revenue', 'dollars_per_page', 'rpm' ) );
		}
	}

	/**
	 * Show the ability-provided revenue time series.
	 *
	 * ## OPTIONS
	 *
	 * [--include-alltime]
	 * [--format=<format>]
	 * : table, json, or csv.
	 *
	 * @subcommand arc
	 * @when after_wp_load
	 */
	public function arc( $args, $assoc_args ) {
		$ability = $this->ability( 'extrachill/get-content-revenue', 'Extra Chill Analytics' );
		$result  = $ability->execute(
			array(
				'group_by'        => 'timeseries',
				'include_alltime' => isset( $assoc_args['include-alltime'] ),
			)
		);
		$this->assert_success( $result, 'Revenue arc failed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'table' !== $format ) {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
			return;
		}

		Utils\format_items( 'table', (array) ( $result['series'] ?? array() ), array( 'period', 'pages', 'views', 'revenue', 'mom_delta', 'mom_pct', 'rpm' ) );
	}

	/**
	 * Show structured integrity diagnostics for the revenue store.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * [--period-start=<date>]
	 * [--period-end=<date>]
	 * [--batch=<label>]
	 * [--blog-id=<id>]
	 * [--hostname=<host>]
	 * [--format=<format>]
	 * : table, json, or csv.
	 *
	 * @subcommand diagnose
	 * @when after_wp_load
	 */
	public function diagnose( $args, $assoc_args ) {
		$ability = $this->ability( 'extrachill/get-content-revenue-diagnostics', 'Extra Chill Analytics' );
		$result  = $ability->execute(
			array(
				'period'       => $assoc_args['period'] ?? '',
				'period_start' => $assoc_args['period-start'] ?? '',
				'period_end'   => $assoc_args['period-end'] ?? '',
				'import_batch' => $assoc_args['batch'] ?? '',
				'blog_id'      => (int) ( $assoc_args['blog-id'] ?? 0 ),
				'hostname'     => $assoc_args['hostname'] ?? '',
			)
		);
		$this->assert_success( $result, 'Revenue diagnostics failed.' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Content Revenue Diagnostics - %s - %s', $result['window'] ?? '', strtoupper( $result['overall_status'] ?? 'fail' ) ) );
			Utils\format_items( 'table', (array) ( $result['checks'] ?? array() ), array( 'check', 'status', 'evidence', 'totals' ) );
		} else {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
		}

		if ( 'fail' === ( $result['overall_status'] ?? 'fail' ) ) {
			WP_CLI::error( 'Revenue diagnostics reported failures.' );
		}
	}

	private function ability( $name, $owner ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( '%s ability not found. Is %s active and up to date?', $name, $owner ) );
		}
		return $ability;
	}

	private function fetch_periods( $assoc_args ) {
		if ( isset( $assoc_args['periods'] ) ) {
			$periods = json_decode( (string) $assoc_args['periods'], true );
			if ( ! is_array( $periods ) || empty( $periods ) ) {
				WP_CLI::error( '--periods must be a non-empty JSON array of {period, start_date, end_date} objects.' );
			}
			return $periods;
		}

		$period = array( 'period' => $assoc_args['period'] ?? '', 'start_date' => $assoc_args['start'] ?? '', 'end_date' => $assoc_args['end'] ?? '' );
		if ( '' === $period['period'] || '' === $period['start_date'] || '' === $period['end_date'] ) {
			WP_CLI::error( '--start, --end, and --period are required unless --periods is provided.' );
		}
		return array( $period );
	}

	private function ingest_rows( $rows ) {
		return array_map(
			static function ( $row ) {
				return array(
					'slug'                     => $row['slug'] ?? '',
					'views'                    => $row['views'] ?? 0,
					'revenue'                  => $row['revenue'] ?? 0,
					'rpm'                      => $row['rpm'] ?? 0,
					'cpm'                      => $row['cpm'] ?? 0,
					'viewability'              => $row['viewability'] ?? 0,
					'fill_rate'                => $row['fillRate'] ?? 0,
					'impressions_per_pageview' => $row['impressionsPerPageview'] ?? 0,
				);
			},
			$rows
		);
	}

	private function source_site( $summary, $report ) {
		$provenance = (array) ( $summary['provenance'] ?? $report['provenance'] ?? array() );
		$site       = (array) ( $provenance['site'] ?? array() );
		return (string) ( $site['relay_id'] ?? $site['requested_id'] ?? '' );
	}

	private function report_date( $summary, $requested_key, $canonical_key ) {
		$canonical = $summary['provenance']['period']['canonical'][ $canonical_key ] ?? '';
		return '' !== $canonical ? str_replace( '/', '-', (string) $canonical ) : (string) ( $summary[ $requested_key ] ?? '' );
	}

	private function assert_success( $result, $fallback ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? $fallback );
		}
	}

	private function page_fields() {
		return array( 'cohort', 'post_id', 'title', 'url', 'categories', 'format', 'route_family', 'views', 'revenue', 'derived_rpm', 'source_rpm', 'cpm', 'viewability', 'fill_rate', 'impressions_per_pageview', 'zero_views', 'benchmark_opportunity' );
	}
}
