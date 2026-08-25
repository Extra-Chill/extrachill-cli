<?php
/**
 * Link Pages storage migration operator adapter.
 *
 * @package ExtraChill\CLI\Commands\LinkPages
 */

namespace ExtraChill\CLI\Commands\LinkPages;

use WP_CLI;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/** Thin command adapter over the Link Pages migration ability. */
class StorageMigrationCommand {
	/**
	 * Plan, apply, validate, or roll back the feature-owned storage migration.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<site>]
	 * : Source blog ID or registered logical site key.
	 *
	 * [--destination=<site>]
	 * : Destination blog ID or registered logical site key.
	 *
	 * [--apply]
	 * : Apply a prior plan. Requires --expect.
	 *
	 * [--expect=<fingerprint>]
	 * : Exact source fingerprint returned by a prior plan.
	 *
	 * [--validate=<journal-id>]
	 * : Validate an applied journal.
	 *
	 * [--rollback=<journal-id>]
	 * : Roll back only mutations owned by a journal.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --source=4 --destination=13
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --source=4 --destination=13 --apply --expect=<fingerprint>
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --validate=<journal-id> --format=json
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --rollback=<journal-id>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$modes = (int) ! empty( $assoc_args['apply'] ) + (int) isset( $assoc_args['validate'] ) + (int) isset( $assoc_args['rollback'] );
		if ( $modes > 1 ) {
			WP_CLI::error( '--apply, --validate, and --rollback are mutually exclusive.' ); }
		if ( isset( $assoc_args['expect'] ) && empty( $assoc_args['apply'] ) ) {
			WP_CLI::error( '--expect may only be used with --apply.' ); }
		if ( ! empty( $assoc_args['apply'] ) && empty( $assoc_args['expect'] ) ) {
			WP_CLI::error( '--apply requires --expect=<fingerprint> from a prior plan.' ); }
		if ( ( isset( $assoc_args['validate'] ) || isset( $assoc_args['rollback'] ) ) && ( isset( $assoc_args['source'] ) || isset( $assoc_args['destination'] ) || isset( $assoc_args['expect'] ) ) ) {
			WP_CLI::error( 'Journal modes cannot be combined with source, destination, or expectation arguments.' ); }
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( '--format must be table or json.' ); }
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'Abilities API not available (requires WordPress 6.9+).' ); }
		$ability = wp_get_ability( 'extrachill/migrate-link-page-storage' );
		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/migrate-link-page-storage ability not available. Ensure Extra Chill Link Pages is network-active and up to date.' ); }
		if ( isset( $assoc_args['validate'] ) ) {
			$input = array(
				'mode'       => 'validate',
				'journal_id' => (string) $assoc_args['validate'],
			); } elseif ( isset( $assoc_args['rollback'] ) ) {
			$input = array(
				'mode'       => 'rollback',
				'journal_id' => (string) $assoc_args['rollback'],
			); } else {
				if ( ! isset( $assoc_args['source'], $assoc_args['destination'] ) ) {
					WP_CLI::error( 'Plan and apply require --source and --destination.' ); }
				$source      = $this->resolve_blog_id( $assoc_args['source'] );
				$destination = $this->resolve_blog_id( $assoc_args['destination'] );
				if ( ! $source || ! $destination ) {
					WP_CLI::error( 'Source and destination must resolve to existing positive blog IDs.' ); }
				if ( get_current_blog_id() !== $source ) {
					WP_CLI::error( sprintf( 'Run this command on source blog %d so all required owner participants are active. Use --url=<source-url> and --user=<network-admin>.', $source ) ); }
				$input = array(
					'mode'                => ! empty( $assoc_args['apply'] ) ? 'apply' : 'plan',
					'source_blog_id'      => $source,
					'destination_blog_id' => $destination,
					'required_participants' => array( 'analytics' ),
				);
				if ( ! empty( $assoc_args['apply'] ) ) {
					$input['expected_fingerprint'] = (string) $assoc_args['expect']; }
			}
			$result = $ability->execute( $input );
			if ( is_wp_error( $result ) ) {
				$data    = $result->get_error_data();
				$code    = $result->get_error_code();
				$message = '[' . $code . '] ' . $result->get_error_message();
				if ( false !== stripos( $code, 'permission' ) || false !== stripos( $code, 'forbidden' ) ) {
					$message .= ' Execute with global --user=<network-admin>; authorization is enforced by the ability.'; }
				if ( is_array( $data ) && ! empty( $data['journal_id'] ) && in_array( $data['journal_status'] ?? '', array( 'applying', 'applied', 'failed', 'rolling_back' ), true ) ) {
					$message .= ' Journal: ' . $data['journal_id'] . '. Roll back with: wp extrachill link-pages migrate-storage --rollback=' . $data['journal_id']; }
				if ( 'json' === $format ) {
					$message = wp_json_encode(
						array(
							'success' => false,
							'code'    => $code,
							'message' => $result->get_error_message(),
							'data'    => $data,
						),
						JSON_UNESCAPED_SLASHES
					); }
				WP_CLI::error( $message );
			}
			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return; }
			$rows = array();
			foreach ( $result as $key => $value ) {
				$rows[] = array(
					'field' => $key,
					'value' => is_scalar( $value ) || null === $value ? ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) : wp_json_encode( $value, JSON_UNESCAPED_SLASHES ),
				);
			}
			format_items( 'table', $rows, array( 'field', 'value' ) );
			if ( 'plan' === ( $result['mode'] ?? '' ) ) {
				WP_CLI::log( 'Dry run only. No options, database rows, files, routing, or source data were changed.' ); }
			if ( ! empty( $result['rollback'] ) ) {
				WP_CLI::log( 'Rollback: ' . $result['rollback'] ); }
	}

	/**
	 * Resolve numeric IDs or existing logical site keys.
	 *
	 * @param string $value Site ID or key.
	 */
	private function resolve_blog_id( $value ) {
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) {
			return (int) $value; }
		return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( $value ) : 0;
	}
}
