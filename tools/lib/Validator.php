<?php

declare(strict_types=1);

namespace WrapperGenerator;

final class ValidationException extends \RuntimeException
{
}

/**
 * Validiert und normalisiert alle Eingaben, bevor sie an Generator/BuildManager
 * weitergereicht werden. Da diese Werte spaeter in generierte Dateien
 * eingesetzt werden, wird streng per Whitelist geprueft.
 *
 * Dieselben Regeln werden im Worker ein ZWEITES Mal angewendet
 * (assertStoredJobIsSafe), da Datenbankeintraege veraltet oder manipuliert
 * sein koennen.
 */
final class Validator
{
    /**
     * Erlaubte Zeichen in einer normalisierten URL (RFC 3986 ohne das
     * Apostroph). Alles andere wird prozent-kodiert. Dadurch kann eine URL
     * NIE Zeichen wie ' " \ ` $ { } < > oder Leerzeichen enthalten, die in
     * generierten Dateien (XML, Kotlin, Gradle) als Syntax wirken koennten.
     */
    private const URL_SAFE_PATTERN = '#^https://[A-Za-z0-9\-._~:/?\#\[\]@!&()*+,;=%]+$#';

    /** Java- und Kotlin-Schluesselwoerter: als Package-Segment unzulaessig. */
    private const RESERVED_WORDS = [
        // Java
        'abstract', 'assert', 'boolean', 'break', 'byte', 'case', 'catch', 'char', 'class', 'const',
        'continue', 'default', 'do', 'double', 'else', 'enum', 'extends', 'final', 'finally', 'float',
        'for', 'goto', 'if', 'implements', 'import', 'instanceof', 'int', 'interface', 'long', 'native',
        'new', 'package', 'private', 'protected', 'public', 'return', 'short', 'static', 'strictfp',
        'super', 'switch', 'synchronized', 'this', 'throw', 'throws', 'transient', 'try', 'void',
        'volatile', 'while', 'true', 'false', 'null', 'var', 'record', 'yield', 'sealed', 'permits',
        // Kotlin (harte Schluesselwoerter)
        'as', 'fun', 'in', 'is', 'object', 'typealias', 'typeof', 'val', 'when',
    ];

    public static function validateBuildRequest(array $input): array
    {
        $websiteUrl = self::url($input['websiteUrl'] ?? '');

        return [
            'websiteUrl' => $websiteUrl,
            'appName' => self::appName($input['appName'] ?? ''),
            'packageName' => self::packageName($input['packageName'] ?? '', $websiteUrl),
            'fullscreenMode' => self::fullscreenMode($input['fullscreenMode'] ?? 'standalone'),
            'themeColor' => self::hexColor($input['themeColor'] ?? '#2563EB'),
        ];
    }

    /**
     * Zweite, unabhaengige Pruefung im Worker, direkt vor der Verwendung.
     * Ohne DNS-Aufloesung (die fand bereits beim Absenden statt), aber mit
     * allen Format- und Pfadpruefungen.
     */
    public static function assertStoredJobIsSafe(array $job): void
    {
        if (self::packageName((string) $job['package_name']) !== $job['package_name']) {
            throw new ValidationException('Gespeicherter Package-Name ist ungueltig.');
        }
        if (self::appName((string) $job['app_name']) !== $job['app_name']) {
            throw new ValidationException('Gespeicherter App-Name ist ungueltig.');
        }
        self::fullscreenMode((string) $job['fullscreen_mode']);
        self::hexColor((string) $job['theme_color']);

        $url = (string) $job['website_url'];
        if (strlen($url) > 2048 || !preg_match(self::URL_SAFE_PATTERN, $url)) {
            throw new ValidationException('Gespeicherte URL ist ungueltig.');
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ValidationException('Gespeicherte URL hat keinen Host.');
        }

        $icon = $job['icon_path'] ?? null;
        if ($icon !== null && $icon !== '') {
            if (!preg_match('#/icon-[a-f0-9]{32}\.png$#', (string) $icon)
                || !Security::isPathInside((string) $icon, UPLOADS_DIR)) {
                throw new ValidationException('Gespeicherter Icon-Pfad ist ungueltig.');
            }
        }
    }

    /**
     * Vollstaendige URL-Validierung:
     *   - nur https; andere Schemes (javascript:, file:, content:, ...) werden
     *     verworfen
     *   - keine Steuerzeichen, maximal 2048 Zeichen
     *   - alle potenziell gefaehrlichen Zeichen werden prozent-kodiert
     *   - keine eingebetteten Zugangsdaten, gueltiger Port
     *   - gueltiger Hostname oder IP, nicht intern/privat (SsrfGuard)
     */
    public static function url(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            throw new ValidationException('Bitte gib eine gueltige URL ein.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ValidationException('Die URL enthaelt ungueltige Steuerzeichen.');
        }

        // Explizit gefaehrliche Schemes sofort ablehnen, statt sie
        // "umzubiegen".
        if (preg_match('#^\s*(javascript|data|file|content|intent|vbscript|about|blob)\s*:#i', $value)) {
            throw new ValidationException('Nur https-Adressen sind erlaubt.');
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)) {
            if (!preg_match('#^https?://#i', $value)) {
                throw new ValidationException('Nur https-Adressen sind erlaubt.');
            }
            $value = preg_replace('#^https?://#i', '', $value);
        }
        $value = 'https://' . $value;
        $value = self::percentEncodeUnsafe($value);

        if (strlen($value) > 2048 || !preg_match(self::URL_SAFE_PATTERN, $value)) {
            throw new ValidationException('Diese URL sieht nicht gueltig aus.');
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ValidationException('Diese URL sieht nicht gueltig aus.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new ValidationException('Nur https-Adressen sind erlaubt.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('URLs mit eingebetteten Zugangsdaten sind nicht erlaubt.');
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            throw new ValidationException('Ungueltiger Port.');
        }

        $host = strtolower($parts['host']);
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new ValidationException('Ungueltiger Hostname (Umlaut-Domains bitte in Punycode, z.B. xn--...).');
        }
        if (!$isIp && !str_contains($host, '.')) {
            throw new ValidationException('Bitte gib eine vollstaendige Domain an (z.B. beispiel.de).');
        }

        SsrfGuard::assertPublicHost($host);

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('Diese URL sieht nicht gueltig aus.');
        }

        return $value;
    }

    /**
     * Prozent-kodiert jedes Byte ausserhalb des sicheren Zeichensatzes und
     * jedes "%", auf das keine zwei Hex-Ziffern folgen.
     */
    private static function percentEncodeUnsafe(string $url): string
    {
        $url = preg_replace('/%(?![0-9A-Fa-f]{2})/', '%25', $url);

        return preg_replace_callback(
            '#[^A-Za-z0-9\-._~:/?\#\[\]@!&()*+,;=%]#',
            static fn (array $m): string => rawurlencode($m[0]),
            $url
        );
    }

    public static function appName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new ValidationException('Bitte gib einen App-Namen ein.');
        }
        if (mb_strlen($value) > 30) {
            throw new ValidationException('Der App-Name darf maximal 30 Zeichen lang sein.');
        }
        // Keine Anfuehrungszeichen, Backslashes, @ oder ? am Anfang (die in
        // Android-String-Ressourcen Sonderbedeutung haben).
        if (!Security::matchesWhitelist($value, '/^[\p{L}\p{N}][\p{L}\p{N} .:_&\-]{0,29}$/u')) {
            throw new ValidationException('Der App-Name enthaelt nicht erlaubte Zeichen.');
        }
        return $value;
    }

    /**
     * Package-Name: striktes Format, Mindest-/Maximallaenge, keine
     * Java-/Kotlin-Schluesselwoerter. Sicherheitskritisch, da der Name in
     * Dateipfade uebersetzt wird (Generator::relocateJavaPackage).
     */
    public static function packageName(string $value, string $fallbackUrl = ''): string
    {
        $value = strtolower(trim($value));

        if ($value === '' && $fallbackUrl !== '') {
            $value = self::derivePackageNameFromUrl($fallbackUrl);
        }

        $length = strlen($value);
        if ($length < PACKAGE_NAME_MIN_LENGTH || $length > PACKAGE_NAME_MAX_LENGTH) {
            throw new ValidationException(
                'Der Package-Name muss zwischen ' . PACKAGE_NAME_MIN_LENGTH .
                ' und ' . PACKAGE_NAME_MAX_LENGTH . ' Zeichen lang sein.'
            );
        }

        if (!Security::matchesWhitelist($value, PACKAGE_NAME_PATTERN)) {
            throw new ValidationException(
                'Der Package-Name muss dem Format "com.beispiel.appname" entsprechen ' .
                '(nur Kleinbuchstaben und Ziffern, jedes Segment beginnt mit einem Buchstaben, ' .
                'mindestens zwei Segmente).'
            );
        }

        foreach (explode('.', $value) as $segment) {
            if (in_array($segment, self::RESERVED_WORDS, true)) {
                throw new ValidationException("Das Segment \"$segment\" ist ein reserviertes Wort und nicht erlaubt.");
            }
        }

        return $value;
    }

    public static function derivePackageNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'app';
        $host = preg_replace('/^www\./', '', strtolower($host));
        $parts = array_values(array_filter(preg_split('/[.\-]+/', $host), fn ($p) => $p !== ''));

        $parts = array_map(function (string $p): string {
            $p = preg_replace('/[^a-z0-9]/', '', $p);
            if ($p === '') {
                $p = 'app';
            }
            if (!preg_match('/^[a-z]/', $p)) {
                $p = 'a' . $p;
            }
            // z.B. ".in", ".do", ".is", ".as" (Laender-Domains) waeren als
            // Package-Segment ein Schluesselwort und wuerden den Build brechen.
            if (in_array($p, self::RESERVED_WORDS, true)) {
                $p .= 'app';
            }
            return $p;
        }, $parts);

        if (count($parts) < 2) {
            $parts = array_merge(['wrapper'], $parts);
        }

        $reversed = array_reverse($parts);
        $reversed[] = 'app';
        $candidate = implode('.', $reversed);

        if (strlen($candidate) > PACKAGE_NAME_MAX_LENGTH) {
            $candidate = substr($candidate, 0, PACKAGE_NAME_MAX_LENGTH);
            $candidate = rtrim($candidate, '.');
        }
        if (strlen($candidate) < PACKAGE_NAME_MIN_LENGTH) {
            $candidate = 'de.wrapper.app';
        }

        return $candidate;
    }

    public static function fullscreenMode(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, ['standalone', 'immersive'], true)) {
            throw new ValidationException('Ungueltiger Anzeigemodus.');
        }
        return $value;
    }

    public static function hexColor(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!Security::matchesWhitelist($value, '/^#[0-9A-F]{6}$/')) {
            throw new ValidationException('Ungueltige Farbe.');
        }
        $allowed = array_merge(...array_values(THEME_COLOR_PALETTE));
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException('Bitte waehle eine der vorgegebenen Farben.');
        }
        return $value;
    }

    /**
     * Produkt-Pruefung des Icon-Uploads (Format, Groesse, quadratisch).
     * Die sicherheitskritische Neu-Kodierung uebernimmt ImageProcessor.
     */
    public static function icon(?array $file): ?array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (is_array($file['error'] ?? null)) {
            throw new ValidationException('Bitte nur ein einzelnes Icon hochladen.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Der Icon-Upload ist fehlgeschlagen.');
        }
        if ($file['size'] > MAX_ICON_UPLOAD_BYTES) {
            throw new ValidationException('Das Icon darf maximal 2 MB gross sein.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException('Ungueltiger Datei-Upload.');
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new ValidationException('Die Datei ist kein gueltiges Bild.');
        }
        [$width, $height, $type] = $imageInfo;
        if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new ValidationException('Bitte lade ein PNG- oder JPEG-Bild hoch.');
        }
        if ($width < 192 || $height < 192) {
            throw new ValidationException('Das Icon sollte mindestens 192x192 Pixel gross sein.');
        }
        if ($width > ICON_MAX_DIMENSION_PX || $height > ICON_MAX_DIMENSION_PX) {
            throw new ValidationException('Das Icon ist zu gross (maximal ' . ICON_MAX_DIMENSION_PX . 'px).');
        }
        if ($width !== $height) {
            throw new ValidationException('Das Icon sollte quadratisch sein.');
        }

        return $file;
    }
}
