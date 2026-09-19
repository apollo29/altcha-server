<?php

/**
 * Prüft, ob dieser Server Challenges ausgibt, die das Redaxo-Modul M20 annimmt.
 *
 *   php bin/selftest.php                      # prüft die Konfiguration im Prozess
 *   php bin/selftest.php http://localhost:3000/challenge   # prüft zusätzlich den laufenden Dienst
 *
 * Die Prüffunktion unten ist die aus M20 (output.php, $m20CheckAltcha) - Zeichen für
 * Zeichen dieselbe Logik. Läuft dieser Test durch, nimmt das Modul die Lösung an.
 */

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use AltchaOrg\AltchaStarterPhp\Config;

require __DIR__ . '/../vendor/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
}

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        ++$failures;
    }
    echo($ok ? "  OK   " : "  FEHL "), $label, ('' !== $detail ? ' - ' . $detail : ''), PHP_EOL;
}

/**
 * Die Prüfung aus M20. Sie löst die Challenge nicht, sondern bestätigt eine Lösung.
 */
function m20CheckAltcha(string $payload, string $key): bool
{
    $raw = base64_decode($payload, true);
    if (false === $raw) {
        return false;
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        return false;
    }
    foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $k) {
        if (!isset($d[$k]) || !is_scalar($d[$k])) {
            return false;
        }
    }
    $algo = strtolower(str_replace('-', '', (string) $d['algorithm']));
    if (!in_array($algo, ['sha256', 'sha384', 'sha512'], true)) {
        return false;
    }

    $salt = (string) $d['salt'];
    $pos  = strpos($salt, '?');
    if (false !== $pos) {
        parse_str(substr($salt, $pos + 1), $q);
        if (isset($q['expires']) && (int) $q['expires'] < time()) {
            return false;
        }
    }

    $expected = hash($algo, $salt . $d['number']);
    if (!hash_equals($expected, (string) $d['challenge'])) {
        return false;
    }

    return hash_equals(hash_hmac($algo, $expected, $key), (string) $d['signature']);
}

/**
 * Löst eine Challenge so, wie es das Widget im Browser tut: zählen, bis der Hash passt.
 *
 * @param array<string, mixed> $challenge
 */
function solve(array $challenge): ?int
{
    $algo = strtolower(str_replace('-', '', (string) $challenge['algorithm']));
    $max  = (int) ($challenge['maxnumber'] ?? 1000000);
    for ($n = 0; $n <= $max; ++$n) {
        if (hash_equals((string) $challenge['challenge'], hash($algo, $challenge['salt'] . $n))) {
            return $n;
        }
    }

    return null;
}

/**
 * Baut die Base64-Nutzlast, die das Widget in das Feld «altcha» legt.
 *
 * @param array<string, mixed> $challenge
 */
function payload(array $challenge, int $number): string
{
    return base64_encode((string) json_encode([
        'algorithm' => $challenge['algorithm'],
        'challenge' => $challenge['challenge'],
        'number'    => $number,
        'salt'      => $challenge['salt'],
        'signature' => $challenge['signature'],
    ]));
}

/**
 * @param array<string, mixed> $challenge
 */
function verifyChallenge(array $challenge, string $key, string $source): void
{
    echo PHP_EOL, $source, PHP_EOL;

    foreach (['algorithm', 'challenge', 'maxnumber', 'salt', 'signature'] as $field) {
        check('Feld «' . $field . '» vorhanden', isset($challenge[$field]));
    }
    if (!isset($challenge['algorithm'], $challenge['challenge'], $challenge['salt'], $challenge['signature'])) {
        return;
    }

    $algo = strtolower(str_replace('-', '', (string) $challenge['algorithm']));
    check('Algorithmus wird von M20 akzeptiert', in_array($algo, ['sha256', 'sha384', 'sha512'], true), (string) $challenge['algorithm']);

    $pos = strpos((string) $challenge['salt'], '?');
    $expires = null;
    if (false !== $pos) {
        parse_str(substr((string) $challenge['salt'], $pos + 1), $q);
        $expires = isset($q['expires']) ? (int) $q['expires'] : null;
    }
    check(
        'Salt enthält «expires»',
        null !== $expires,
        null === $expires ? 'ohne Ablauf bleibt eine Lösung unbegrenzt gültig' : ''
    );
    if (null !== $expires) {
        check('Ablauf liegt in der Zukunft', $expires > time(), 'in ' . ($expires - time()) . ' s');
    }

    // CVE-2025-68113: ohne Trennzeichen am Ende des Salts lässt sich eine Ziffer
    // aus «number» in den Salt schieben, ohne dass sich der Hash ändert - «expires»
    // wird dann grösser gelesen und die alte Lösung gilt weiter.
    check(
        'Salt endet mit «&» (Schutz vor Parameter-Splicing)',
        str_ends_with((string) $challenge['salt'], '&'),
        str_ends_with((string) $challenge['salt'], '&') ? '' : 'veraltete altcha-Bibliothek?'
    );

    $number = solve($challenge);
    check('Challenge ist lösbar', null !== $number, null !== $number ? 'Zahl ' . $number : 'nicht gefunden');
    if (null === $number) {
        return;
    }

    check('M20 nimmt die Lösung an', m20CheckAltcha(payload($challenge, $number), $key));
    check('M20 weist eine verfälschte Lösung ab', !m20CheckAltcha(payload($challenge, $number + 1), $key));
    check('M20 weist einen falschen Schlüssel ab', !m20CheckAltcha(payload($challenge, $number), $key . 'x'));

    // Der Angriff aus CVE-2025-68113 durch M20s Augen: eine Ziffer wandert vom
    // Ende des Salts an den Anfang der Zahl. Der Hash bleibt derselbe, die Lösung
    // gilt also weiter - entscheidend ist, dass sich «expires» dabei nicht
    // verlängern lässt. Dafür sorgt das «&» am Ende des Salts: die Ziffer landet
    // hinter dem Trennzeichen und damit in einem Parameter, den niemand liest.
    $digits = (string) $number;
    if (null !== $expires && strlen($digits) > 1) {
        $splicedSalt = $challenge['salt'] . substr($digits, 0, 1);
        parse_str(substr($splicedSalt, (int) strpos($splicedSalt, '?') + 1), $q);
        $splicedExpires = isset($q['expires']) ? (int) $q['expires'] : 0;

        check(
            'Ablauf lässt sich nicht durch Splicing verlängern',
            $splicedExpires <= $expires,
            $splicedExpires > $expires
                ? 'Ablauf liesse sich auf ' . date('d.m.Y', $splicedExpires) . ' schieben'
                : ''
        );
    }
}

try {
    $config = Config::fromEnv($_ENV + $_SERVER);
} catch (RuntimeException $e) {
    echo 'Konfiguration unbrauchbar: ', $e->getMessage(), PHP_EOL;
    exit(1);
}

echo 'Algorithmus: ', $config->algorithm, ' | maxnumber: ', $config->maxNumber,
     ' | Gültigkeit: ', $config->expires, ' s', PHP_EOL;

$local = (new Altcha($config->hmacKey))->createChallenge(new ChallengeOptions(
    algorithm: Algorithm::from($config->algorithm),
    maxNumber: $config->maxNumber,
    expires: new DateTimeImmutable('+' . $config->expires . ' seconds'),
));
verifyChallenge([
    'algorithm' => $local->algorithm,
    'challenge' => $local->challenge,
    'maxnumber' => $local->maxNumber,
    'salt'      => $local->salt,
    'signature' => $local->signature,
], $config->hmacKey, 'Challenge aus der Konfiguration:');

$url = $argv[1] ?? null;
if (null !== $url) {
    echo PHP_EOL, 'Laufender Dienst: ', $url, PHP_EOL;
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 10, 'header' => "Origin: https://www.fclaenggasse.ch\r\n", 'ignore_errors' => true],
    ]));
    $headers = $http_response_header ?? [];
    check('Antwort erhalten', false !== $body && '' !== (string) $body);
    if (false === $body) {
        exit($failures > 0 ? 1 : 0);
    }

    $joined = strtolower(implode("\n", $headers));
    check('Status 200', (bool) preg_match('~^http/\S+\s+200~i', $headers[0] ?? ''), $headers[0] ?? '');
    check('Content-Type ist JSON', false !== strpos($joined, 'application/json'));
    check('Access-Control-Allow-Origin gesetzt', false !== strpos($joined, 'access-control-allow-origin'));
    check('Antwort wird nicht zwischengespeichert', false !== strpos($joined, 'no-store'));

    $remote = json_decode((string) $body, true);
    check('Antwort ist JSON', is_array($remote));
    if (is_array($remote)) {
        verifyChallenge($remote, $config->hmacKey, 'Challenge vom Dienst:');
    }
}

echo PHP_EOL, 0 === $failures ? 'Alles bestanden.' : $failures . ' Prüfung(en) fehlgeschlagen.', PHP_EOL;
exit($failures > 0 ? 1 : 0);
