<?php
	$config['influx']['host'] = getEnvOrDefault('INFLUX_HOST', 'localhost');
	$config['influx']['port'] = getEnvOrDefault('INFLUX_PORT', '8086');
	$config['influx']['user'] = getEnvOrDefault('INFLUX_USER', '');
	$config['influx']['pass'] = getEnvOrDefault('INFLUX_PASS', '');
	$config['influx']['db'] = getEnvOrDefault('INFLUX_DB', 'MyDNSHost');

	$config['redis'] = getEnvOrDefault('REDIS_HOST', '');
	$config['redisPort'] = getEnvOrDefault('REDIS_PORT', 6379);

	$config['bind']['slaves'] = getEnvOrDefault('INFLUX_BIND_SLAVES', 'ns1=1.1.1.1, ns2=2.2.2.2, ns3=3.3.3.3');

	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
