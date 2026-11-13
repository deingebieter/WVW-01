# WebView-Wrapper – Build auf GitHub

Baut aus einer Website (Variante 1) oder aus eigenen HTML/CSS/JS-Dateien
(Variante 2) eine signierte Android-APK. Gebaut wird komplett auf GitHub
(GitHub Actions) – kein eigener Server nötig.

## APK bauen

1. Tab **Actions** öffnen → links **APK bauen** → **Run workflow**.
2. Felder ausfüllen:
   - **quelle**: `url` = Website laden, `ordner` = Dateien aus `web/` in die App packen
   - **url**: nur bei `url`, z. B. `https://beispiel.de`
   - **app_name**: Name unter dem Icon (max. 30 Zeichen)
   - **package_name**: leer lassen = automatisch
   - **anzeige**: `standalone` (Statusleiste sichtbar) oder `immersive` (Vollbild)
   - **farbe**: `#RRGGBB`, z. B. `#3C507D`
   - **icon**: optional, Pfad zu einem PNG/JPG im Repo, z. B. `icons/logo.png`
   - **release**: an = APK unter **Releases** direkt herunterladbar
3. Nach ca. 3–6 Minuten (erster Lauf etwas länger) ist der Build grün.
   APK unter **Releases** (direkt als .apk) oder im Build unter **Artifacts** (als .zip).

## Variante 2: eigene Dateien

Alles im Ordner `web/` wird in die App eingebaut; `web/index.html` ist die
Startseite. Die Seite läuft offline unter
`https://appassets.androidplatform.net/assets/www/`. Externe Links öffnen im
Browser, Aufrufe an eigene APIs per `fetch()` funktionieren (die API muss
dafür CORS erlauben).

## Optional: fester Signaturschlüssel (für Updates)

Ohne festen Schlüssel bekommt jede APK einen neuen Einmal-Schlüssel. Eine
neue Version lässt sich dann nur nach Deinstallation der alten installieren.
Für echte Updates unter **Settings → Secrets and variables → Actions** anlegen:

| Secret | Inhalt |
|---|---|
| `KEYSTORE_BASE64` | Keystore-Datei (.jks/PKCS12), base64-kodiert |
| `KEYSTORE_PASSWORD` | Passwort des Keystores (= Schlüssel-Passwort) |
| `KEY_ALIAS` | Alias des Schlüssels |

Den Schlüssel gut sichern – geht er verloren, sind keine Updates mehr möglich.

## Aufbau

```
.github/workflows/apk-bauen.yml   Der Build auf GitHub
android/                          Android-Template mit {{PLATZHALTERN}}
tools/generate.php                Füllt das Template (prüft alle Eingaben)
tools/lib/                        Generator/Validator aus dem PHP-Projekt
web/                              Inhalte für Variante 2
```

Hinweis: Bei einem öffentlichen Repository sind Releases öffentlich.
