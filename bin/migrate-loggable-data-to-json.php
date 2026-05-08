#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Gedmo\Loggable\Command\MigrateDataToJsonCommand;
use Symfony\Component\Console\Application;

$autoloadCandidates = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../vendor/autoload.php',
    __DIR__.'/../../../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "Could not find the Composer autoloader. Run 'composer install' first.\n");
    exit(1);
}

$application = new Application('DoctrineExtensions Loggable Migration');
$command = new MigrateDataToJsonCommand();
$application->add($command);
$application->setDefaultCommand((string) $command->getName(), true);
$application->run();
