# ALTCHA Server

Selbst gehosteter Challenge-Dienst für das Redaxo-Modul **M20 Formular** aus
[fclg.redaxo](https://github.com/fclaenggasse/fclg.redaxo).

ALTCHA stellt dem Browser eine Rechenaufgabe, statt dem Besucher eine zu stellen:
kein Klicken, kein Bilderraten, keine Cookies von Dritten, keine Daten an Google.
Dieser Dienst gibt diese Aufgaben aus und signiert sie.

**Installation:** [INSTALL.md](INSTALL.md)

## Wie das Zusammenspiel aussieht

```
Besucher öffnet das Formular
        │
        │  1. Widget holt eine Challenge
        ▼
  GET /challenge  ─────────►  dieser Dienst
                              signiert sie mit ALTCHA_HMAC_KEY
        │
        │  2. Browser rechnet, bis der Hash passt
        │  3. Formular wird abgeschickt – die Lösung liegt im Feld «altcha»
        ▼
  Redaxo, Modul M20  ───►  prüft die Signatur selbst, mit demselben Schlüssel
```

Entscheidend ist der dritte Schritt: **Die Lösung wird in Redaxo geprüft, nicht
hier.** Beide Seiten brauchen denselben HMAC-Schlüssel, sonst weist M20 jede
Einsendung ab. Ein Endpunkt zum Absenden des Formulars ist deshalb nicht nötig –
die Formulardaten verlassen den Redaxo-Server nie.

## Endpunkte

| Methode | Pfad | Zweck |
|---|---|---|
| `GET` | `/challenge` | Neue Challenge für das Widget. Dieser Pfad gehört in M20 ins Feld «Challenge-URL». |
| `GET` | `/altcha` | Dasselbe unter dem Namen aus dem ALTCHA-Starter – damit bestehende Einträge weiter funktionieren. |
| `GET` | `/health` | Kurze Auskunft über Zustand und Einstellungen. Ohne Schlüssel. |

Antwort von `/challenge`:

```json
{
  "algorithm": "SHA-256",
  "challenge": "5c4fffc4…",
  "maxnumber": 50000,
  "salt": "fce99d4f17c0a8d846f738e9?expires=1789828361&",
  "signature": "c9e4508b…"
}
```

## Konfiguration

Alles über Umgebungsvariablen, wahlweise in einer `.env` im Wurzelverzeichnis
(Vorlage: [`.env.example`](.env.example)).

| Variable | Standard | Bedeutung |
|---|---|---|
| `ALTCHA_HMAC_KEY` | – | **Pflicht.** Mindestens 32 Zeichen. Derselbe Wert wie in M20, Feld «HMAC-Schlüssel». Erzeugen: `openssl rand -hex 32` |
| `ALTCHA_ALLOWED_ORIGINS` | alle | Kommagetrennte Liste der Domains, die das Widget einbinden dürfen, mit Schema (`https://www.example.ch`). Leer oder `*` erlaubt jede. |
| `ALTCHA_EXPIRES` | `300` | Gültigkeitsdauer einer Challenge in Sekunden (30–3600). |
| `ALTCHA_MAX_NUMBER` | `50000` | Rechenaufwand für den Browser (1000–1000000). Höher heisst teurer für Bots, aber auch langsamer für Besucher auf alten Geräten. |
| `ALTCHA_ALGORITHM` | `SHA-256` | `SHA-256` oder `SHA-512`. |
| `ALTCHA_BASE_PATH` | – | Nur nötig, wenn der Dienst in einem Unterverzeichnis liegt, z. B. `/altcha`. |
| `ALTCHA_DEBUG` | `false` | Fehlermeldungen im Klartext. Nur zur Fehlersuche. |

Eine Einstellung, die M20 nicht akzeptieren würde, lässt der Dienst nicht durchgehen:
Er antwortet dann mit einer Fehlermeldung, die den Grund nennt, statt Challenges
auszugeben, die später stillschweigend abgewiesen werden. `SHA-1` gilt als gebrochen
und wird abgelehnt.

## Selbsttest

```sh
php bin/selftest.php                                   # nur die Konfiguration
php bin/selftest.php https://altcha.example.ch/challenge   # samt laufendem Dienst
```

Der Test löst eine Challenge wie der Browser und prüft sie anschliessend mit
derselben Routine, die in M20 steckt (`output.php`, `$m20CheckAltcha`). Läuft er
durch, nimmt das Modul die Lösungen dieses Dienstes an. Zusätzlich prüft er, dass
eine verfälschte Lösung, ein falscher Schlüssel und der Splicing-Angriff aus
CVE-2025-68113 scheitern.

## Was der Dienst schützt – und was nicht

- Die Antworten tragen `Cache-Control: no-store`. Eine zwischengespeicherte Challenge
  wäre für alle Besucher dieselbe und beliebig oft verwendbar.
- Jede Challenge trägt ein Ablaufdatum im `salt`. M20 prüft es.
- Der `salt` endet mit `&`. Ohne dieses Trennzeichen liesse sich eine Ziffer aus der
  Lösung in den `salt` schieben, ohne dass sich der Hash ändert – das Ablaufdatum
  würde dann grösser gelesen und eine alte Lösung bliebe gültig
  ([CVE-2025-68113](https://github.com/advisories/GHSA-6gvq-jcmp-8959)). Dagegen hilft
  ausschliesslich eine aktuelle Bibliothek: `altcha-org/altcha` ab 1.3.1.
- `ALTCHA_ALLOWED_ORIGINS` hindert fremde Seiten daran, den Dienst im Browser ihrer
  Besucher mitzubenutzen. Es hindert niemanden daran, die Challenge per `curl` zu
  holen – das ist bei ALTCHA auch nicht der Punkt.

**Grenze:** ALTCHA verteuert das massenhafte Absenden, es macht es nicht unmöglich.
Wer einen Browser fernsteuert, löst die Aufgabe wie jeder andere. Gegen gezielte
Angriffe auf ein einzelnes Formular ist es kein Mittel.

Die Einmal-Verwendung einer Lösung prüft M20 in der Session des Besuchers, also nur
innerhalb eines Besuchs. Halten Sie `ALTCHA_EXPIRES` deshalb kurz – Minuten, nicht
Stunden.

## Anforderungen

PHP 8.2 oder neuer, entwickelt und geprüft mit **PHP 8.4**. Keine Datenbank.

## Dokumentation von ALTCHA

- [Server Integration](https://altcha.org/docs/server-integration/)
- [Widget](https://altcha.org/docs/website-integration/)

## Lizenz

MIT
