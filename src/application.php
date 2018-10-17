<?php
// application.php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;

use \App\Command\CopyDatabaseCommand;

$application = new Application('roundcube-utils', '1.0.0');

$application->setCommandLoader(new \Symfony\Component\Console\CommandLoader\FactoryCommandLoader([
  'db:copy' => function () {
    return new CopyDatabaseCommand();
  },
]));

$application->run();
