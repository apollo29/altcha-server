<?php

/**
 * Endpunkte des Challenge-Dienstes.
 *
 * Das Redaxo-Modul M20 prüft die gelöste Challenge selbst - mit demselben
 * HMAC-Schlüssel, direkt beim Verarbeiten des Formulars. Der Server muss deshalb
 * nur Challenges ausgeben; einen Endpunkt zum Absenden des Formulars braucht es nicht.
 *
 * @var \Slim\App $app
 * @var \AltchaOrg\AltchaStarterPhp\Config $config
 */

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use AltchaOrg\AltchaStarterPhp\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @var Config $altchaConfig */
$altchaConfig = $config;

/**
 * Schreibt JSON in die Antwort.
 *
 * @param array<string, mixed> $data
 */
$json = static function (Response $response, array $data, int $status = 200): Response {
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $response->getBody()->write(false === $body ? '{"error":"Antwort konnte nicht erzeugt werden."}' : $body);

    return $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        // Eine zwischengespeicherte Challenge wäre für alle Besucher dieselbe und
        // liesse sich beliebig oft wiederverwenden.
        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('X-Content-Type-Options', 'nosniff');
};

/** Erzeugt eine frische Challenge für das Widget. */
$challenge = static function (Request $request, Response $response) use ($altchaConfig, $json): Response {
    try {
        $challenge = (new Altcha($altchaConfig->hmacKey))->createChallenge(new ChallengeOptions(
            algorithm: Algorithm::from($altchaConfig->algorithm),
            maxNumber: $altchaConfig->maxNumber,
            // Ohne Ablauf bliebe eine gelöste Challenge unbegrenzt gültig. M20 liest
            // den Zeitpunkt aus dem Salt und weist abgelaufene Lösungen zurück.
            expires: new DateTimeImmutable('+' . $altchaConfig->expires . ' seconds'),
        ));
    } catch (Throwable $e) {
        return $json($response, ['error' => 'Challenge konnte nicht erzeugt werden.'], 500);
    }

    // Kleingeschriebenes «maxnumber» - so erwartet es das Widget.
    return $json($response, [
        'algorithm' => $challenge->algorithm,
        'challenge' => $challenge->challenge,
        'maxnumber' => $challenge->maxNumber,
        'salt'      => $challenge->salt,
        'signature' => $challenge->signature,
    ]);
};

// Der Name, den M20 im Feld «Challenge-URL» vorschlägt.
$app->get('/challenge', $challenge);
// Der Name aus dem ALTCHA-Starter - bleibt bestehen, damit bereits eingetragene
// URLs nach einem Update nicht ins Leere zeigen.
$app->get('/altcha', $challenge);

/** Kurze Auskunft, ob der Dienst läuft und wie er eingestellt ist - ohne Schlüssel. */
$app->get('/health', static function (Request $request, Response $response) use ($altchaConfig, $json): Response {
    return $json($response, [
        'status'    => 'ok',
        'algorithm' => $altchaConfig->algorithm,
        'maxnumber' => $altchaConfig->maxNumber,
        'expires'   => $altchaConfig->expires,
    ]);
});

$app->get('/', static function (Request $request, Response $response) use ($json): Response {
    return $json($response, [
        'service'   => 'ALTCHA Challenge-Server',
        'endpoints' => ['/challenge', '/health'],
    ]);
});
