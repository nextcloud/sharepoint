<?php
/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint;

use Psr\Log\LoggerInterface;

class ClientFactory {
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function getClient(
		ContextsFactory $contextsFactory,
		string $sharePointUrl,
		array $credentials,
		array $options = [],
	): Client {
		return new Client(
			$contextsFactory,
			$this->logger,
			$sharePointUrl,
			$credentials,
			$options
		);
	}
}
