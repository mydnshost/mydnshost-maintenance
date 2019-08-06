#!/usr/bin/php
<?php
	require_once(__DIR__ . '/functions.php');
	require_once(__DIR__ . '/FakeThread.php');
	require_once(__DIR__ . '/RedisLock.php');

	$runId = base_convert(crc32(uniqid()), 16, 36);;

	function showTime() {
		return date('[Y-m-d H:i:s O]');
	}

	$statsTime = time();

	echo showTime(), ' [', $runId, '] Starting gather-statistics.', "\n";

	if (empty($config['redis']) || !class_exists('Redis')) {
		die(showTime() . ' [' . $runId . '] Redis is required for GatherStatistics.' . "\n");
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
			echo showTime(), ' [', $runId, '] Failed to parse statistics for server: ', $server, "\n";
			return FALSE;
		}

		echo showTime(), ' [', $runId, '] Parsing statistics for server: ', $server, "\n";

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
		echo showTime(), ' [', $runId, '] Begin statistics.', "\n";

		if (FakeThread::available()) {
			echo showTime(), ' [', $runId, '] Threaded.', "\n";
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
				echo showTime(), ' [', $runId, '] Starting collector Thread for: ', $name, "\n";
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
						echo showTime(), ' [', $runId, '] ', $t['type'], ' thread has finished for: ', $name, "\n";

						if ($t['type'] == 'collector') {
							$data = $t['thread']->getData();

							echo showTime(), ' [', $runId, '] Starting parser Thread for: ', $name, "\n";
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

			echo showTime(), ' [', $runId, '] Got all data.', "\n";
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
		echo showTime(), ' [', $runId, '] End statistics.', "\n";

		RedisLock::releaseLock('GatherStatistics');
	} else {
		echo showTime(), ' [', $runId, '] Unable to grab statistics, unable to get lock.', "\n";
	}
