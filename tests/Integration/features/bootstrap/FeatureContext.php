<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
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
