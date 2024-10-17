<?php

/**
 * @copyright Copyright (c) 2016 Arthur Schiwon <blizzz@arthur-schiwon.de>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
