<?php
/**
 * Ability runner for WP-CLI commands.
 *
 * Lets command implementations delegate to a registered ability with
 * a one-line call. Handles input parsing (CLI flags -> ability input
 * schema), permission checks, error formatting, and output rendering
 * (ability output -> WP_CLI::print_value or similar).
 *
 * Usage inside a wp-cli command method:
 *
 *   public function leaderboard( $args, $assoc_args ) {
 *     extrachill_cli_run_ability( 'extrachill/users-leaderboard', $assoc_args );
 *   }
 *
 * The ability owns the validation, execution, and output shape.
 * The command method is just a CLI entry point that maps to it.
 *
 * @package ExtraChill\Cli
 * @since   0.x.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Run an ability and render its output via WP-CLI.
 *
 * - Validates that the ability is registered (errors if not).
 * - Checks permissions (errors with 403 message if denied).
 * - Executes the ability with the provided input.
 * - Prints the result as JSON by default, or via --format=table/yaml/csv
 *   when the ability output shape supports it.
 *
 * @param string $ability_name  Fully-qualified ability name.
 * @param array  $input         CLI $assoc_args, treated as the ability input.
 * @param array  $options       Optional. ['format' => 'json'|'table'|'yaml'|'csv', ...].
 *                              Format defaults to whatever $input['format'] specifies, then 'json'.
 * @return void Outputs via WP_CLI; never returns.
 */
function extrachill_cli_run_ability( string $ability_name, array $input = array(), array $options = array() ): void {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}

	$ability = wp_get_ability( $ability_name );
	if ( ! $ability ) {
		WP_CLI::error( sprintf(
			'Ability %s is not registered. The feature plugin that owns this ability may be inactive.',
			$ability_name
		) );
		return;
	}

	// Strip CLI-only flags before passing to ability.
	$cli_only_keys = array( 'format' );
	$ability_input = array_diff_key( $input, array_flip( $cli_only_keys ) );

	// Permission check.
	$has_permission = $ability->has_permission( $ability_input );
	if ( is_wp_error( $has_permission ) ) {
		WP_CLI::error( $has_permission->get_error_message() );
		return;
	}
	if ( ! $has_permission ) {
		WP_CLI::error( sprintf( 'Permission denied for ability %s.', $ability_name ) );
		return;
	}

	// Execute.
	$result = $ability->execute( $ability_input );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( sprintf( '%s: %s', $result->get_error_code(), $result->get_error_message() ) );
		return;
	}

	// Render output.
	$format = $options['format'] ?? $input['format'] ?? 'json';
	extrachill_cli_render_ability_output( $result, $format );
}

/**
 * Render an ability's result via WP-CLI in the requested format.
 *
 * @param mixed  $result  The ability's return value.
 * @param string $format  json|table|yaml|csv.
 * @return void
 */
function extrachill_cli_render_ability_output( $result, string $format ): void {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}

	if ( 'json' === $format ) {
		WP_CLI::print_value( $result, array( 'format' => 'json' ) );
		return;
	}

	if ( in_array( $format, array( 'table', 'yaml', 'csv' ), true ) ) {
		// For table/yaml/csv, the result must be an array of records.
		// If it's a flat object, wrap it in a single-row array.
		if ( is_array( $result ) && ! empty( $result ) && ! isset( $result[0] ) ) {
			$result = array( $result );
		}

		if ( is_array( $result ) && isset( $result[0] ) && is_array( $result[0] ) ) {
			\WP_CLI\Utils\format_items( $format, $result, array_keys( $result[0] ) );
			return;
		}

		WP_CLI::print_value( $result, array( 'format' => $format ) );
		return;
	}

	WP_CLI::print_value( $result, array( 'format' => 'json' ) );
}
