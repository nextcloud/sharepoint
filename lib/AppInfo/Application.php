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

use OCA\SharePoint\Listener\ExternalStoragesRegistrationListener;
use OCA\SharePoint\Vendor\Office365\Runtime\Auth\AuthenticationContext;
use OCA\SharePoint\Vendor\Office365\Runtime\Auth\SamlTokenProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('sharepoint');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSensitiveMethods(SamlTokenProvider::class, ['acquireSecurityToken']);
		$context->registerSensitiveMethods(AuthenticationContext::class, ['acquireToken', 'acquireTokenForUser']);
		$context->registerEventListener('OCA\\Files_External::loadAdditionalBackends', ExternalStoragesRegistrationListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
