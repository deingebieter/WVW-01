<?php

declare(strict_types=1);

namespace WrapperGenerator;

final class Security
{
    public static function csrfToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $submitted): bool
    {
        self::ensureSession();
        if (!is_string($submitted) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => self::isHttps(),
                'path' => '/',
            ]);
            session_start();
        }
    }

    private static function isFromTrustedProxy(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES, true);
    }

    /**
     * X-Forwarded-Proto wird NUR ausgewertet, wenn die Anfrage direkt von
     * einem in TRUSTED_PROXIES eingetragenen Proxy kommt. Sonst koennte ein
     * Client den Header selbst setzen.
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::isFromTrustedProxy()
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * Client-IP fuer das Rate-Limiting. Hinter einem vertrauenswuerdigen
     * Proxy wird der rechteste nicht-vertrauenswuerdige Eintrag aus
     * X-Forwarded-For verwendet (vom Client selbst gesetzte Eintraege
     * stehen weiter links und werden damit ignoriert).
     */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!self::isFromTrustedProxy() || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!in_array($ip, TRUSTED_PROXIES, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    public static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // script-src ohne 'unsafe-inline': Daten wie die Farbpalette werden
        // deshalb per data-Attribut uebergeben (siehe index.php/app.js).
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "script-src 'self'; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src https://fonts.gstatic.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self'; " .
            "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        );
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function randomId(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function matchesWhitelist(string $value, string $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Prueft, dass $path eine existierende Datei/ein Verzeichnis INNERHALB
     * von $baseDir ist (nach Aufloesung aller Symlinks und ".."). Wird vor
     * jedem Loeschen/Lesen von Pfaden verwendet, die aus der Datenbank
     * stammen - eine manipulierte DB darf so keine beliebigen Dateien
     * loeschen oder ausliefern lassen.
     */
    public static function isPathInside(string $path, string $baseDir): bool
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            return false;
        }
        $realBase = realpath($baseDir);
        $realPath = realpath($path);
        if ($realBase === false || $realPath === false) {
            return false;
        }
        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR);
    }
}
