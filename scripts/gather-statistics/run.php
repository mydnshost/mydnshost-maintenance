#!/usr/bin/php
<?php
	require_once(__DIR__ . '/functions.php');
	require_once(__DIR__ . '/FakeThread.php');
	require_once(__DIR__ . '/RedisLock.php');

	$runId = base_convert(crc32(uniqid()), 16, 36);

	function doLog(...$args) {
		global $runId;
		echo date('[Y-m-d H:i:s O]'), ' [gather-statistics::', $runId, '] ', implode('', $args), "\n";
	}

	$statsTime = time();

	doLog('Starting gather-statistics.');

	if (empty($config['redis']) || !class_exists('Redis')) {
		doLog('Redis is required for gather-statistics.');
		die();
	} else {
		RedisLock::setRedisHost($config['redis'], $config['redisPort']);
	}

	if (empty($config['influx']['host']) || empty($config['bind']['slaves'])) { die(0); }

	$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);

	$database = $client->selectDB($config['influx']['db']);
	if (!$database->exists()) { $database->create(); }



	function parseStats($server, $xml, $statsTime = NULL) {
		global $runId;

		$xml = simplexml_load_string($xml);
		$points = [];

		if ($xml === false) {
			doLog('Failed to parse statistics for server: ', $server);
			return FALSE;
		}

		doLog('Parsing statistics for server: ', $server);

		if ($statsTime == NULL) {
			$statsTime = strtotime((string)$xml->xpath('/statistics/server/current-time')[0]);
		}

		// Global Statistics
		$query = (int)$xml->xpath('/statistics/server/counters[@type="opcode"]/counter[@name="QUERY"]')[0][0];
		$points[] = new InfluxDB\Point('opcode_query', $query, ['host' => $server], [], $statsTime);

		foreach ($xml->xpath('/statistics/server/counters[@type="qtype"]/counter') as $counter) {
			$type = (string)$counter['name'];
			$value = (int)$counter;
			$points[] = new InfluxDB\Point('qtype', $value, ['host' => $server, 'qtype' => $type], [], $statsTime);
		}

		// Per-Zone Statistics
		foreach ($xml->xpath('/statistics/views/view[@name="_default"]/zones/zone') as $zone) {
			$zoneName = strtolower((string)$zone['name']);

			foreach ($zone->xpath('counters[@type="qtype"]/counter') as $counter) {
				$type = (string)$counter['name'];
				$value = (int)$counter;
				$points[] = new InfluxDB\Point('zone_qtype', $value, ['host' => $server, 'qtype' => $type, 'zone' => $zoneName], [], $statsTime);
			}
		}

		return $points;
	}

	function getStats($host) {
		return @file_get_contents('http://' . $host . ':8080/');
	}

	if (RedisLock::acquireLock('GatherStatistics', false, 300)) {
		doLog('Begin statistics.');

		if (FakeThread::available()) {
			doLog('Threaded.');
			$runningThreads = [];
		}

		// Grab all stats
		$data = [];
		foreach (explode(',', $config['bind']['slaves']) as $slave) {
			$slave = trim($slave);
			$slave = explode('=', $slave);

			$name = $slave[0];
			$host = $slave[1];

			if (FakeThread::available()) {
				doLog('Starting collector Thread for: ', $name);
				$thread = new FakeThread('getStats');
				$thread->start($host);
				$runningThreads[$name] = ['thread' => $thread, 'type' => 'collector'];
			} else {
				$data[$name] = getStats($host);
			}
		}

		$points = [];

		if (FakeThread::available()) {
			set_time_limit(0);
			while (count($runningThreads) > 0) {
				$currentThreads = $runningThreads;
				foreach ($currentThreads as $name => $t) {
					if (!$t['thread']->isAlive()) {
						unset($runningThreads[$name]);
						doLog('', $t['type'], ' thread has finished for: ', $name);

						if ($t['type'] == 'collector') {
							$data = $t['thread']->getData();

							doLog('Starting parser Thread for: ', $name);
							$thread = new FakeThread('parseStats');
							$thread->start($name, $data, $statsTime);
							$runningThreads[$name] = ['thread' => $thread, 'type' => 'parser'];

						} else if ($t['type'] == 'parser') {
							$points[] = $t['thread']->getData();
						}
					}
				}
				// Sleep for 100ms
				usleep(100000);
			}

			doLog('Got all data.');
		} else {
			foreach ($data as $name => $stats) {
				if (!empty($stats)) {
					$points[] = parseStats($name, $xml, $statsTime);
				}
			}
		}

		// Parse stats into database.
		foreach ($points as $p) {
			if ($p !== FALSE) {
				$result = $database->writePoints($p, InfluxDB\Database::PRECISION_SECONDS);
			}
		}
		doLog('End statistics.');

		RedisLock::releaseLock('GatherStatistics');
	} else {
		doLog('Unable to grab statistics, unable to get lock.');
	}
