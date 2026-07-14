<?php
/**
 * Exercises revenue argument validation through a real WP-CLI dispatcher.
 */

$wp_path = getenv( 'WP_CLI_RUNTIME_PATH' );
if ( false === $wp_path || '' === $wp_path ) {
	fwrite( STDOUT, "RevenueCommand runtime test skipped: WP_CLI_RUNTIME_PATH is not set.\n" );
	exit( 0 );
}

$bootstrap = __DIR__ . '/RevenueCommandRuntimeBootstrap.php';
$commands  = array(
	'fetch --hostname=extrachill.com --dry-run',
	'pages --hostname=extrachill.com --min-views=1000 --limit=25',
	'rollup --hostname=extrachill.com --limit=100',
	'arc --include-alltime --format=json',
	'diagnose --hostname=extrachill.com --format=json',
);

foreach ( $commands as $command ) {
	$runtime_command = sprintf(
		'wp --path=%s --allow-root --skip-plugins --require=%s extrachill analytics revenue %s 2>&1',
		escapeshellarg( $wp_path ),
		escapeshellarg( $bootstrap ),
		$command
	);
	exec( $runtime_command, $output, $exit_code );
	$output = implode( "\n", $output );

	if ( false !== strpos( $output, 'Parameter errors:' ) || false !== strpos( $output, 'unknown --' ) ) {
		throw new RuntimeException( sprintf( 'WP-CLI rejected documented revenue options for "%s": %s', $command, $output ) );
	}
	if ( false === strpos( $output, 'ability not found' ) ) {
		throw new RuntimeException( sprintf( 'Revenue command "%s" did not reach its Ability boundary: %s', $command, $output ) );
	}
	if ( 0 === $exit_code ) {
		throw new RuntimeException( sprintf( 'Revenue command "%s" unexpectedly succeeded without its Ability.', $command ) );
	}
}

fwrite( STDOUT, "RevenueCommand runtime tests passed.\n" );
