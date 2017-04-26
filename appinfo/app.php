<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new \OCA\SharePoint\AppInfo\Application();
$app->registerBackendProvider();
