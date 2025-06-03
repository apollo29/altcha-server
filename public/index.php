<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

use AltchaOrg\AltchaStarterPhp\CorsMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Slim\Factory\AppFactory;

/*
$request_headers = apache_request_headers();
$http_origin = $request_headers['Origin'];
$allowed_http_origins = array(
    "https://meinteam.be",
    "https://apollo29.com",
    "https://www.meinteam.be",
    "https://www.apollo29.com",
);
if (in_array($http_origin, $allowed_http_origins)) {
    @header("Access-Control-Allow-Origin: " . $http_origin);
}
*/

$app = AppFactory::create();

$app->addRoutingMiddleware();

$app->add(new BasePathMiddleware($app));

$app->add(new CorsMiddleware());

require __DIR__ . '/../src/routes.php';

$app->run();
