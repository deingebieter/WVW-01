<?php

declare(strict_types=1);

namespace WrapperGenerator;

/**
 * SsrfGuard.php
 *
 * Prueft, ob ein Hostname auf eine oeffentlich erreichbare Adresse zeigt -
 * und nicht auf interne Netze, Loopback-Adressen oder Cloud-Metadaten-
 * Endpunkte (z.B. 169.254.169.254). Die URL wird zwar nur in die generierte
 * Android-App eingebettet und vom Server selbst nicht aufgerufen, trotzdem
 * ist diese Pruefung sinnvoll:
 *
 *   - schuetzt Nutzer der generierten App vor versehentlich internen Zielen
 *   - schuetzt den Server, falls kuenftig serverseitig auf die URL
 *     zugegriffen wird (z.B. Vorschau-Screenshot, Favicon-Download)
 *
 * Wichtig: Die Pruefung loest den Hostnamen tatsaechlich per DNS auf und
 * prueft die resultierenden IP-Adressen (nicht nur den Hostnamen selbst),
 * um simple DNS-Rebinding-Tricks zu erschweren.
 */
final class SsrfGuard
{
    public static function assertPublicHost(string $host): void
    {
        $host = strtolower($host);

        if (in_array($host, BLOCKED_HOSTNAMES, true)) {
            throw new ValidationException('Diese Adresse ist nicht erlaubt.');
        }

        foreach (BLOCKED_HOSTNAME_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new ValidationException('Diese Adresse ist nicht erlaubt.');
            }
        }

        // Falls der Host selbst schon eine IP-Adresse ist, direkt pruefen.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($host);
            return;
        }

        $resolvedIps = self::resolveAllIps($host);

        if (empty($resolvedIps)) {
            // Kein DNS-Eintrag gefunden - lehnen wir sicherheitshalber ab,
            // statt eine unklare Adresse durchzulassen.
            throw new ValidationException('Der Hostname konnte nicht aufgeloest werden.');
        }

        foreach ($resolvedIps as $ip) {
            self::assertPublicIp($ip);
        }
    }

    /**
     * @return string[]
     */
    private static function resolveAllIps(string $host): array
    {
        $ips = [];

        $recordsA = @dns_get_record($host, DNS_A);
        if (is_array($recordsA)) {
            foreach ($recordsA as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $recordsAAAA = @dns_get_record($host, DNS_AAAA);
        if (is_array($recordsAAAA)) {
            foreach ($recordsAAAA as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // Fallback, falls dns_get_record() (z.B. wegen restriktiver
        // PHP-Konfiguration) nichts liefert.
        if (empty($ips)) {
            $fallback = gethostbyname($host);
            if ($fallback !== $host) {
                $ips[] = $fallback;
            }
        }

        return array_unique($ips);
    }

    private static function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (BLOCKED_IPV4_RANGES as $range) {
                if (self::ipv4InCidr($ip, $range)) {
                    throw new ValidationException('Diese Adresse zeigt auf ein internes Netzwerk.');
                }
            }
            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                throw new ValidationException('Ungueltige IP-Adresse.');
            }

            // ::  (unspezifiziert) und ::1 (Loopback)
            if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1") {
                throw new ValidationException('Diese Adresse zeigt auf ein internes Netzwerk.');
            }

            // IPv4-mapped (::ffff:a.b.c.d) -> als IPv4 pruefen, sonst waere
            // z.B. ::ffff:127.0.0.1 ein Umweg um die IPv4-Sperrliste.
            if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
                self::assertPublicIp((string) inet_ntop(substr($packed, 12)));
                return;
            }

            // Normalisierte Langform, damit Praefix-Vergleiche zuverlaessig sind.
            $hex = bin2hex($packed);
            foreach (BLOCKED_IPV6_PREFIXES as $prefix) {
                if (str_starts_with($hex, $prefix)) {
                    throw new ValidationException('Diese Adresse zeigt auf ein internes Netzwerk.');
                }
            }
            return;
        }

        throw new ValidationException('Ungueltige IP-Adresse.');
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
