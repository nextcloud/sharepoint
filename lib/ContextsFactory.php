<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint;

use OCA\SharePoint\Vendor\Office365\Runtime\Auth\UserCredentials;
use OCA\SharePoint\Vendor\Office365\SharePoint\ClientContext;

class ContextsFactory {
	/**
	 * @throws \Exception
	 */
	public function getClientContext(
		string $url,
		string $user,
		string $password,
		bool $useNtlm = false,
	): ClientContext {
		$clientContext = new ClientContext($url);
		$credentials = new UserCredentials($user, $password);
		if ($useNtlm) {
			return $this->getWithNtlm($clientContext, $credentials);
		}
		return $this->withCredentials($clientContext, $credentials);
	}

	/**
	 * @throws \Exception
	 */
	protected function withCredentials(
		ClientContext $clientContext,
		UserCredentials $userCredentials,
	): ClientContext {
		return $clientContext->withCredentials($userCredentials);
	}

	protected function getWithNtlm(
		ClientContext $clientContext,
		UserCredentials $userCredentials,
	) {
		return $clientContext->withNtlm($userCredentials);
	}
}
