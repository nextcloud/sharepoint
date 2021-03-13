<?php
/**
 * @copyright Copyright (c) 2017 Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OCA\SharePoint\AppInfo;

use OCA\Files_External\Service\BackendService;
use OCA\SharePoint\AuthMechanism\Provider as AuthMechanismProvider;
use OCA\SharePoint\Backend\Provider;
use OCP\AppFramework\App;

class Application extends App {
	public function __construct() {
		parent::__construct('sharepoint');
	}

	public function registerBackendProvider() {
		$server = $this->getContainer()->getServer();

		/** @var Provider $ntlmAuth */
		$spAuthMechanismProvider = $server->get(AuthMechanismProvider::class);

		$backendProvider = new Provider($server->getL10NFactory());
		/** @var BackendService $backendService */
		$backendService = $server->getStoragesBackendService();
		$backendService->registerBackendProvider($backendProvider);
		$backendService->registerAuthMechanismProvider($spAuthMechanismProvider);
	}
}
