<?php

use AltchaOrg\AltchaStarterPhp\Config;
use AltchaOrg\AltchaStarterPhp\CorsMiddleware;
use Psr\Http\Message\ResponseInterface;
use Selective\BasePath\BasePathMiddleware;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;

/*
 * Alles bis zum Autoloader muss auch auf altem PHP lesbar bleiben: nur so kann
 * die Versionsprüfung unten überhaupt greifen. Keine Enums, kein readonly,
 * keine benannten Argumente vor dieser Stelle.
 */

// Eine HTML-Fehlerseite ist für das Widget wertlos - es bricht mit
// «Expected application/json, received text/html» ab und verschweigt den Grund.
// Fehler gehen deshalb ins Log und von hier aus als JSON hinaus.
ini_set('display_errors', '0');

/**
 * Antwortet mit JSON und bricht ab - für Fehler, die auftreten, bevor die
 * Anwendung überhaupt steht.
 *
 * @param string $message
 * @param int    $status
 */
function altcha_fail($message, $status = 500)
{
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Auch ein Fatal soll als JSON herauskommen, solange noch nichts gesendet wurde -
// etwa ein Syntaxfehler, weil PHP zu alt ist, oder eine fehlende Erweiterung.
register_shutdown_function(static function () {
    $error = error_get_last();
    if (null === $error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $debug = in_array(strtolower((string) getenv('ALTCHA_DEBUG')), ['1', 'true', 'yes', 'on'], true);
    altcha_fail($debug
        ? $error['message'] . ' (' . $error['file'] . ':' . $error['line'] . ')'
        : 'Der Dienst konnte nicht starten. Einzelheiten stehen im Fehlerprotokoll des Webservers; '
          . 'zur Fehlersuche ALTCHA_DEBUG=true setzen.');
});

if (PHP_VERSION_ID < 80200) {
    altcha_fail('Dieser Dienst braucht PHP 8.2 oder neuer, läuft aber auf ' . PHP_VERSION
        . '. Beim Hoster die PHP-Version der Domain umstellen.');

    return;
}

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    altcha_fail('Die Abhängigkeiten fehlen. Im Projektverzeichnis ausführen: '
        . 'composer install --no-dev --optimize-autoloader');

    return;
}

require __DIR__ . '/../vendor/autoload.php';

// safeLoad: auf Servern, die die Werte selbst in die Umgebung legen, gibt es keine .env-Datei.
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

try {
    $config = Config::fromEnv($_ENV + $_SERVER);
} catch (RuntimeException $e) {
    // Eine falsche Einstellung schweigend zu übergehen hiesse, Challenges auszugeben,
    // die M20 niemals annimmt - der Grund gehört deshalb in die Antwort.
    altcha_fail($e->getMessage());

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
