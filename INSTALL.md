# Installation

Diese Anleitung richtet den Challenge-Dienst so ein, dass ihn das Redaxo-Modul
**M20 Formular** nutzen kann. Rechnen Sie mit rund 20 Minuten.

Als Beispiel dient durchgehend die Subdomain `altcha.fclaenggasse.ch`; ersetzen Sie
sie durch Ihre eigene.

---

## 1. Voraussetzungen

| | |
|---|---|
| PHP | 8.2 oder neuer – entwickelt und geprüft mit **8.4** |
| PHP-Erweiterungen | `json`, `mbstring`, `openssl` (bei Standard-Installationen vorhanden) |
| Composer | 2.x |
| Webserver | Apache mit `mod_rewrite` oder nginx |
| HTTPS | Pflicht. Das Widget läuft auf einer HTTPS-Seite; ein Aufruf über `http://` bricht der Browser ab. |

Der Dienst braucht **keine Datenbank**. Er speichert nichts und setzt keine Cookies.

Prüfen, welche PHP-Version läuft:

```sh
php -v
```

---

## 2. Wohin der Dienst gehört

Zwei Wege. Der erste ist der einfachere.

### Variante A: eigene Subdomain (empfohlen)

Legen Sie eine Subdomain an, z. B. `altcha.fclaenggasse.ch`, und setzen Sie deren
**Document Root auf das Verzeichnis `public/`** dieses Projekts. Dann liegen `.env`,
`vendor/` und `src/` ausserhalb des Web-Verzeichnisses und sind von aussen nicht
erreichbar.

Die Challenge-URL lautet danach: `https://altcha.fclaenggasse.ch/challenge`

### Variante B: Unterverzeichnis einer bestehenden Domain

Geht auch, etwa `https://www.fclaenggasse.ch/altcha/`. Dabei zeigt der Document Root
auf das Projektverzeichnis selbst; die mitgelieferte `.htaccess` im Wurzelverzeichnis
leitet nach `public/` weiter.

Tragen Sie in diesem Fall in der `.env` zwingend den Pfad ein:

```ini
ALTCHA_BASE_PATH=/altcha
```

Ohne diese Zeile versucht der Dienst, den Pfad zu erraten – was hinter der
Umschreibung der `.htaccess` nicht zuverlässig gelingt und zu `404` führt.

Die Challenge-URL lautet dann: `https://www.fclaenggasse.ch/altcha/challenge`

---

## 3. Dateien einspielen

```sh
git clone https://github.com/apollo29/altcha-server.git
cd altcha-server
composer install --no-dev --optimize-autoloader
```

`--no-dev` lässt Entwicklungspakete weg, `--optimize-autoloader` macht den
Klassen-Lader schneller.

Steht auf dem Server kein Composer zur Verfügung, führen Sie die beiden Befehle
lokal aus und laden Sie anschliessend **das gesamte Verzeichnis samt `vendor/`** hoch.

---

## 4. Schlüssel erzeugen und `.env` anlegen

Der HMAC-Schlüssel ist das Herzstück: Mit ihm signiert dieser Dienst die Challenges,
und mit demselben Schlüssel prüft Redaxo die Lösungen. Stimmen die beiden nicht
überein, weist M20 **jede** Einsendung ab.

```sh
cp .env.example .env
openssl rand -hex 32
```

Tragen Sie die ausgegebene Zeichenkette in die `.env` ein:

```ini
ALTCHA_HMAC_KEY=8f3c…                       # die 64 Zeichen von openssl
ALTCHA_ALLOWED_ORIGINS=https://www.fclaenggasse.ch,https://fclaenggasse.ch
ALTCHA_EXPIRES=300
```

Zu `ALTCHA_ALLOWED_ORIGINS`: Tragen Sie jede Domain ein, auf der ein Formular steht –
**mit und ohne `www`**, falls beide erreichbar sind. Fehlt eine, blockiert der Browser
dort das Widget. Alle Einstellungen sind in [README.md](README.md#konfiguration)
erklärt.

Den Schlüssel danach schützen:

```sh
chmod 600 .env
```

> **Nicht in die Versionsverwaltung.** `.env` steht in der `.gitignore`. Sollte der
> Schlüssel je in einem Repository, einer E-Mail oder einem Chat gelandet sein,
> erzeugen Sie einen neuen – hier und in Redaxo.

---

## 5. Webserver einrichten

### Apache

`mod_rewrite` muss aktiv sein und `.htaccess`-Dateien müssen erlaubt sein
(`AllowOverride All`). Die nötigen Regeln liegen bereits bei.

Beispiel für einen vHost nach Variante A:

```apache
<VirtualHost *:443>
    ServerName altcha.fclaenggasse.ch
    DocumentRoot /var/www/altcha-server/public

    <Directory /var/www/altcha-server/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Bei Hosting-Paketen mit Klickoberfläche genügt es meist, beim Anlegen der Subdomain
als Zielverzeichnis `…/altcha-server/public` anzugeben.

### nginx

```nginx
server {
    listen 443 ssl;
    server_name altcha.fclaenggasse.ch;
    root /var/www/altcha-server/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # Nichts ausserhalb von public/ ausliefern.
    location ~ /\.(env|git) { deny all; }
}
```

---

## 6. Prüfen, ob es läuft

**a) Lebt der Dienst?**

```sh
curl https://altcha.fclaenggasse.ch/health
```

Erwartet: `{"status":"ok","algorithm":"SHA-256","maxnumber":50000,"expires":300}`

**b) Kommt eine Challenge?**

```sh
curl https://altcha.fclaenggasse.ch/challenge
```

Erwartet ein JSON mit `algorithm`, `challenge`, `maxnumber`, `salt`, `signature`.
Der `salt` muss ein `?expires=…&` enthalten – daran erkennt M20 das Ablaufdatum.

**c) Der eingebaute Selbsttest**

Er löst eine Challenge genau wie der Browser und prüft sie anschliessend mit
derselben Routine, die in M20 steckt:

```sh
php bin/selftest.php https://altcha.fclaenggasse.ch/challenge
```

Am Ende muss **«Alles bestanden.»** stehen. Steht dort etwas anderes, nennt die
fehlgeschlagene Zeile den Grund – arbeiten Sie den ab, bevor Sie weitergehen.

---

## 7. Redaxo-Modul M20 einstellen

Im Redaxo-Backend den Block **M20 Formular** öffnen, Abschnitt
*Spamschutz mit ALTCHA*:

| Feld | Wert |
|---|---|
| **Challenge-URL** | `https://altcha.fclaenggasse.ch/challenge` |
| **HMAC-Schlüssel** | derselbe Wert wie `ALTCHA_HMAC_KEY` in der `.env` |
| **Skript-URL** | leer lassen (lädt das Widget von jsDelivr) |

Beide ersten Felder müssen ausgefüllt sein. Ist nur die Challenge-URL gesetzt, bleibt
der Spamschutz aus – ein Widget, dessen Lösung niemand prüft, hält keinen Bot auf.
Das Backend weist darauf hin.

Danach die Seite im Frontend aufrufen: Über dem Absende-Knopf erscheint das
ALTCHA-Feld, das nach kurzer Rechenzeit von selbst auf «Verifiziert» springt.
Ein Testformular abschicken – kommt die Erfolgsmeldung, steht alles.

---

## 8. Wenn etwas klemmt

| Symptom | Ursache | Abhilfe |
|---|---|---|
| Widget meldet `Expected application/json, received text/html` | Die Anfrage erreicht die Anwendung nicht – siehe eigener Abschnitt unten | |
| `{"error":"Die Abhängigkeiten fehlen…"}` | `composer install` wurde nie ausgeführt | Schritt 3 nachholen |
| `{"error":"Dieser Dienst braucht PHP 8.2 oder neuer…"}` | Die Domain läuft auf einer älteren PHP-Version | Beim Hoster umstellen |
| `{"error":"ALTCHA_HMAC_KEY ist nicht gesetzt…"}` | `.env` fehlt, liegt am falschen Ort oder ist nicht lesbar | Die Datei gehört ins **Projektwurzelverzeichnis**, nicht nach `public/` |
| `{"error":"ALTCHA_HMAC_KEY ist zu kurz…"}` | Schlüssel unter 32 Zeichen | `openssl rand -hex 32` |
| `404` auf `/challenge` | `mod_rewrite` fehlt, `AllowOverride` verbietet `.htaccess`, oder Unterverzeichnis ohne `ALTCHA_BASE_PATH` | siehe Schritt 2 und 5 |
| Widget bleibt leer, Konsole meldet CORS | Domain fehlt in `ALTCHA_ALLOWED_ORIGINS` | Domain ergänzen, **mit Schema** und mit/ohne `www` |
| «Der Spamschutz konnte nicht bestätigt werden» im Formular | Schlüssel in `.env` und in M20 verschieden | beide vergleichen; nach einer Änderung Redaxo-Cache leeren |
| Dieselbe Meldung, aber erst nach langem Ausfüllen | Challenge abgelaufen | `ALTCHA_EXPIRES` erhöhen (z. B. `600`) |
| `500` ohne Text | PHP-Fehler | vorübergehend `ALTCHA_DEBUG=true` setzen, Meldung lesen, danach **wieder abschalten** |

Zur Fehlersuche hilft fast immer:

```sh
php bin/selftest.php https://altcha.fclaenggasse.ch/challenge
```

### «Expected application/json, received text/html»

Diese Meldung des Widgets heisst: Statt der Challenge kam eine HTML-Seite zurück. Der
Dienst selbst antwortet **immer** mit JSON, auch auf Fehler – die Anfrage hat ihn also
gar nicht erreicht, oder PHP ist vorher gescheitert.

Die eine Frage, die es klärt:

```sh
curl -i https://altcha.fclaenggasse.ch/challenge
```

Ordnen Sie die Antwort zu:

| Was zurückkommt | Was los ist |
|---|---|
| `Content-Type: application/json` und eine Challenge | Der Dienst ist in Ordnung. Dann stimmt die **Challenge-URL in M20** nicht mit der geprüften überein – Tippfehler, fehlendes `/challenge`, `http` statt `https`. |
| `301`/`302` auf eine andere Adresse | Das Widget folgt der Weiterleitung und landet auf einer HTML-Seite. Tragen Sie in M20 die **Zieladresse** ein, also mit/ohne `www` genau so, wie der Server sie haben will. |
| `404` mit HTML | Unter dieser Adresse liegt der Dienst nicht. Es antwortet der Webserver oder Redaxo. → Schritt 2 und 5: Document Root, `mod_rewrite`, `AllowOverride All`; im Unterverzeichnis zusätzlich `ALTCHA_BASE_PATH`. |
| `500` mit HTML | PHP bricht ab, bevor die Anwendung steht – meist eine fehlende Erweiterung. Fehlerprotokoll des Webservers lesen, notfalls `ALTCHA_DEBUG=true`. |
| Ein Verzeichnislisting oder die Startseite der Hauptdomain | Der Document Root zeigt nicht auf `public/`. → Schritt 2 |
| `{"error":"…"}` | Der Dienst läuft und sagt, was ihm fehlt – die Meldung nennt den Grund, siehe Tabelle oben. |

Der Selbsttest nimmt Ihnen diese Zuordnung ab und prüft gleich weiter:

```sh
php bin/selftest.php https://altcha.fclaenggasse.ch/challenge
```

---

## 9. Betrieb

**Updates einspielen:**

```sh
git pull
composer install --no-dev --optimize-autoloader
php bin/selftest.php
```

**Auf Sicherheitslücken in den Abhängigkeiten prüfen** – gelegentlich, etwa
vierteljährlich:

```sh
composer audit
```

**Den Schlüssel wechseln:** neuen Wert in die `.env` schreiben *und* gleichzeitig in
M20 eintragen. Zwischen den beiden Änderungen schlagen Einsendungen fehl; wechseln
Sie ihn deshalb ausserhalb der Stosszeiten.
