#!/usr/bin/php
<?php
	require_once(__DIR__ . '/functions.php');

	if (empty($config['mydnshost']['api']) || empty($config['statuscake']['username'])) { die(0); }

	echo '[statuscake-updater] Starting statuscake-updater at ', date('r'), "\n";

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
		echo '[statuscake-updater] Creating missing activerr TXT record: ', json_encode($active), "\n";
	} else {
		$active = $active[0];
		echo '[statuscake-updater] Current activerr is: ', json_encode($active), "\n";
	}

	// Find the "active" RRNAME in our list of RRNAMES.
	// if it is not there, assume the first one.
	$oldActivePos = array_search($active['content'], $rrs);
	if ($oldActivePos === FALSE) { $oldActivePos = 0; }
	// Find the next one to use
	$newActivePos = ($oldActivePos + 1) % count($rrs);

	echo '[statuscake-updater] RRs: ', json_encode($rrs, JSON_FORCE_OBJECT), "\n";
	echo '[statuscake-updater] Old: ', $oldActivePos, ' => ', $rrs[$oldActivePos], ' || New: ', $newActivePos, ' => ', $rrs[$newActivePos], "\n";

	// Find the actual record we need to chagne (the new active RRNAME).
	// Create it if it does not exist.
	$activerr = $api->getDomainRecordsByName($config['mydnshost']['domain'], $rrs[$newActivePos]);
	if (!isset($activerr[0])) {
		$activerr = ['name' => $rrs[$newActivePos], 'type' => 'A', 'content' => '127.0.0.1'];
		echo '[statuscake-updater] Create Missing ActiveRR', "\n";

		$activerr = $api->setDomainRecords($config['mydnshost']['domain'], ['records' => [$activerr]]);
		$activerr = $activerr['response']['changed']['0'];
		echo '[statuscake-updater] Using activerr: ', json_encode($activerr), "\n";
	} else {
		$activerr = $activerr[0];
		echo '[statuscake-updater] Found activerr: ', json_encode($activerr), "\n";
	}

	// Create a new value
	// $newContent = sprintf('127.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(0, 255));
	$newContent = date('127.n.j.G'); // 127.month.day.hour

	echo '[statuscake-updater] Setting activerr (', $rrs[$newActivePos], ') to ', $newContent, "\n";

	// Update 'active' to point at the new activerr and the activerr with the new content.
	$api->setDomainRecords($config['mydnshost']['domain'], ['records' => [['id' => $active['id'], 'content' => $rrs[$newActivePos]], ['id' => $activerr['id'], 'content' => $newContent]]]);

	// Allow slaves time to update.
	echo '[statuscake-updater] Allowing servers time to update... (Waiting: 30s)', "\n";
	sleep(30);
	echo '[statuscake-updater] Updating tests.', "\n";

	// Update statuscake
	$headers = array('API' => $config['statuscake']['apikey'], 'Username' => $config['statuscake']['username']);
	$data = ['TestID' => '0', 'DNSIP' => $newContent, 'WebsiteURL' => sprintf('%s.%s', $rrs[$newActivePos], $config['mydnshost']['domain'])];

	echo '[statuscake-updater] Updating tests to check ', $data['WebsiteURL'], ' is ', $data['DNSIP'], "\n";

	foreach ($tests as $testid) {
		$data['TestID'] = $testid;
		try {
			Requests::put('https://app.statuscake.com/API/Tests/Update', $headers, $data);
			echo '[statuscake-updater] Updated test ', $testid, "\n";
		} catch (Exception $ex) {
			echo '[statuscake-updater] Error updating ', $testid, ': ', $ex->getMessage(), "\n";
		}
	}

	// Done.

	echo '[statuscake-updater] Finished statuscake-updater at ', date('r'), "\n";
