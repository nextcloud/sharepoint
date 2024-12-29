<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Behat\Context\Context;

require __DIR__ . '/../../vendor/autoload.php';

class FeatureContext implements Context {
	protected int $latestCreatedStorage;

	use CommandLine;

	public function __construct() {
		$this->ocPath = __DIR__ . '/../../../../../../';
	}

	/**
	 * @Given /^a dummy storage with login "([^"]*)" and password "([^"]*)"$/
	 */
	public function aDummyStorageWithLoginAndPassword(string $login, string $password): void {
		$code = $this->runOcc([
			'files_external:create',
			'--output=json',
			'-c', 'host=my.sharepoint.test',
			'-c', 'documentLibrary=QA Documents',
			'-c', 'user=' . $login,
			'-c', 'password=' . $password,
			'/Team QA',
			'sharepoint',
			'password::password',
		]);
		if ($code === 0) {
			$this->latestCreatedStorage = (int)$this->lastStdOut;
		} else {
			throw new \RuntimeException('Storage was not created, output: ' . PHP_EOL . $this->lastStdOut);
		}
	}

	/**
	 * @When /^verifying the latest created storage \(ignoring the result\)$/
	 */
	public function verifyingTheLatestCreatedStorageIgnoringTheResult(): void {
		if (!isset($this->latestCreatedStorage)) {
			throw new \RuntimeException('No storage was created priorly');
		}
		$this->runOcc([
			'files_external:verify',
			$this->latestCreatedStorage
		]);
	}

	/**
	 * @Then /^the string "([^"]*)" must not appear in the nextcloud\.log$/
	 */
	public function theStringMustNotAppearInTheNextcloudLog(string $sensitiveString) {
		$logFile = __DIR__ . '/../../../../../../data/nextcloud.log';
		if (!file_exists($logFile)) {
			throw new \RuntimeException('Log file does not exist :\'-(');
		}
		$log = \file_get_contents($logFile);
		if (strpos($log, $sensitiveString) !== false) {
			throw new \RuntimeException('Sensitive string was found in the log!');
		}
	}
}
