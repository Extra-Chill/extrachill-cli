<?php
/**
 * Focused contract tests for concert tracking CLI adapters.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class ConcertTrackingCommandTestError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();

		public static function error( $message ) {
			throw new ConcertTrackingCommandTestError( $message );
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$messages[] = 'Warning: ' . $message;
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}
	}

	class ConcertTrackingCommandTestWpError {
		private $message;

		public function __construct( $message ) {
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class ConcertTrackingCommandTestAbility {
		public $inputs = array();
		private $callback;

		public function __construct( $callback ) {
			$this->callback = $callback;
		}

		public function execute( $input ) {
			$this->inputs[] = $input;
			return call_user_func( $this->callback, $input );
		}
	}

	$concert_tracking_users = array(
		1 => array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.com' ),
		7 => array( 'ID' => 7, 'user_login' => 'chubes', 'user_email' => 'chubes@example.com' ),
		8 => array( 'ID' => 8, 'user_login' => 'listener', 'user_email' => 'listener@example.com' ),
	);
	$concert_tracking_posts = array(
		10 => array( 'ID' => 10, 'post_type' => 'data_machine_events', 'post_title' => 'First Show' ),
		11 => array( 'ID' => 11, 'post_type' => 'data_machine_events', 'post_title' => 'Second Show' ),
	);
	$concert_tracking_current_user_id = 0;
	$concert_tracking_marks           = array();
	$concert_tracking_abilities       = array();

	function get_user_by( $field, $value ) {
		global $concert_tracking_users;
		foreach ( $concert_tracking_users as $user ) {
			if ( ( 'id' === $field && (int) $value === $user['ID'] ) || ( 'login' === $field && $value === $user['user_login'] ) || ( 'email' === $field && $value === $user['user_email'] ) ) {
				return (object) $user;
			}
		}
		return false;
	}

	function wp_get_current_user() {
		global $concert_tracking_current_user_id;
		return $concert_tracking_current_user_id ? get_user_by( 'id', $concert_tracking_current_user_id ) : (object) array( 'ID' => 0 );
	}

	function wp_set_current_user( $user_id ) {
		global $concert_tracking_current_user_id;
		$concert_tracking_current_user_id = (int) $user_id;
		return wp_get_current_user();
	}

	function current_user_can( $capability ) {
		global $concert_tracking_current_user_id;
		return 'manage_network_options' === $capability && 1 === $concert_tracking_current_user_id;
	}

	function get_post( $post_id ) {
		global $concert_tracking_posts;
		return isset( $concert_tracking_posts[ $post_id ] ) ? (object) $concert_tracking_posts[ $post_id ] : null;
	}

	function wp_get_ability( $name ) {
		global $concert_tracking_abilities;
		return $concert_tracking_abilities[ $name ] ?? null;
	}

	function is_wp_error( $value ) {
		return $value instanceof ConcertTrackingCommandTestWpError;
	}

	function is_email( $value ) {
		return false !== strpos( $value, '@' );
	}

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function concert_tracking_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function concert_tracking_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new \RuntimeException( $message . '\nMissing: ' . $needle );
		}
	}
}

namespace WP_CLI\Utils {
	$concert_tracking_formats = array();

	function format_items( $format, $items, $fields ) {
		global $concert_tracking_formats;
		$concert_tracking_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Events/ConcertTrackingCommand.php';

	use ExtraChill\CLI\Commands\Events\ConcertTrackingCommand;

	global $concert_tracking_abilities, $concert_tracking_current_user_id, $concert_tracking_formats, $concert_tracking_marks;

	$set_ability = new ConcertTrackingCommandTestAbility(
		static function ( $input ) use ( &$concert_tracking_marks, &$concert_tracking_current_user_id ) {
			if ( $input['user_id'] !== $concert_tracking_current_user_id && 1 !== $concert_tracking_current_user_id ) {
				return new ConcertTrackingCommandTestWpError( 'Not authorized for target user.' );
			}
			$key      = $input['user_id'] . ':' . $input['event_id'];
			$before   = ! empty( $concert_tracking_marks[ $key ] );
			$marked   = (bool) $input['marked'];
			$changed  = $before !== $marked;
			$concert_tracking_marks[ $key ] = $marked;
			return array( 'changed' => $changed, 'marked' => $marked, 'timing' => 'past', 'count' => $marked ? 1 : 0 );
		}
	);
	$check_ability = new ConcertTrackingCommandTestAbility(
		static function ( $input ) use ( &$concert_tracking_marks, &$concert_tracking_current_user_id ) {
			if ( isset( $input['user_id'] ) && $input['user_id'] !== $concert_tracking_current_user_id && 1 !== $concert_tracking_current_user_id ) {
				return new ConcertTrackingCommandTestWpError( 'Not authorized for target user.' );
			}
			$key = ( $input['user_id'] ?? $concert_tracking_current_user_id ) . ':' . $input['event_id'];
			return array(
				'user_marked' => ! empty( $concert_tracking_marks[ $key ] ),
				'timing'      => 'past',
				'count'       => ! empty( $concert_tracking_marks[ $key ] ) ? 1 : 0,
				'count_label' => ! empty( $concert_tracking_marks[ $key ] ) ? '1 was there' : '0 were there',
				'attendees'   => array(),
			);
		}
	);
	$concert_tracking_abilities = array(
		'extrachill/set-event-mark'      => $set_ability,
		'extrachill/get-event-attendance' => $check_ability,
	);

	$command = new ConcertTrackingCommand();

	// Bare WP-CLI execution adopts the administrator as actor while retaining the explicit target.
	$command->mark( array( 10 ), array( 'user' => 'chubes' ) );
	$command->mark( array( 10 ), array( 'user' => 'chubes' ) );
	concert_tracking_assert_same( 1, $concert_tracking_current_user_id, 'Bare CLI execution must establish the deterministic administrator actor.' );
	concert_tracking_assert_same( true, $concert_tracking_marks['7:10'], 'Repeated marks must leave the event marked.' );
	concert_tracking_assert_same( array( 'user_id' => 7, 'event_id' => 10, 'marked' => true ), $set_ability->inputs[0], 'Mark must use the canonical desired-state contract.' );
	concert_tracking_assert_contains( 'already marked', implode( "\n", WP_CLI::$messages ), 'Repeated mark must report an unchanged state.' );

	WP_CLI::$messages = array();
	$command->unmark( array( 11 ), array( 'user' => 'chubes' ) );
	$command->unmark( array( 11 ), array( 'user' => 'chubes' ) );
	concert_tracking_assert_same( false, $concert_tracking_marks['7:11'], 'Repeated unmarks must leave the event unmarked.' );
	concert_tracking_assert_contains( 'was not marked', implode( "\n", WP_CLI::$messages ), 'Repeated unmark must report an unchanged state.' );

	$writes_before = count( $set_ability->inputs );
	$command->check( array( 10 ), array( 'user' => 'chubes' ) );
	$command->check( array( 10 ), array( 'user' => 'listener' ) );
	concert_tracking_assert_same( $writes_before, count( $set_ability->inputs ), 'Checks for any user must not execute a mutation ability.' );
	concert_tracking_assert_same( array( 'event_id' => 10, 'user_id' => 8 ), $check_ability->inputs[1], 'Check must pass the selected user explicitly.' );

	$snapshot = $concert_tracking_marks;
	WP_CLI::$messages = array();
	$command->import( array( 'chubes', '10,11' ), array( 'dry-run' => true ) );
	concert_tracking_assert_same( $snapshot, $concert_tracking_marks, 'Import dry-run must not mutate attendance.' );
	concert_tracking_assert_contains( 'Would mark 1 events for chubes. Skipped: 1. Invalid: 0.', implode( "\n", WP_CLI::$messages ), 'Dry-run counts must distinguish changed and skipped events.' );

	WP_CLI::$messages = array();
	$command->import( array( 'chubes', '10,11,11' ), array() );
	concert_tracking_assert_same( true, $concert_tracking_marks['7:11'], 'Import must idempotently mark unmarked events.' );
	concert_tracking_assert_contains( 'Marked 1 events for chubes. Skipped: 2. Invalid: 0.', implode( "\n", WP_CLI::$messages ), 'Import counts must reflect actual changes and repeated IDs.' );

	$concert_tracking_formats = array();
	$command->event( array( 10 ), array( 'attendees' => true, 'limit' => 3 ) );
	$event_input = end( $check_ability->inputs );
	concert_tracking_assert_same( array( 'event_id' => 10, 'include_attendees' => true, 'limit' => 3 ), $event_input, 'Event detail must pass the canonical limit field.' );
	concert_tracking_assert_same( 'Event ID', $concert_tracking_formats[0]['items'][0]['Field'], 'Event detail must present a field backed by the ability contract.' );

	// A non-admin actor cannot silently target another user.
	wp_set_current_user( 8 );
	try {
		$command->mark( array( 10 ), array( 'user' => 'chubes' ) );
		throw new \RuntimeException( 'Unauthorized targeting should fail.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'Not authorized', $error->getMessage(), 'Unauthorized targeting must fail clearly.' );
	}

	fwrite( STDOUT, "ConcertTrackingCommand tests passed.\n" );
}
