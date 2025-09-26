<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint\Backend;

use OCA\Files_External\Lib\Auth\Password\Password;
use OCA\Files_External\Lib\Backend\Backend;
use OCA\Files_External\Lib\Config\IBackendProvider;
use OCP\L10N\IFactory;

class Provider implements IBackendProvider {

	public function __construct(
		protected IFactory $lFactory,
	) {
	}

	/**
	 * @since 9.1.0
	 * @return Backend[]
	 */
	#[\Override]
	public function getBackends(): array {
		$backend = new \OCA\SharePoint\Backend\Backend(
			$this->lFactory->get('sharepoint'),
			new Password($this->lFactory->get('files_external'))
		);
		return [ $backend ];
	}
}
