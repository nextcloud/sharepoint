<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerSensitiveMethods(SamlTokenProvider::class, ['acquireSecurityToken']);
		$context->registerSensitiveMethods(AuthenticationContext::class, ['acquireToken', 'acquireTokenForUser']);
		$context->registerEventListener('OCA\\Files_External::loadAdditionalBackends', ExternalStoragesRegistrationListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
