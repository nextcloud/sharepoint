<?php

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SharePoint\Command;

use OC\Core\Command\Base;
use OCA\SharePoint\ClientFactory;
use OCA\SharePoint\ContextsFactory;
use OCA\SharePoint\Vendor\Office365\SharePoint\SPList;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ListLibraries extends Base {

	/**
	 * from: egrep -o 'getProperty\("[^"]*"' vendor/vgrem/php-spo/src/SharePoint/SPList.php | egrep -o '"[^"]*"' | tr '"' "'"
	 * @var string[]
	 */
	public const ALL_PROPERTIES = [
		'AdditionalUXProperties',
		'AllowContentTypes',
		'AllowDeletion',
		'Author',
		'BaseTemplate',
		'BaseType',
		'BrowserFileHandling',
		'Color',
		'ContentTypes',
		'ContentTypes',
		'ContentTypesEnabled',
		'CrawlNonDefaultViews',
		'CreatablesInfo',
		'Created',
		'CurrentChangeToken',
		'CustomActionElements',
		'DataSource',
		'DefaultContentApprovalWorkflowId',
		'DefaultDisplayFormUrl',
		'DefaultEditFormUrl',
		'DefaultItemOpenInBrowser',
		'DefaultItemOpenUseListSetting',
		'DefaultNewFormUrl',
		'DefaultSensitivityLabelForLibrary',
		'DefaultView',
		'DefaultViewPath',
		'DefaultViewUrl',
		'Description',
		'DescriptionResource',
		'Direction',
		'DisableCommenting',
		'DisableGridEditing',
		'DocumentTemplateUrl',
		'DraftVersionVisibility',
		'EffectiveBasePermissions',
		'EffectiveBasePermissionsForUI',
		'EnableAssignToEmail',
		'EnableAttachments',
		'EnableFolderCreation',
		'EnableMinorVersions',
		'EnableModeration',
		'EnableRequestSignOff',
		'EnableVersioning',
		'EntityTypeName',
		'ExcludeFromOfflineClient',
		'ExemptFromBlockDownloadOfNonViewableFiles',
		'Fields',
		'FileSavePostProcessingEnabled',
		'ForceCheckout',
		'HasExternalDataSource',
		'Hidden',
		'Icon',
		'Id',
		'ImagePath',
		'ImageUrl',
		'InformationRightsManagementSettings',
		'InformationRightsManagementSettings',
		'IrmEnabled',
		'IrmExpire',
		'IrmReject',
		'IsApplicationList',
		'IsCatalog',
		'IsDefaultDocumentLibrary',
		'IsEnterpriseGalleryLibrary',
		'IsPrivate',
		'IsSiteAssetsLibrary',
		'IsSystemList',
		'ItemCount',
		'LastItemDeletedDate',
		'LastItemModifiedDate',
		'LastItemUserModifiedDate',
		'ListExperienceOptions',
		'ListFormCustomized',
		'ListItemEntityTypeFullName',
		'ListSchemaVersion',
		'MajorVersionLimit',
		'MajorWithMinorVersionsLimit',
		'MultipleDataList',
		'NoCrawl',
		'OnQuickLaunch',
		'PageRenderType',
		'ParentWeb',
		'ParentWeb',
		'ParentWebPath',
		'ParentWebUrl',
		'ParserDisabled',
		'ReadSecurity',
		'RootFolder',
		'SchemaXml',
		'ServerTemplateCanCreateFolders',
		'ShowHiddenFieldsInModernForm',
		'TemplateFeatureId',
		'TemplateTypeId',
		'Title',
		'TitleResource',
		'UserCustomActions',
		'ValidationFormula',
		'ValidationMessage',
		'Views',
		'WriteSecurity',
	];

	public function __construct(
		protected ClientFactory $clientFactory,
		protected ContextsFactory $ctxFactory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('sharepoint:list-libraries')
			->setDescription('List the available document libraries')
			->addArgument(
				'host',
				InputArgument::REQUIRED,
				'the url to the sharepoint server'
			)
			->addArgument(
				'login',
				InputArgument::REQUIRED,
				'the login name to authenticate with'
			)
			->addArgument(
				'password',
				InputArgument::OPTIONAL,
				'will be asked interactively if not provided as argument',
				null
			)
			->addOption(
				'all-properties',
				null,
				InputOption::VALUE_NONE,
				'print all properties of each document library'
			)
			->addOption(
				'json',
				null,
				InputOption::VALUE_NONE,
				'output in JSON instead of a table'
			);
	}

	protected function defaultOutput(OutputInterface $output, array $libraries, bool $json = false): void {
		$rows = [];
		foreach ($libraries as $library) {
			if (!$library instanceof SPList) {
				continue;
			}
			$mdate = new \DateTime($library->getProperty('LastItemModifiedDate'));
			$rows[] = [
				'title' => $library->getProperty('Title'),
				'items' => $library->getProperty('ItemCount'),
				'mdate' => date('Y-m-d H:i', $mdate->getTimestamp()),
			];
		}

		if ($json) {
			$output->writeln(\json_encode($rows));
			return;
		}

		$table = new Table($output);
		$table->setHeaders(['Title', 'Items', 'Last modification']);
		$table->setRows($rows);
		$table->render();
	}

	protected function allPropertiesOutput(OutputInterface $output, array $libraries, bool $json = false): void {
		if (empty($libraries)) {
			return;
		}

		$rows = [];
		$i = 0;

		/** @var SPList $library */
		foreach ($libraries as $library) {
			$rows[$i] = [];

			$rows[$i]['Title'] = $library->getProperty('Title');
			foreach (self::ALL_PROPERTIES as $propertyName) {
				$v = $library->getProperty($propertyName);
				$rows[$i][$propertyName] = (is_object($v) || is_array($v)) ? '{object}' : $v;
			}
			$i++;
		}

		if ($json) {
			$output->writeln(\json_encode($rows));
			return;
		}

		foreach ($rows as $libraryData) {
			$table = new Table($output);
			$table->setHeaders(['Property', 'Value']);
			foreach ($libraryData as $k => $v) {
				$table->addRow([$k, $v]);
			}
			$table->render();
		}
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$password = $input->getArgument('password');
		if ($password === null) {
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');
			$question = new Question('Password: ');
			$question->setHidden(true);
			$password = $helper->ask($input, $output, $question);
			if ($password === null) {
				$output->writeln('<error>Password required</error>');
				return 1;
			}
		}

		$client = $this->clientFactory->getClient(
			$this->ctxFactory,
			$input->getArgument('host'),
			[
				'user' => $input->getArgument('login'),
				'password' => $password,
			]
		);
		$collection = $client->getDocumentLibraries();

		if ($input->getOption('all-properties')) {
			$this->allPropertiesOutput($output, $collection, $input->getOption('json'));
		} else {
			$this->defaultOutput($output, $collection, $input->getOption('json'));
		}

		return 0;
	}
}
