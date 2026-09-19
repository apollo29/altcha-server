<?php

declare(strict_types=1);

namespace AltchaOrg\AltchaStarterPhp;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Das Widget holt die Challenge von einer anderen Domain als der Seite - ohne
 * CORS-Kopfzeilen bricht der Browser die Anfrage ab.
 *
 * Ist ALTCHA_ALLOWED_ORIGINS gesetzt, antwortet der Server nur den dort genannten
 * Herkünften. Das hält niemanden davon ab, die Challenge per curl zu holen, verhindert
 * aber, dass fremde Seiten den Dienst im Browser ihrer Besucher mitbenutzen.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $this->config->resolveOrigin('' === $origin ? null : $origin);

        // Vorabfrage: beantworten, ohne die Anwendung zu bemühen.
        if ('OPTIONS' === $request->getMethod()) {
            return $this->addHeaders(new SlimResponse(204), $allowed);
        }

        return $this->addHeaders($handler->handle($request), $allowed);
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        return $this->process($request, $handler);
    }

    private function addHeaders(Response $response, ?string $allowed): Response
    {
        // Die Antwort hängt von der Herkunft ab - das müssen Caches wissen.
        $response = $response->withHeader('Vary', 'Origin');

        if (null === $allowed) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowed)
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
