#!/usr/bin/php
<?php
	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	require_once(__DIR__ . '/functions.php');

	function doLog(...$args) {
		global $runId;
		echo date('[Y-m-d H:i:s O]'), ' [gather-statistics::', $runId, '] ', implode('', $args), "\n";
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
	$channel->exchange_declare('events', 'topic', false, false, false);
	$event = strtolower('cron.daily');
	$msg = new AMQPMessage(json_encode(['event' => $event, 'args' => $args]));
	$channel->basic_publish($msg, 'events', 'event.' . $event);
