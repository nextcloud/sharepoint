<?php
/**
 * @copyright Copyright (c) 2019 Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OCA\SharePoint\Command;

use OCA\SharePoint\ClientFactory;
use OCA\SharePoint\ContextsFactory;
use Office365\PHP\Client\SharePoint\SPList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ListLibraries extends Command {

	/** @var ClientFactory */
	protected $clientFactory;
	/** @var ContextsFactory */
	protected $ctxFactory;

	public function __construct(ClientFactory $clientFactory, ContextsFactory $ctxFactory) {
		parent::__construct();
		$this->clientFactory = $clientFactory;
		$this->ctxFactory = $ctxFactory;
	}

	protected function configure() {
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

	protected function defaultOutput(OutputInterface $output, array $libraries, bool $json = false) {
		$rows = [];
		foreach ($libraries as $library) {
			$mdate = new \DateTime($library->getProperties()['LastItemModifiedDate']);
			$rows[] = [
				'title' => $library->getProperties()['Title'],
				'items' => $library->getProperties()['ItemCount'],
				'mdate' => date('Y-m-d H:i', $mdate->getTimestamp()),
			];
		}

		if($json) {
			$output->writeln(\json_encode($rows));
			return;
		}

		$table = new Table($output);
		$table->setHeaders(['Title', 'Items', 'Last modification']);
		$table->setRows($rows);
		$table->render();

	}

	protected function allPropertiesOutput(OutputInterface $output, array $libraries, bool $json = false) {
		if(empty($libraries)) {
			return;
		}

		$rows = [];
		$i = 0;

		/** @var SPList $library */
		foreach ($libraries as $library) {
			$props = $library->getProperties();
			$rows[$i] = [];

			$rows[$i]['Title'] = $props['Title'];
			unset($props['Title']);
			foreach ($props as $k => $v) {
				$rows[$i][$k] =  (is_object($v) || is_array($v)) ? '{object}' : $v;
			}
			$i++;
		}

		if($json) {
			$output->writeln(\json_encode($rows));
			return;
		}

		foreach($rows as $libraryData) {
			$table = new Table($output);
			$table->setHeaders(['Property', 'Value']);
			foreach($libraryData as $k => $v) {
				$table->addRow([$k, $v]);
			}
			$table->render();
		}

		return;
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$password = $input->getArgument('password');
		if($password === null) {
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');
			$question = new Question('Password: ');
			$question->setHidden(true);
			$password = $helper->ask($input, $output, $question);
			if($password === null) {
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

		if($input->getOption('all-properties')) {
			$this->allPropertiesOutput($output, $collection, $input->getOption('json'));
		} else {
			$this->defaultOutput($output, $collection, $input->getOption('json'));
		}

		return 0;
	}
}
