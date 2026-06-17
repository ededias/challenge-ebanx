<?php

declare(strict_types=1);

/**
 * Front controller: the single entry point for every HTTP request.
 *
 * Its only job is wiring and I/O -- pick a repository, build the controller,
 * hand it the raw request and emit the response. No business logic lives here.
 */

use Ebanx\Http\Controller;
use Ebanx\Repository\FileAccountRepository;
use Ebanx\Service\AccountService;

require __DIR__ . '/../vendor/autoload.php';

$storePath = getenv('EBANX_STORE_PATH') ?: sys_get_temp_dir() . '/ebanx-accounts.json';

$controller = new Controller(
    new AccountService(
        new FileAccountRepository($storePath),
    ),
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

$response = $controller->handle($method, $path, $_GET, file_get_contents('php://input') ?: '');

http_response_code($response->status);
header('Content-Type: ' . $response->contentType);
echo $response->body;
