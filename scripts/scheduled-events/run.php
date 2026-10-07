#!/usr/bin/php
<?php
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	require_once(__DIR__ . '/functions.php');

	function doLog(...$args) {
		global $runId;
		echo date('[Y-m-d H:i:s O]'), ' [scheduled-events::', $runId, '] ', implode('', $args), "\n";
	}

	if (!isset($argv[1])) {
		echo 'Usage: ', $argv[0], ' <event>', "\n";
		exit(1);
	}
	$event = strtolower($argv[1]);
	$args = [];

	doLog('Scheduled Event: ', $event);

	$connection = new AMQPStreamConnection($config['rabbitmq']['host'], $config['rabbitmq']['port'], $config['rabbitmq']['user'], $config['rabbitmq']['pass']);
	$channel = $connection->channel();
	$channel->confirm_select();
	$channel->set_nack_handler(function ($msg) use ($event) {
		doLog('Event rejected by RabbitMQ: ', $event);
		exit(1);
	});
	$channel->exchange_declare('events', 'topic', false, true, false);
	$msg = new AMQPMessage(json_encode(['event' => $event, 'args' => $args]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
	$channel->basic_publish($msg, 'events', 'event.' . $event);
	$channel->wait_for_pending_acks(10);
