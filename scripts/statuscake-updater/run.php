#!/usr/bin/php
<?php
	require_once(__DIR__ . '/functions.php');
	require_once(__DIR__ . '/RedisLock.php');

	$runId = base_convert(crc32(uniqid()), 16, 36);

	function doLog(...$args) {
		global $runId;
		echo date('[Y-m-d H:i:s O]'), ' [statuscake-updater::', $runId, '] ', implode('', $args), "\n";
	}

	if (empty($config['mydnshost']['api']) || empty($config['statuscake']['username'])) { die(0); }

	doLog('Starting statuscake-updater at ', date('r'));

	if (empty($config['redis']) || !class_exists('Redis')) {
		doLog('Redis is required for statuscake-updater.');
		die();
	} else {
		RedisLock::setRedisHost($config['redis'], $config['redisPort']);
	}

	if (!RedisLock::acquireLock('statuscake-updater', false, 300)) {
		doLog('Unable to update statuscake, unable to get lock.');
		die();
	}

	$api = new MyDNSHostAPI($config['mydnshost']['api']);
	$api->setAuthDomainKey($config['mydnshost']['domain'], $config['mydnshost']['domain_key']);

	// Get the list of RRs we cycle through
	$rrs = explode(',', $config['mydnshost']['rrnames']);
	// Get the test IDs we need to update.
	$tests = explode(',', $config['statuscake']['testids']);

	// Get the current "active" RRNAME.
	// This is denoted by the value of the "active" TXT record.
	//
	// If there isn't an "active" TXT record, create it.
	$active = $api->getDomainRecordsByName($config['mydnshost']['domain'], 'active');
	if (!isset($active[0])) {
		$active = ['name' => 'active', 'type' => 'TXT', 'content' => $rrs[0]];
		$api->setDomainRecords($config['mydnshost']['domain'], ['records' => [$active]]);
		doLog('Creating missing activerr TXT record: ', json_encode($active));
	} else {
		$active = $active[0];
		doLog('Current activerr is: ', json_encode($active));
	}

	// Find the "active" RRNAME in our list of RRNAMES.
	// if it is not there, assume the first one.
	$oldActivePos = array_search($active['content'], $rrs);
	if ($oldActivePos === FALSE) { $oldActivePos = 0; }
	// Find the next one to use
	$newActivePos = ($oldActivePos + 1) % count($rrs);

	doLog('RRs: ', json_encode($rrs, JSON_FORCE_OBJECT));
	doLog('Old: ', $oldActivePos, ' => ', $rrs[$oldActivePos], ' || New: ', $newActivePos, ' => ', $rrs[$newActivePos]);

	// Find the actual record we need to chagne (the new active RRNAME).
	// Create it if it does not exist.
	$activerr = $api->getDomainRecordsByName($config['mydnshost']['domain'], $rrs[$newActivePos]);
	if (!isset($activerr[0])) {
		$activerr = ['name' => $rrs[$newActivePos], 'type' => 'A', 'content' => '127.0.0.1'];
		doLog('Create Missing ActiveRR');

		$activerr = $api->setDomainRecords($config['mydnshost']['domain'], ['records' => [$activerr]]);
		$activerr = $activerr['response']['changed']['0'];
		doLog('Using activerr: ', json_encode($activerr));
	} else {
		$activerr = $activerr[0];
		doLog('Found activerr: ', json_encode($activerr));
	}

	// Create a new value
	// $newContent = sprintf('127.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(0, 255));
	$newContent = date('127.n.j.G'); // 127.month.day.hour

	doLog('Setting activerr (', $rrs[$newActivePos], ') to ', $newContent);

	// Update 'active' to point at the new activerr and the activerr with the new content.
	$res = $api->setDomainRecords($config['mydnshost']['domain'], ['records' => [['id' => $active['id'], 'content' => $rrs[$newActivePos]], ['id' => $activerr['id'], 'content' => $newContent]]]);
	doLog('Setting: ', json_encode(['records' => [['id' => $active['id'], 'content' => $rrs[$newActivePos]], ['id' => $activerr['id'], 'content' => $newContent]]]));
	doLog('Setting Result: ', json_encode($res));

	// Allow slaves time to update.
	doLog('Allowing servers time to update... (Waiting: 30s)');
	sleep(30);
	doLog('Updating tests.');

	// Update statuscake
	$headers = array('Authorization' => 'Bearer ' . $config['statuscake']['apikey']);
	$data = ['dns_ips' => [$newContent], 'website_url' => sprintf('%s.%s', $rrs[$newActivePos], $config['mydnshost']['domain'])];

	doLog('Updating tests to check ', $data['website_url'], ' is ', $data['dns_ips'][0]);

	foreach ($tests as $testid) {
		try {
			$resp = Requests::put('https://api.statuscake.com/v1/uptime/' . $testid, $headers, $data);
			if ($resp->status_code >= 200 && $resp->status_code < 300) {
				doLog('Updated test ', $testid);
			} else {
				throw new Exception('Got ' . $resp->status_code . ' from API.');
			}
		} catch (Exception $ex) {
			doLog('Error updating ', $testid, ': ', $ex->getMessage());
		}
	}

	// Done.
	RedisLock::releaseLock('statuscake-updater');
	doLog('Finished statuscake-updater at ', date('r'));
