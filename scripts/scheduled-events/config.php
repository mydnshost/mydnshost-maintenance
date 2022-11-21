<?php
	$config['rabbitmq']['host'] = getEnvOrDefault('RABBITMQ_HOST', '127.0.0.1');
	$config['rabbitmq']['port'] = getEnvOrDefault('RABBITMQ_PORT', 5672);
	$config['rabbitmq']['user'] = getEnvOrDefault('RABBITMQ_USER', 'guest');
	$config['rabbitmq']['pass'] = getEnvOrDefault('RABBITMQ_PASS', 'guest');

	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
