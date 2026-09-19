<?php

use AltchaOrg\AltchaStarterPhp\Config;
use AltchaOrg\AltchaStarterPhp\CorsMiddleware;
use Psr\Http\Message\ResponseInterface;
use Selective\BasePath\BasePathMiddleware;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Antwortet mit JSON und bricht ab - für Fehler, die auftreten, bevor die
 * Anwendung überhaupt steht.
 */
$fail = static function (string $message, int $status = 500): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// safeLoad: auf Servern, die die Werte selbst in die Umgebung legen, gibt es keine .env-Datei.
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

try {
    $config = Config::fromEnv($_ENV + $_SERVER);
} catch (RuntimeException $e) {
    // Eine falsche Einstellung schweigend zu übergehen hiesse, Challenges auszugeben,
    // die M20 niemals annimmt - der Grund gehört deshalb in die Antwort.
    $fail($e->getMessage());

    return;
}

$app = AppFactory::create();

$app->addRoutingMiddleware();

// Betrieb in einem Unterverzeichnis (z. B. /altcha/challenge). Ist der Pfad gesetzt,
// gilt er; sonst wird geraten - was hinter der Umschreibung in der .htaccess nicht
// immer gelingt, weshalb ALTCHA_BASE_PATH dort den Ausschlag gibt.
if ('' !== $config->basePath) {
    $app->setBasePath($config->basePath);
} else {
    $app->add(new BasePathMiddleware($app));
}

$errorMiddleware = $app->addErrorMiddleware($config->debug, true, true);
$errorMiddleware->setDefaultErrorHandler(
    static function ($request, Throwable $exception, bool $displayErrorDetails): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        $payload = ['error' => $status >= 500 && !$displayErrorDetails
            ? 'Serverfehler.'
            : $exception->getMessage()];

        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
);

// Zuoberst, damit auch Fehlerantworten die CORS-Kopfzeilen tragen - sonst sieht das
// Widget im Browser nur einen Netzwerkfehler ohne Grund.
$app->add(new CorsMiddleware($config));

require __DIR__ . '/../src/routes.php';

$app->run();
