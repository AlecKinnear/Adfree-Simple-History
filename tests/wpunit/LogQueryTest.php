<?php

use Simple_History\Log_Query;

/**
 * Test the Log_Query class.
 *
 * This test should run on different versions of MySQL and MariaDB, to make sure it works correctly.
 * 
 * Use NPM scripts:
 * - npm run test:log-query-mysq55
 * - npm run test:log-query-mysq57
 * - npm run test:log-query-mariadb105
 * 
 * 
 * @coversDefaultClass Simple_History\Log_Query
 */
class LogQueryTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * Add n log entries and then query for them.
	 * 
	 * Then add another login attempt and check for updates since the last above id.
	 */
	function test_query() {
		// Add and set current user to admin user, so user can read all logs.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		/*
		action: SimpleHistoryNewRowsNotifier
		apiArgs[since_id]: 1279
		apiArgs[dates]: lastdays:30
		response: 
			"num_new_rows": 1,
        	"num_mysql_queries": 50, (what? why so many??)
		*/
		$added_rows_ids = [];
		$num_rows_to_add = 10;
		for ($i = 0; $i < $num_rows_to_add; $i++) {
			$logger = SimpleLogger()->info(
				'Test info message ' . $i,
				[
					'_occasionsID' => 'my_occasion_id',
					'message_num' => $i,
				]
			);
			$added_rows_ids[] = $logger->last_insert_id;
		}

		// Now query the log and see what id we get as the latest.
		$query_results = (new Log_Query())->query( ['posts_per_page' => 1] );
		$first_log_row_from_query = $query_results['log_rows'][0];

		$this->assertEquals(
			$added_rows_ids[$num_rows_to_add-1], 
			$first_log_row_from_query->id, 
			'The id of the first row in query result should be the same as the id of the last added row.'
		);

		// Add more.
		for ($i = 0; $i < 4; $i++) {
			$logger = SimpleLogger()->info(
				'Another test info message ' . $i,
				[
					'_occasionsID' => 'my_occasion_id_2',
					'message_num' => $i,
				]
			);
		}

		$logger = SimpleLogger()->info(
			'Single message ' . 0,
			[
				'_occasionsID' => 'my_occasion_id_3',
				'message_num' => 0,
			]
		);

		
		$hello_some_messages_message_count = 7;
		for ($i = 0; $i < $hello_some_messages_message_count; $i++) {
			$logger = SimpleLogger()->info(
				'Hello some messages ' . $i,
				[
					'_occasionsID' => 'my_occasion_id_5',
					'message_num' => $i,
				]
			);
		}

		$oh_such_logging_rows_num_to_add = 3;
		for ($i = 0; $i < $oh_such_logging_rows_num_to_add; $i++) {
			$logger = SimpleLogger()->info(
				'Oh such logging things ' . $i,
				[
					'_occasionsID' => 'my_occasion_id_6',
					'message_num' => $i,
				]
			);
		}

		// Get first result and check that it has 3 subsequentOccasions 
		// and that the message is 
		// "Oh such logging things {$i-1}"
		// and that context contains message_num = {$i-1}.
		$results = (new Log_Query())->query([
			'posts_per_page' => 3
		]);

		$first_log_row_from_query = $results['log_rows'][0];
		$second_log_row_from_query = $results['log_rows'][1];
		$third_log_row_from_query = $results['log_rows'][2];

		$this->assertEquals(
			3,
			$first_log_row_from_query->subsequentOccasions,
			'The first log row should have 3 subsequentOccasions.'
		);

		$this->assertIsNumeric($first_log_row_from_query->subsequentOccasions);

		$this->assertEquals(
			'Oh such logging things ' . ($i-1),
			$first_log_row_from_query->message,
			'The first log row should have the message "Oh such logging things" ' . ($i-1)
		);

		$this->assertEquals(
			$i-1,
			$first_log_row_from_query->context['message_num'],
			'The first log row should have the context message_num = ' . ($i-1)
		);

		// Test second message.
		$this->assertEquals(
			$hello_some_messages_message_count,
			$second_log_row_from_query->subsequentOccasions,
			"The second log row should have $hello_some_messages_message_count subsequentOccasions."
		);

		$this->assertIsNumeric($second_log_row_from_query->subsequentOccasions);

		$this->assertEquals(
			'Hello some messages 6',
			$second_log_row_from_query->message,
			'The first log row should have the message "Hello some messages 6"'
		);

		// Test third message.
		$this->assertEquals(
			1,
			$third_log_row_from_query->subsequentOccasions,
			'The third log row should have 1 subsequentOccasions.'
		);
		
		$this->assertIsNumeric($third_log_row_from_query->subsequentOccasions);

		$this->assertEquals(
			'Single message 0',
			$third_log_row_from_query->message,
			'The third log row should have the message "Single message 0"'
		);


		// Test occasions query arg. for first returned row.
		$query_results = (new Log_Query())->query([
			'type' => 'occasions',
			// Get history rows with id:s less than this, i.e. get earlier/previous rows.
			'logRowID' => $first_log_row_from_query->id, 
			'occasionsID' => $first_log_row_from_query->occasionsID, // The occasions id is md5:ed so we need to use log query to get the last row, and then get occasions id..
			'occasionsCount' => $first_log_row_from_query->subsequentOccasions - 1,
		]);

		$this->assertCount(
			$oh_such_logging_rows_num_to_add - 1,
			$query_results['log_rows'],
			'The number of rows returned when getting occasions should be ' . ($oh_such_logging_rows_num_to_add - 1)
		);

		// Test occasions query arg. for second returned row.
		$query_results = (new Log_Query())->query([
			'type' => 'occasions',
			'logRowID' => $second_log_row_from_query->id, 
			'occasionsID' => $second_log_row_from_query->occasionsID, // The occasions id is md5:ed so we need to use log query to get the last row, and then get occasions id..
			'occasionsCount' => $second_log_row_from_query->subsequentOccasions - 1,
		]);

		$this->assertCount(
			$hello_some_messages_message_count - 1,
			$query_results['log_rows'],
			'The number of rows returned when getting occasions should be ' . ($hello_some_messages_message_count - 1)
		);

		// Test occasions query arg. for third returned row.
		$query_results = (new Log_Query())->query([
			'type' => 'occasions',
			'logRowID' => $third_log_row_from_query->id, 
			'occasionsID' => $third_log_row_from_query->occasionsID, // The occasions id is md5:ed so we need to use log query to get the last row, and then get occasions id.
			'occasionsCount' => $third_log_row_from_query->subsequentOccasions - 1,
		]);

		// No further occasions for this row.
		$this->assertSame(
			"1",
			$third_log_row_from_query->subsequentOccasions,
			'The number of rows returned when getting occasions should be 0'
		);

		$this->assertCount(
			0,
			$query_results['log_rows'],
			'The number of rows returned when getting occasions should be 0'
		);
	}

	function test_since_id() {
		// Add and set current user to admin user, so user can read all logs.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$logger = SimpleLogger()->info(
			'Test info message 1',
			[
				'_occasionsID' => 'my_occasion_id',
				'message_num' => 1,
			]
		);

		$last_insert_id = $logger->last_insert_id;

		// Query log again and use $last_insert_id as since_id.
		// It should not have any new rows yet.
		$query_results = (new Log_Query())->query( ['since_id' => $last_insert_id] );
		$this->assertEmpty($query_results['log_rows'], 'There should be no new rows yet.');

		// Add two new events.
		for ($i=0; $i<2; $i++) {
			echo "log $i";
			$logger = SimpleLogger()->info(
				'Test info message ' . $i,
				[
					'_occasionsID' => 'my_occasion_id_in_loop_' . $i,
					'message_num' => $i,
				]
			);
		}

		// Test that we get two new rows.
		$query_results = (new Log_Query())->query( ['since_id' => $last_insert_id] );
		$this->assertEquals(2, $query_results['total_row_count'], 'There should be two new rows now.');
	}

	 /**
	  * Test that the Log_Query returns the expected things.
	  */
	function test_log_query() {
		// Add and set current user to admin user, so user can read all logs.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$log_query_args = array(
			'posts_per_page' => 1,
		);

		$log_query = new Log_Query();
		$query_results = $log_query->query( $log_query_args );

		// The latest row should be the user we create above
		$this->assertArrayHasKey( 'total_row_count', $query_results );
		$this->assertArrayHasKey( 'pages_count', $query_results );
		$this->assertArrayHasKey( 'page_current', $query_results );
		$this->assertArrayHasKey( 'page_rows_from', $query_results );
		$this->assertArrayHasKey( 'page_rows_to', $query_results );
		$this->assertArrayHasKey( 'max_id', $query_results );
		$this->assertArrayHasKey( 'min_id', $query_results );
		$this->assertArrayHasKey( 'log_rows_count', $query_results );
		$this->assertArrayHasKey( 'log_rows', $query_results );

		$this->assertCount( 1, $query_results['log_rows'] );

		// $this->assertFalse(property_exists($myObject, $nonExistentPropertyName));
		// Can not use ->assertObjectHasAttribute because it's deprecated and wp_browser does not have the
		// recommendeded replacement ->assertObjectHasProperty (yet).
		$first_log_row = $query_results['log_rows'][0];

		$this->assertIsObject($first_log_row);

		$this->assertTrue( property_exists( $first_log_row, 'id' ) );
		$this->assertTrue( property_exists( $first_log_row, 'logger' ) );
		$this->assertTrue( property_exists( $first_log_row, 'level' ) );
		$this->assertTrue( property_exists( $first_log_row, 'date' ) );
		$this->assertTrue( property_exists( $first_log_row, 'message' ) );
		$this->assertTrue( property_exists( $first_log_row, 'initiator' ) );
		$this->assertTrue( property_exists( $first_log_row, 'occasionsID' ) );
		$this->assertTrue( property_exists( $first_log_row, 'subsequentOccasions' ) );
		$this->assertTrue( property_exists( $first_log_row, 'context' ) );
	}

	function test_inner_where_array_filter() {
		// Add and set current user to admin user, so user can read all logs.
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$log_query = new Log_Query();
		$query_results = $log_query->query( [
			'posts_per_page' => 10,
		] );
	
		$this->assertGreaterThan(0, did_filter('simple_history/log_query_inner_where_array', 'Filter should have been called.'));
		$this->assertEquals(10, $query_results['log_rows_count'], 'There should be 10 log rows.');

		// Add filter that adds where condition so query returns nothing.
		add_filter('simple_history/log_query_inner_where_array', function($inner_where, $args) {
			$inner_where[] = "1 = 0";
			sh_d('$inner_where', $inner_where);
			return $inner_where;
		}, 10, 2);

		$log_query = new Log_Query();
		$query_results = $log_query->query( [
			'posts_per_page' => 11, // change count so cached query is not used.
		] );

		$this->assertEquals(0, $query_results['log_rows_count'], 'There should be 0 log rows.');
	}
}
