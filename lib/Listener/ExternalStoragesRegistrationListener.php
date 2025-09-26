<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint\Listener;

use OCA\Files_External\Service\BackendService;
use OCA\SharePoint\Backend\Provider as BackendProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<Event>
 */
class ExternalStoragesRegistrationListener implements IEventListener {

	public function __construct(
		private BackendService $backendService,
		private BackendProvider $backendProvider,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		$this->backendService->registerBackendProvider($this->backendProvider);
	}
}
