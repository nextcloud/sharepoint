<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint\Backend;

use OCA\Files_External\Lib\Auth\AuthMechanism;
use OCA\Files_External\Lib\Auth\Password\Password;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\SharePoint\Storage\Storage;
use OCP\IL10N;

class Backend extends \OCA\Files_External\Lib\Backend\Backend {
	public function __construct(IL10N $l, Password $legacyAuth) {
		$forceNtlmParameter = new DefinitionParameter('forceNtlm', $l->t('Enforce NTLM auth'));
		$forceNtlmParameter->setType(DefinitionParameter::VALUE_BOOLEAN);
		$forceNtlmParameter->setTooltip($l->t('Acquiring a SAML token is attempted first by default.'));

		$this
			->setIdentifier('sharepoint')
			->setStorageClass(Storage::class)
			->setText($l->t('SharePoint'))
			->addParameters([
				(new DefinitionParameter('host', $l->t('Host'))),
				(new DefinitionParameter('documentLibrary', $l->t('Document Library'))),
				$forceNtlmParameter
			])
			->addAuthScheme(AuthMechanism::SCHEME_PASSWORD)
			->setLegacyAuthMechanism($legacyAuth)
		;
	}
}
