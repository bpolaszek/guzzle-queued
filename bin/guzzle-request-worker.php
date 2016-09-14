<?php

use Pheanstalk\PheanstalkInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\EventDispatcher;

$tryAutoloaders = [
    __DIR__ . '/../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($tryAutoloaders AS $autoloader) {
    if (file_exists($autoloader)) {
        require $autoloader;
        break;
    }
}


$input = new ArgvInput(null, new InputDefinition([
    new InputOption('host', 'bh', InputOption::VALUE_OPTIONAL, 'Beanstalk host', '127.0.0.1'),
    new InputOption('port', 'bp', InputOption::VALUE_OPTIONAL, 'Beanstalk port', PheanstalkInterface::DEFAULT_PORT),
    new InputOption('connect-timeout', 'bct', InputOption::VALUE_OPTIONAL, 'Beanstalk connect timeout', null),
    new InputOption('connect-persistent', 'bcp', InputOption::VALUE_OPTIONAL, 'Beanstalk connect persistent', false),
]));

$guzzle     = new \GuzzleHttp\Client();
$pheanstalk = new \Pheanstalk\Pheanstalk($input->getOption('host'), $input->getOption('port'), $input->getOption('connect-timeout'), $input->getOption('connect-persistent'));
$worker     = new \BenTools\GuzzleQueued\Worker($guzzle, $pheanstalk, new EventDispatcher());

$worker->loop();