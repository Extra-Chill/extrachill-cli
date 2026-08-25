<?php
/** Coordinated disposable MariaDB-backed multisite parity proof. */

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Multisite was not enabled.' ); }

function parity_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
function parity_site( $domain ) {
	$id = wpmu_create_blog( $domain, '/', $domain, 1, array( 'public' => 0 ), 1 );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	} return (int) $id; }
while ( get_current_blog_id() < 1 ) {
	restore_current_blog(); }
$site2               = parity_site( 'fixture-two.test' );
$site3               = parity_site( 'fixture-three.test' );
$source_blog_id      = parity_site( 'artist.fixture.test' );
$destination_blog_id = parity_site( 'links.fixture.test' );
parity_assert( 4 === $source_blog_id && 5 === $destination_blog_id, 'Expected deterministic source/destination blog IDs.' );

require_once '/tmp/pr/network/inc/core/blog-ids.php';
require_once '/tmp/pr/link-pages/extrachill-link-pages.php';
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', '/tmp/pr/artist/' );
require_once '/tmp/pr/artist/inc/link-pages/artist-owner-compatibility.php';
require_once '/tmp/pr/artist/inc/link-pages/artist-owner-operations.php';
require_once '/tmp/pr/artist/inc/link-pages/storage-migration.php';
if ( ! function_exists( 'ec_get_link_page_data' ) ) {
	function ec_get_link_page_data( $owner_id, $link_page_id ) {
		return array(
			'link_page_id' => (int) $link_page_id,
			'owner_id'     => (int) $owner_id,
		); } }
if ( ! function_exists( 'extrachill_artist_platform_ability_artist_permission' ) ) {
	function extrachill_artist_platform_ability_artist_permission() {
		return true; } }
ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
ec_artist_register_link_page_migration_adapter();

define( 'EXTRACHILL_ANALYTICS_PLUGIN_DIR', '/tmp/pr/analytics/' );
require_once '/tmp/pr/analytics/inc/database/link-page-analytics-db.php';
require_once '/tmp/pr/analytics/inc/database/events-db.php';
require_once '/tmp/pr/analytics/inc/core/link-page-storage-migration.php';
extrachill_analytics_register_link_page_migration_participant();

add_filter(
	'ec_link_page_storage_blog_id',
	static function () use ( $source_blog_id ) {
		return $source_blog_id;
	}
);
switch_to_blog( $source_blog_id );
ec_register_link_page_post_type();

$owner_id = wp_insert_post(
	array(
		'import_id'   => 100,
		'post_type'   => 'artist_profile',
		'post_status' => 'publish',
		'post_title'  => 'Proof Owner',
		'post_name'   => 'proof-owner',
	),
	true
);
parity_assert( 100 === $owner_id, 'Owner import ID mismatch.' );
$modified = '2025-02-03 04:05:06';
foreach ( array(
	200 => 'proof-one',
	201 => 'proof-two',
) as $id => $slug ) {
	$inserted = wp_insert_post(
		array(
			'import_id'         => $id,
			'post_type'         => EC_LINK_PAGE_POST_TYPE,
			'post_status'       => 'publish',
			'post_title'        => $slug,
			'post_name'         => $slug,
			'post_modified'     => $modified,
			'post_modified_gmt' => $modified,
		),
		true
	);
	parity_assert( $id === $inserted, 'Link Page import ID mismatch.' );
	add_post_meta( $id, EC_LINK_PAGE_OWNER_META_KEY, 'post:4:artist_profile:100' );
	add_post_meta( $id, '_associated_artist_profile_id', 100 );
}
add_post_meta( 100, '_extrch_link_page_id', 200 );

$uploads = wp_upload_dir();
wp_mkdir_p( $uploads['path'] );
function parity_attachment( $id, $parent, $name, $uploads ) {
	$relative = trailingslashit( $uploads['subdir'] ) . $name;
	$absolute = trailingslashit( $uploads['basedir'] ) . $relative;
	file_put_contents( $absolute, 'main-' . $id );
	$attachment = wp_insert_attachment(
		array(
			'import_id'      => $id,
			'post_title'     => $name,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		),
		$absolute,
		$parent,
		true
	);
	parity_assert( $id === $attachment, 'Attachment import ID mismatch.' );
	update_post_meta( $id, '_wp_attached_file', $relative );
	$dir        = dirname( $absolute );
	$companions = array( 'size-' . $name, 'original-' . $name, 'thumb-' . $name, 'source-' . $name, 'video-' . $name, 'poster-' . $name, 'backup-' . $name );
	foreach ( $companions as $file ) {
		file_put_contents( trailingslashit( $dir ) . $file, $file . '-' . $id ); }
	update_post_meta(
		$id,
		'_wp_attachment_metadata',
		array(
			'file'                  => $relative,
			'sizes'                 => array( 'small' => array( 'file' => $companions[0] ) ),
			'original_image'        => $companions[1],
			'thumb'                 => $companions[2],
			'source_image'          => $companions[3],
			'animated_video'        => $companions[4],
			'animated_video_poster' => $companions[5],
		)
	);
	update_post_meta( $id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => $companions[6] ) ) );
}
parity_attachment( 300, 200, 'internal.jpg', $uploads );
parity_attachment( 301, 100, 'external.jpg', $uploads );
update_post_meta( 200, '_link_page_background_image_id', 300 );
update_post_meta( 100, '_thumbnail_id', 301 );

global $wpdb;
$wpdb->insert(
	$wpdb->postmeta,
	array(
		'post_id'    => 200,
		'meta_key'   => '_raw_duplicate',
		'meta_value' => 'slashes\\and"quotes',
	)
);
$wpdb->insert(
	$wpdb->postmeta,
	array(
		'post_id'    => 200,
		'meta_key'   => '_raw_duplicate',
		'meta_value' => 'a:1:{s:3:"raw";s:4:"test";}',
	)
);

extrachill_analytics_link_page_create_table();
$wpdb->insert(
	extrachill_analytics_link_page_views_table(),
	array(
		'view_id'      => 700,
		'link_page_id' => 200,
		'stat_date'    => '2025-02-03',
		'view_count'   => 11,
	)
);
$wpdb->insert(
	extrachill_analytics_link_page_clicks_table(),
	array(
		'click_id'     => 800,
		'link_page_id' => 200,
		'stat_date'    => '2025-02-03',
		'link_url'     => 'https://example.test/song',
		'link_text'    => 'Song',
		'click_count'  => 7,
	)
);
extrachill_analytics_events_create_table();
$wpdb->insert(
	extrachill_analytics_events_table(),
	array(
		'event_type' => 'page_view',
		'event_data' => wp_json_encode( array( 'post_id' => 200 ) ),
		'source_url' => 'https://artist.fixture.test/proof-one',
		'blog_id'    => 4,
		'created_at' => current_time( 'mysql', true ),
	)
);

switch_to_blog( $destination_blog_id );
extrachill_analytics_link_page_create_table();
$destination_public_before = (int) get_blog_details( $destination_blog_id )->public;
restore_current_blog();
$source_snapshot = array(
	'posts' => array_map( 'ec_link_page_migration_post_fields', array( get_post( 200 ), get_post( 201 ), get_post( 300 ), get_post( 301 ) ) ),
	'meta'  => ec_link_page_migration_meta_rows( array( 200, 201, 300, 301 ) ),
);
$storage_before  = ec_get_link_page_storage_blog_id();

$plan = ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id );
parity_assert( ! is_wp_error( $plan ) && $plan['ready'], 'Plan failed: ' . ( is_wp_error( $plan ) ? $plan->get_error_message() : wp_json_encode( $plan ) ) );
$applied = ec_apply_link_page_storage_migration( $source_blog_id, $destination_blog_id, $plan['fingerprint'] );
parity_assert( ! is_wp_error( $applied ), 'Apply failed: ' . ( is_wp_error( $applied ) ? $applied->get_error_message() : '' ) );
$validated = ec_validate_link_page_storage_migration( $applied['journal_id'] );
parity_assert( ! is_wp_error( $validated ) && 'valid' === $validated['status'], 'Validate failed.' );
switch_to_blog( $destination_blog_id );
parity_assert( 200 === get_post( 300 )->post_parent && 0 === get_post( 301 )->post_parent, 'Attachment parent mapping failed.' );
parity_assert( $modified === get_post( 200 )->post_modified, 'Modified date was not preserved.' );
parity_assert(
	ec_link_page_migration_meta_rows( array( 200 ) ) === array_values(
		array_filter(
			$plan['meta'],
			static function ( $row ) {
				return 200 === $row['post_id'];
			}
		)
	),
	'Raw metadata sequence mismatch.'
);
restore_current_blog();
$rolled_back = ec_rollback_link_page_storage_migration( $applied['journal_id'] );
parity_assert( ! is_wp_error( $rolled_back ), 'Rollback failed.' );
switch_to_blog( $destination_blog_id );
parity_assert( ! get_post( 200 ) && ! get_post( 300 ), 'Destination objects remain after rollback.' );
parity_assert( array() === extrachill_analytics_link_page_migration_rows( array( 200, 201 ) )['views'], 'Destination Analytics remains after rollback.' );
parity_assert( $destination_public_before === (int) get_blog_details( $destination_blog_id )->public, 'Destination visibility changed.' );
restore_current_blog();
parity_assert(
	$source_snapshot === array(
		'posts' => array_map( 'ec_link_page_migration_post_fields', array( get_post( 200 ), get_post( 201 ), get_post( 300 ), get_post( 301 ) ) ),
		'meta'  => ec_link_page_migration_meta_rows( array( 200, 201, 300, 301 ) ),
	),
	'Source changed.'
);
parity_assert( $storage_before === ec_get_link_page_storage_blog_id(), 'Storage routing changed.' );
parity_assert( array() === ( $GLOBALS['_wp_switched_stack'] ?? array() ), 'Multisite context leaked.' );

echo wp_json_encode(
	array(
		'status'              => 'passed',
		'source_blog_id'      => 4,
		'destination_blog_id' => 5,
		'journal_id'          => $applied['journal_id'],
		'fingerprint'         => $plan['fingerprint'],
		'cases'               => array( 'parity', 'raw-meta', 'modified-dates', 'companions', 'parents', 'analytics', 'rollback' ),
	)
);
