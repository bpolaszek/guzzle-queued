Guzzle Queued Client
======================

This library allows you to send asynchronous requests via Guzzle 6, using a queue system (only Beanstalk supported for the moment).

Installation
------------

`composer require bentools/guzzle-queued`


Usage
-----
```php
use BenTools\GuzzleQueued\Client as QueuedClient;
use GuzzleHttp\Psr7\Request as PSR7Request;
use Pheanstalk\Pheanstalk;

require_once __DIR__ . '/../vendor/autoload.php';

$pheanstalk = new Pheanstalk('127.0.0.1');
$guzzle     = new \GuzzleHttp\Client();
$client     = new QueuedClient($guzzle, $pheanstalk);
$request    = new PSR7Request('GET', 'http://httpbin.org/user-agent');
$promise    = $client->sendAsync($request)->then(function (\Psr\Http\Message\ResponseInterface $response) {
    echo (string) $response->getBody();
});

$promise->wait(); // Now the hard work has to be done by a separate PHP process
```

Launch as many workers as needed with the following command:
```
php vendor/bin/guzzle-request-worker.php &
```

Event Dispatcher
----------------------

Create your own worker and hook to the following events:

* \BenTools\GuzzleQueued\JobEvent::BEFORE_PROCESS: before the request is processed
* \BenTools\GuzzleQueued\JobEvent::AFTER_PROCESS: after the request is processed
* \BenTools\GuzzleQueued\JobEvent::ERROR: if an error occured

Change response, prevent the request from being effectively sent, delay the request, ...