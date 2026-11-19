<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Isolated\Symfony\Component\Finder\Finder;

// You can do your own things here, e.g. collecting symbols to expose dynamically
// or files to exclude.
// However beware that this file is executed by PHP-Scoper, hence if you are using
// the PHAR it will be loaded by the PHAR. So it is highly recommended to avoid
// to auto-load any code here: it can result in a conflict or even corrupt
// the PHP-Scoper analysis.

return [
	// For more see: https://github.com/humbug/php-scoper/blob/master/docs/configuration.md#prefix
	'prefix' => 'OCA\\SharePoint\\Vendor',

	// For more see: https://github.com/humbug/php-scoper/blob/master/docs/configuration.md#finders-and-paths
	'finders' => [
		Finder::create()->files()
			->exclude([
				'test',
				'composer',
				'bin',
			])
			->notName('autoload.php')
			->in('vendor/vgrem'),
		Finder::create()->files()
			->exclude([
				'test',
				'composer',
				'bin',
			])
			->notName('autoload.php')
			->in('vendor/firebase'),
	],
];
