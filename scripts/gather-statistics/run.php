#!/usr/bin/php
<?php
	require_once(__DIR__ . '/functions.php');
	require_once(__DIR__ . '/FakeThread.php');

	if (empty($config['influx']['host']) || empty($config['bind']['slaves'])) { die(0); }

	$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);

	$database = $client->selectDB($config['influx']['db']);
	if (!$database->exists()) { $database->create(); }

	if (empty($config['redis']) || !class_exists('Redis')) {
		die('Redis is required for GatherStatistics.');
	} else {
		RedisLock::setRedisHost($config['redis'], $config['redisPort']);
	}

	function parseStats($server, $xml, $time = NULL) {
		$xml = simplexml_load_string($xml);
		$points = [];

		if ($xml === false) {
			echo 'Failed to parse statistics for server: ', $server, "\n";
			return FALSE;
		}

		echo 'Parsing statistics for server: ', $server, "\n";

		if ($time == NULL) {
			$time = strtotime((string)$xml->xpath('/statistics/server/current-time')[0]);
		}

		// Global Statistics
		$query = (int)$xml->xpath('/statistics/server/counters[@type="opcode"]/counter[@name="QUERY"]')[0][0];
		$points[] = new InfluxDB\Point('opcode_query', $query, ['host' => $server], [], $time);

		foreach ($xml->xpath('/statistics/server/counters[@type="qtype"]/counter') as $counter) {
			$type = (string)$counter['name'];
			$value = (int)$counter;
			$points[] = new InfluxDB\Point('qtype', $value, ['host' => $server, 'qtype' => $type], [], $time);
		}

		// Per-Zone Statistics
		foreach ($xml->xpath('/statistics/views/view[@name="_default"]/zones/zone') as $zone) {
			$zoneName = strtolower((string)$zone['name']);

			foreach ($zone->xpath('counters[@type="qtype"]/counter') as $counter) {
				$type = (string)$counter['name'];
				$value = (int)$counter;
				$points[] = new InfluxDB\Point('zone_qtype', $value, ['host' => $server, 'qtype' => $type, 'zone' => $zoneName], [], $time);
			}
		}

		return $points;
	}

	function getStats($host) {
		return @file_get_contents('http://' . $host . ':8080/');
	}

	if (!file_exists(__DIR__ . '/run.lock')) { file_put_contents(__DIR__ . '/run.lock', ''); }

	$time = time();

	if (RedisLock::acquireLock('GatherStatistics', false)) {
		echo 'Begin statistics.', "\n";

		if (FakeThread::available()) {
			echo 'Threaded.', "\n";
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
				echo 'Starting collector Thread for: ', $name, "\n";
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
						echo $t['type'], ' thread has finished for: ', $name, "\n";

						if ($t['type'] == 'collector') {
							$data = $t['thread']->getData();

							echo 'Starting parser Thread for: ', $name, "\n";
							$thread = new FakeThread('parseStats');
							$thread->start($name, $data, $time);
							$runningThreads[$name] = ['thread' => $thread, 'type' => 'parser'];

						} else if ($t['type'] == 'parser') {
							$points[] = $t['thread']->getData();
						}
					}
				}
				// Sleep for 100ms
				usleep(100000);
			}

			echo 'Got all data.', "\n";
		} else {
			foreach ($data as $name => $stats) {
				if (!empty($stats)) {
					$points[] = parseStats($name, $xml, $time);
				}
			}
		}

		// Parse stats into database.
		foreach ($points as $p) {
			if ($p !== FALSE) {
				$result = $database->writePoints($p, InfluxDB\Database::PRECISION_SECONDS);
			}
		}
		echo 'End statistics.', "\n";

		RedisLock::releaseLock('GatherStatistics');
	} else {
		echo 'Unable to grab statistics, unable to get lock.', "\n";
	}
