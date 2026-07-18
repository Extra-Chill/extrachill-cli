<?php
/**
 * Focused contract and presentation tests for platform experiment adapters.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class ExperimentsCommandTestError extends \RuntimeException {}

	class WP_CLI {
		public static $messages      = array();
		public static $printed       = array();
		public static $confirmations = array();

		public static function error( $message ) {
			throw new ExperimentsCommandTestError( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function print_value( $value, $args ) {
			self::$printed[] = compact( 'value', 'args' );
		}

		public static function confirm( $message, $assoc_args = array() ) {
			self::$confirmations[] = compact( 'message', 'assoc_args' );
		}
	}

	class ExperimentsCommandTestErrorValue {
		public $code;
		private $message;

		public function __construct( $code, $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class ExperimentsCommandTestAbility {
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

	$experiments_command_test_abilities   = array();
	$experiments_command_test_definitions = array(
		array(
			'key'                  => 'copy-test',
			'definition_version'   => 3,
			'assignment_policy'    => 'weighted_random',
			'default_state'        => 'inactive',
			'state'                => 'inactive',
			'default_variant'      => 'control',
			'control_variant'      => 'control',
			'variants'             => array( 'control' => 40, 'challenger-a' => 35, 'challenger-b' => 25 ),
			'surfaces'             => array( 'hero', 'footer' ),
			'future_definition'    => array( 'typed' => true, 'threshold' => 0.75 ),
		),
		array(
			'key'                  => 'version-test',
			'definition_version'   => 2,
			'assignment_policy'    => 'weighted_random',
			'default_state'        => 'active',
			'state'                => 'active',
			'default_variant'      => 'control',
			'control_variant'      => 'control',
			'variants'             => array( 'control' => 50, 'treatment' => 50 ),
			'surfaces'             => array( 'link-page' ),
		),
	);

	function wp_get_ability( $name ) {
		global $experiments_command_test_abilities;
		return $experiments_command_test_abilities[ $name ] ?? null;
	}

	function is_wp_error( $value ) {
		return $value instanceof ExperimentsCommandTestErrorValue;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function experiments_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function experiments_command_test_assert_true( $value, $message ) {
		if ( ! $value ) {
			throw new \RuntimeException( $message );
		}

	}

	function experiments_command_test_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new \RuntimeException( $message . '\nMissing: ' . $needle );
		}
	}

	function experiments_command_test_reset_output() {
		WP_CLI::$messages      = array();
		WP_CLI::$printed       = array();
		WP_CLI::$confirmations = array();
		$GLOBALS['experiments_command_test_formats'] = array();
	}
}

namespace WP_CLI\Utils {
	$experiments_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $experiments_command_test_formats;
		$experiments_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Experiments/ExperimentsCommand.php';

	use ExtraChill\CLI\Commands\Experiments\ExperimentsCommand;

	global $experiments_command_test_abilities, $experiments_command_test_definitions, $experiments_command_test_formats;

	$list_ability = new ExperimentsCommandTestAbility(
		static function () use ( &$experiments_command_test_definitions ) {
			return $experiments_command_test_definitions;
		}
	);
	$transition_ability = new ExperimentsCommandTestAbility(
		static function ( $input ) use ( &$experiments_command_test_definitions ) {
			foreach ( $experiments_command_test_definitions as &$definition ) {
				if ( $definition['key'] === $input['experiment_key'] ) {
					$previous           = $definition['state'];
					$definition['state'] = $input['state'];
					return array(
						'experiment_key'     => $input['experiment_key'],
						'definition_version' => $input['definition_version'],
						'previous_state'     => $previous,
						'state'              => $input['state'],
					);
				}
			}
			return new ExperimentsCommandTestErrorValue( 'experiment_not_registered', 'Experiment is not registered.' );
		}
	);
	$report_result = array(
		'experiment_key'     => 'copy-test',
		'definition_version' => null,
		'control_variant'    => 'control',
		'state'              => 'measured',
		'variants'           => array(
			array(
				'variant'    => 'control',
				'assignment' => array( 'people' => 10, 'stored_events' => 11, 'coverage_status' => 'measured' ),
				'exposure'   => array( 'people' => 8, 'stored_events' => 8, 'rate' => 0.8, 'rate_status' => 'measured', 'rate_ci_95' => array( 'lower' => 0.49, 'upper' => 0.94 ), 'coverage_status' => 'measured', 'analysis_lens' => 'descriptive' ),
				'outcomes'   => array(
					'newsletter_signup' => array(
						'coverage_status'  => 'measured',
						'after_assignment' => array( 'people' => 2, 'rate' => 0.2, 'rate_status' => 'measured', 'rate_ci_95' => array( 'lower' => 0.06, 'upper' => 0.51 ), 'lift_vs_control' => array( 'absolute' => 0.0, 'relative' => 0.0, 'absolute_ci_95' => null, 'relative_ci_95' => null, 'status' => 'control_reference' ) ),
						'after_exposure'   => array( 'people' => 2, 'rate' => 0.25, 'rate_status' => 'measured', 'analysis_lens' => 'descriptive_exposure_conditioned' ),
					),
				),
			),
			array(
				'variant'    => 'challenger-a',
				'assignment' => array( 'people' => 0, 'stored_events' => 0, 'coverage_status' => 'measured' ),
				'exposure'   => array( 'people' => 0, 'stored_events' => 0, 'rate' => null, 'rate_status' => 'zero_denominator', 'rate_ci_95' => null, 'coverage_status' => 'measured', 'analysis_lens' => 'descriptive' ),
				'outcomes'   => array( 'newsletter_signup' => array( 'coverage_status' => 'measured', 'after_assignment' => array( 'people' => 0, 'rate' => null, 'rate_status' => 'zero_denominator', 'rate_ci_95' => null, 'lift_vs_control' => array( 'absolute' => null, 'relative' => null, 'status' => 'zero_denominator' ) ), 'after_exposure' => null ) ),
			),
			array(
				'variant'    => 'challenger-b',
				'assignment' => array( 'people' => 4, 'stored_events' => 4, 'coverage_status' => 'measured' ),
				'exposure'   => array( 'people' => 3, 'stored_events' => 3, 'rate' => 0.75, 'rate_status' => 'measured', 'coverage_status' => 'measured', 'analysis_lens' => 'descriptive' ),
				'outcomes'   => array(),
			),
		),
		'version_diagnostics' => array( 'observed_event_rows_by_version' => array( 1 => 8, 3 => 18 ), 'mixed_versions_observed' => true, 'requested_version' => null, 'surfaces' => array( 'hero', 'footer' ), 'assignment_policies' => array( 'weighted_random' ) ),
		'coverage'            => array( 'loaded_rows' => 26, 'truncated' => false, 'no_data_semantics' => 'Null is not zero.' ),
		'future_report'       => array( 'sample_size' => 14, 'ratio' => 0.625, 'available' => false ),
	);
	$report_ability = new ExperimentsCommandTestAbility(
		static function () use ( &$report_result ) {
			return $report_result;
		}
	);
	$experiments_command_test_abilities = array(
		'extrachill/list-experiments'             => $list_ability,
		'extrachill/transition-experiment-state' => $transition_ability,
		'extrachill/get-experiment-summary'       => $report_ability,
	);
	$command = new ExperimentsCommand();

	// List/get machine output retains complete typed definitions and future fields.
	$command->list_( array(), array( 'format' => 'json' ) );
	experiments_command_test_assert_same( $experiments_command_test_definitions, WP_CLI::$printed[0]['value'], 'List JSON must preserve every owner definition.' );
	experiments_command_test_assert_same( 3, WP_CLI::$printed[0]['value'][0]['definition_version'], 'List JSON versions must remain integers.' );
	experiments_command_test_assert_same( true, WP_CLI::$printed[0]['value'][0]['future_definition']['typed'], 'List JSON future booleans must remain typed.' );
	experiments_command_test_reset_output();
	$command->list_( array(), array( 'format' => 'csv' ) );
	$csv = $experiments_command_test_formats[0];
	experiments_command_test_assert_true( in_array( 'future_definition', $csv['fields'], true ), 'List CSV must union every future owner field.' );
	experiments_command_test_assert_same( $experiments_command_test_definitions[0]['variants'], json_decode( $csv['items'][0]['variants'], true ), 'List CSV must JSON-encode variant weights.' );
	experiments_command_test_assert_same( 0.75, json_decode( $csv['items'][0]['future_definition'], true )['threshold'], 'List CSV must retain nested numeric types.' );
	experiments_command_test_reset_output();
	$command->get( array( 'copy-test' ), array( 'format' => 'json' ) );
	experiments_command_test_assert_same( $experiments_command_test_definitions[0], WP_CLI::$printed[0]['value'], 'Get JSON must preserve one complete definition.' );

	// Inactive human output explicitly reports effective no-op behavior.
	experiments_command_test_reset_output();
	$command->get( array( 'copy-test' ), array() );
	$human = implode( "\n", WP_CLI::$messages );
	experiments_command_test_assert_contains( 'Code default state: inactive', $human, 'Human definition output must separate the code default.' );
	experiments_command_test_assert_contains( 'Effective/live state: inactive', $human, 'Human definition output must show effective/live state.' );
	experiments_command_test_assert_contains( 'no-op; normal consumer behavior remains unchanged', $human, 'Inactive definitions must be labeled no-op.' );
	experiments_command_test_assert_contains( 'control=40, challenger-a=35, challenger-b=25', $human, 'Human definition output must separate weights.' );
	experiments_command_test_assert_contains( 'Surfaces: hero, footer', $human, 'Human definition output must separate surfaces.' );

	// Active and completed transitions confirm, map exact owner arguments, and retain before/after envelopes.
	experiments_command_test_reset_output();
	$command->state( array( 'copy-test', 'active' ), array( 'yes' => true, 'format' => 'json' ) );
	experiments_command_test_assert_same( 1, count( WP_CLI::$confirmations ), 'Activation must use interactive confirmation.' );
	experiments_command_test_assert_same( true, WP_CLI::$confirmations[0]['assoc_args']['yes'], 'Confirmation must support WP-CLI safe --yes bypass.' );
	experiments_command_test_assert_same( array( 'experiment_key' => 'copy-test', 'definition_version' => 3, 'state' => 'active' ), $transition_ability->inputs[0], 'State must invoke Network transition with exact fields.' );
	experiments_command_test_assert_same( 'inactive', WP_CLI::$printed[0]['value']['before']['state'], 'State JSON must retain the complete before owner envelope.' );
	experiments_command_test_assert_same( 'active', WP_CLI::$printed[0]['value']['after']['state'], 'State JSON must retain the complete after owner envelope.' );
	experiments_command_test_assert_same( $experiments_command_test_definitions[0]['future_definition'], WP_CLI::$printed[0]['value']['after']['future_definition'], 'State JSON must retain future owner fields.' );

	$experiments_command_test_definitions[0]['state'] = 'paused';
	experiments_command_test_reset_output();
	$command->state( array( 'copy-test', 'completed' ), array( 'format' => 'csv' ) );
	experiments_command_test_assert_same( 1, count( WP_CLI::$confirmations ), 'Completion must require confirmation.' );
	experiments_command_test_assert_same( 'csv', $experiments_command_test_formats[0]['format'], 'State must support complete CSV output.' );
	experiments_command_test_assert_same( 'paused', json_decode( $experiments_command_test_formats[0]['items'][0]['before'], true )['state'], 'State CSV must retain nested before values.' );
	experiments_command_test_assert_same( 'completed', json_decode( $experiments_command_test_formats[0]['items'][0]['after'], true )['state'], 'State CSV must retain nested after values.' );

	// Report maps every bounded Analytics input exactly and preserves machine types/future fields.
	experiments_command_test_reset_output();
	$command->report(
		array( 'copy-test' ),
		array(
			'definition-version'      => '2',
			'control-variant'         => 'control',
			'variants'                => 'control,challenger-a,challenger-b',
			'outcome-event-types'     => 'newsletter_signup,bridge_click',
			'days'                    => '90',
			'attribution-window-days' => '45',
			'session-gap-mins'        => '120',
			'max-events'              => '100000',
			'format'                  => 'json',
		)
	);
	experiments_command_test_assert_same(
		array(
			'experiment_key'          => 'copy-test',
			'control_variant'         => 'control',
			'variants'                => array( 'control', 'challenger-a', 'challenger-b' ),
			'outcome_event_types'     => array( 'newsletter_signup', 'bridge_click' ),
			'days'                    => 90,
			'attribution_window_days' => 45,
			'session_gap_mins'        => 120,
			'max_events'              => 100000,
			'definition_version'      => 2,
		),
		$report_ability->inputs[0],
		'Report must map every bounded Analytics field exactly.'
	);
	experiments_command_test_assert_same( $report_result, WP_CLI::$printed[0]['value'], 'Report JSON must preserve the complete Analytics envelope.' );
	experiments_command_test_assert_same( null, WP_CLI::$printed[0]['value']['variants'][1]['exposure']['rate'], 'Report JSON must preserve null denominators.' );
	experiments_command_test_assert_same( 0.625, WP_CLI::$printed[0]['value']['future_report']['ratio'], 'Report JSON must retain future numeric fields.' );
	experiments_command_test_assert_same( false, WP_CLI::$printed[0]['value']['future_report']['available'], 'Report JSON must retain future boolean fields.' );

	experiments_command_test_reset_output();
	$command->report( array( 'copy-test' ), array( 'format' => 'csv' ) );
	experiments_command_test_assert_same(
		array(
			'experiment_key'          => 'copy-test',
			'control_variant'         => 'control',
			'variants'                => array( 'control', 'challenger-a', 'challenger-b' ),
			'outcome_event_types'     => array(),
			'days'                    => 28,
			'attribution_window_days' => 28,
			'session_gap_mins'        => 30,
			'max_events'              => 50000,
		),
		$report_ability->inputs[1],
		'Report defaults must derive only required definition dimensions and exact owner defaults.'
	);
	$report_csv = $experiments_command_test_formats[0];
	experiments_command_test_assert_same( array_keys( $report_result ), $report_csv['fields'], 'Report CSV must retain every owner field.' );
	experiments_command_test_assert_same( null, json_decode( $report_csv['items'][0]['variants'], true )[1]['exposure']['rate'], 'Report CSV nested null must remain null.' );
	experiments_command_test_assert_same( $report_result['future_report'], json_decode( $report_csv['items'][0]['future_report'], true ), 'Report CSV must retain future nested fields.' );

	// Human report separates statistical lenses, diagnostics, and never declares a winner.
	experiments_command_test_reset_output();
	$command->report( array( 'copy-test' ), array() );
	$human = implode( "\n", WP_CLI::$messages );
	experiments_command_test_assert_contains( 'Assignment (intent-to-treat)', $human, 'Human report must label assignment ITT.' );
	experiments_command_test_assert_contains( 'Exposure (descriptive, exposure-conditioned)', $human, 'Human report must label exposure-conditioned metrics descriptive.' );
	experiments_command_test_assert_contains( 'lift/confidence vs control', $human, 'Human report must separate lift and confidence.' );
	experiments_command_test_assert_contains( 'unavailable / insufficient data', $human, 'Human report must preserve insufficient-data states.' );
	experiments_command_test_assert_contains( 'Version/surface/policy diagnostics', $human, 'Human report must show mixed-version and surface diagnostics.' );
	experiments_command_test_assert_contains( 'Coverage and insufficient-data diagnostics', $human, 'Human report must separate coverage.' );
	experiments_command_test_assert_contains( 'No winner is declared', $human, 'Human report must expressly avoid winner selection.' );

	// Owner permission and transition errors pass through without replacement.
	$experiments_command_test_abilities['extrachill/list-experiments'] = new ExperimentsCommandTestAbility(
		static function () {
			return new ExperimentsCommandTestErrorValue( 'rest_forbidden', 'Sorry, you are not allowed to manage experiment lifecycle.' );
		}
	);
	try {
		$command->list_( array(), array() );
		throw new \RuntimeException( 'Permission error did not terminate list.' );
	} catch ( ExperimentsCommandTestError $error ) {
		experiments_command_test_assert_same( 'Sorry, you are not allowed to manage experiment lifecycle.', $error->getMessage(), 'List permission errors must propagate unchanged.' );
	}
	$experiments_command_test_abilities['extrachill/list-experiments'] = $list_ability;
	$experiments_command_test_abilities['extrachill/transition-experiment-state'] = new ExperimentsCommandTestAbility(
		static function () {
			return new ExperimentsCommandTestErrorValue( 'invalid_experiment_state_transition', 'Experiment lifecycle transition is invalid.' );
		}
	);
	try {
		$command->state( array( 'copy-test', 'active' ), array( 'yes' => true ) );
		throw new \RuntimeException( 'Invalid transition did not terminate state.' );
	} catch ( ExperimentsCommandTestError $error ) {
		experiments_command_test_assert_same( 'Experiment lifecycle transition is invalid.', $error->getMessage(), 'Invalid transition errors must propagate unchanged.' );
	}

	$registry = file_get_contents( dirname( __DIR__ ) . '/inc/CommandRegistry.php' );
	experiments_command_test_assert_contains( "'extrachill experiments'", $registry, 'Experiments command group must be registered.' );

	fwrite( STDOUT, "ExperimentsCommand tests passed.\n" );
}
