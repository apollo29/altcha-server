<?php

declare(strict_types=1);

namespace AltchaOrg\AltchaStarterPhp;

use RuntimeException;

/**
 * Konfiguration aus der Umgebung.
 *
 * Der Server hat genau eine Aufgabe: Challenges ausliefern, die das Redaxo-Modul
 * M20 gegenzeichnen kann. Deshalb ist alles, was M20 beim Prüfen voraussetzt, hier
 * fest verdrahtet oder eingegrenzt - eine Einstellung, die M20 nicht akzeptiert,
 * soll beim Start auffallen und nicht erst, wenn ein Besucher das Formular abschickt.
 */
final class Config
{
    /** M20 akzeptiert sha256, sha384 und sha512; die Bibliothek kann 256 und 512. */
    private const ALGORITHMS = ['SHA-256', 'SHA-512'];

    /** Kürzere Schlüssel sind zu leicht zu erraten - dann wäre die Signatur wertlos. */
    private const MIN_KEY_LENGTH = 32;

    /** Derselbe Schlüssel, der in M20 unter REX_VALUE[16] steht. */
    public string $hmacKey;

    public string $algorithm;

    public int $maxNumber;

    /** Gültigkeitsdauer einer Challenge in Sekunden. */
    public int $expires;

    /** @var string[] Leer heisst: jede Herkunft darf fragen. */
    public array $allowedOrigins;

    /** Unterverzeichnis, in dem der Dienst hängt; '' bei eigener Domain. */
    public string $basePath;

    public bool $debug;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $env
     *
     * @throws RuntimeException wenn der Server so keine für M20 brauchbaren Challenges ausgeben kann
     */
    public static function fromEnv(array $env): self
    {
        // Ob eine Variable in $_ENV landet, hängt von variables_order in der php.ini
        // ab - deshalb zusätzlich getenv() fragen, statt sich darauf zu verlassen.
        $read = static function (string $name) use ($env) {
            $value = $env[$name] ?? false;
            if (false === $value || '' === $value) {
                $value = getenv($name);
            }

            return false === $value ? null : $value;
        };

        $config = new self();

        $config->hmacKey = trim((string) ($read('ALTCHA_HMAC_KEY') ?? ''));
        if ('' === $config->hmacKey) {
            throw new RuntimeException(
                'ALTCHA_HMAC_KEY ist nicht gesetzt. Der Schlüssel muss derselbe sein wie im '
                . 'Redaxo-Modul M20 (Feld «HMAC-Schlüssel»), sonst verwirft M20 jede Lösung. '
                . 'Einen erzeugen: openssl rand -hex 32'
            );
        }
        if (strlen($config->hmacKey) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException(sprintf(
                'ALTCHA_HMAC_KEY ist zu kurz (%d Zeichen, mindestens %d nötig). '
                . 'Einen erzeugen: openssl rand -hex 32',
                strlen($config->hmacKey),
                self::MIN_KEY_LENGTH
            ));
        }

        $algorithm = strtoupper(trim((string) ($env['ALTCHA_ALGORITHM'] ?? 'SHA-256')));
        if ('' === $algorithm) {
            $algorithm = 'SHA-256';
        }
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new RuntimeException(sprintf(
                'ALTCHA_ALGORITHM=%s wird nicht unterstützt. Erlaubt sind: %s. '
                . '(SHA-1 gilt als gebrochen und wird von M20 zurückgewiesen.)',
                $algorithm,
                implode(', ', self::ALGORITHMS)
            ));
        }
        $config->algorithm = $algorithm;

        $config->maxNumber = self::clampInt($read('ALTCHA_MAX_NUMBER'), 50000, 1000, 1000000);
        $config->expires   = self::clampInt($read('ALTCHA_EXPIRES'), 300, 30, 3600);

        $config->allowedOrigins = self::parseOrigins((string) ($read('ALTCHA_ALLOWED_ORIGINS') ?? ''));
        $config->basePath = self::normalizeBasePath((string) ($read('ALTCHA_BASE_PATH') ?? ''));
        $config->debug = self::parseBool($read('ALTCHA_DEBUG'));

        return $config;
    }

    /**
     * Entscheidet, welcher Wert in Access-Control-Allow-Origin steht.
     *
     * @return string|null null heisst: diese Herkunft darf nicht
     */
    public function resolveOrigin(?string $origin): ?string
    {
        if ([] === $this->allowedOrigins) {
            return '*';
        }
        if (null === $origin || '' === $origin) {
            return null;
        }

        return in_array(strtolower($origin), $this->allowedOrigins, true) ? $origin : null;
    }

    private static function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        if (null === $value || '' === trim((string) $value)) {
            return $default;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $number) {
            return $default;
        }

        return max($min, min($max, $number));
    }

    private static function parseBool(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Macht aus «/altcha/» ein «/altcha» - und aus Leerem Leeres.
     */
    private static function normalizeBasePath(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw || '/' === $raw) {
            return '';
        }

        return '/' . trim($raw, '/');
    }

    /**
     * @return string[]
     */
    private static function parseOrigins(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw || '*' === $raw) {
            return [];
        }

        $origins = [];
        foreach (explode(',', $raw) as $origin) {
            $origin = strtolower(trim($origin));
            // Eine Herkunft ist Schema + Host + Port, ohne Pfad und ohne Schrägstrich am Ende.
            $origin = rtrim($origin, '/');
            if ('' !== $origin) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }
}
