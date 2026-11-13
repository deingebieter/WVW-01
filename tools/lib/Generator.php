<?php

declare(strict_types=1);

namespace WrapperGenerator;

final class GeneratorException extends \RuntimeException
{
}

/**
 * Generator.php
 *
 * Nimmt das statische Android-Template (backend/templates/android) und
 * erzeugt daraus ein konkretes, kompilierbares Projekt in einem
 * Build-spezifischen Verzeichnis:
 *
 *   1. Template rekursiv in das Zielverzeichnis kopieren
 *   2. Platzhalter ({{PACKAGE_NAME}}, {{APP_NAME}}, ...) in Textdateien ersetzen
 *   3. Das Kotlin-Quellverzeichnis von com/wrapper/ in den echten
 *      Package-Pfad verschieben (z.B. de/beispiel/app/)
 *   4. Das App-Icon (falls hochgeladen) in alle mipmap-Dichten skalieren,
 *      sonst ein generiertes Platzhalter-Icon verwenden
 *   5. local.properties mit dem Pfad zum Android SDK schreiben
 */
final class Generator
{
    /**
     * Explizite Whitelist der Dateien, in denen Platzhalter ersetzt werden.
     * Bewusst KEINE Endungs-basierte Suche (z.B. "alle *.xml") mehr, damit
     * niemals versehentlich eine Datei veraendert wird, die nicht dafuer
     * vorgesehen ist (siehe auch assertSafeTemplatePath()). Pfade sind
     * relativ zu TEMPLATE_DIR / zum jeweiligen Build-Zielverzeichnis.
     *
     * Hinweis: MainActivity.kt liegt zum Zeitpunkt dieser Ersetzung noch an
     * ihrem urspruenglichen Template-Pfad (com/wrapper/...) - die
     * Verschiebung an den tatsaechlichen Package-Pfad passiert erst danach
     * in relocateJavaPackage().
     */
    private const TEMPLATE_TEXT_FILES = [
        'app/build.gradle',                                // nur PACKAGE_NAME + feste Zahlen
        'app/src/main/java/com/wrapper/MainActivity.kt',   // nur PACKAGE_NAME
        'app/src/main/res/values/colors.xml',              // nur THEME_COLOR (Whitelist)
        'app/src/main/res/values/strings.xml',             // APP_NAME (maskiert)
        'app/src/main/res/values/wrapper_config.xml',      // URL + Anzeigemodus (maskiert)
    ];

    private const MAX_TEMPLATE_TEXT_FILE_BYTES = 1024 * 1024; // 1 MB

    public static function generate(string $targetDir, array $params): void
    {
        self::copyDirectory(TEMPLATE_DIR, $targetDir);
        self::replacePlaceholders($targetDir, $params);
        self::relocateJavaPackage($targetDir, $params['packageName']);
        self::writeLocalProperties($targetDir);
        self::generateIcons($targetDir, $params['iconPath'] ?? null, $params['themeColor']);
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new GeneratorException("Template-Verzeichnis fehlt: $source");
        }
        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new GeneratorException("Konnte Zielverzeichnis nicht anlegen: $destination");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new GeneratorException("Konnte Template-Datei nicht kopieren: $relative");
                }
            }
        }
    }

    /**
     * Loest einen relativen Whitelist-Pfad sicher gegen ein Basisverzeichnis
     * auf: lehnt Nullbytes, ".." und Pfade ausserhalb des Basisverzeichnisses
     * ab. Schuetzt davor, dass ein manipulierter oder fehlerhafter Whitelist-
     * Eintrag (oder ein kompromittiertes Template) auf beliebige Dateien
     * ausserhalb des Build-Verzeichnisses zugreifen koennte.
     */
    private static function assertSafeTemplatePath(string $baseDir, string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
            throw new GeneratorException("Ungueltiger Template-Pfad: $relativePath");
        }

        $fullPath = $baseDir . '/' . $relativePath;
        $resolvedPath = realpath($fullPath);
        $resolvedBase = realpath($baseDir);

        if ($resolvedPath === false || $resolvedBase === false || !str_starts_with($resolvedPath, $resolvedBase . DIRECTORY_SEPARATOR)) {
            throw new GeneratorException("Template-Pfad liegt ausserhalb des erlaubten Verzeichnisses: $relativePath");
        }

        if (!is_file($resolvedPath)) {
            throw new GeneratorException("Erwartete Template-Datei fehlt: $relativePath");
        }

        if (filesize($resolvedPath) > self::MAX_TEMPLATE_TEXT_FILE_BYTES) {
            throw new GeneratorException("Template-Datei ist unerwartet gross: $relativePath");
        }

        return $resolvedPath;
    }

    private static function replacePlaceholders(string $dir, array $params): void
    {
        $replacements = [
            // Nutzerwerte gelangen NUR in XML-Ressourcen und werden dort
            // doppelt maskiert (Android-String-Regeln + XML). In Gradle-
            // bzw. Kotlin-Code landet ausschliesslich der streng
            // validierte Package-Name.
            '{{PACKAGE_NAME}}' => $params['packageName'],
            '{{APP_NAME}}' => self::androidStringResource($params['appName']),
            '{{WEBSITE_URL}}' => self::androidStringResource($params['websiteUrl']),
            '{{FULLSCREEN_MODE}}' => $params['fullscreenMode'],
            '{{SOURCE_MODE}}' => ($params['sourceMode'] ?? 'url') === 'local' ? 'local' : 'url',
            '{{THEME_COLOR}}' => $params['themeColor'],
            '{{VERSION_CODE}}' => (string) ($params['versionCode'] ?? 1),
            '{{VERSION_NAME}}' => (string) ($params['versionName'] ?? '1.0.0'),
            '{{MIN_SDK}}' => (string) ANDROID_MIN_SDK,
            '{{TARGET_SDK}}' => (string) ANDROID_TARGET_SDK,
            '{{COMPILE_SDK}}' => (string) ANDROID_COMPILE_SDK,
        ];

        foreach (self::TEMPLATE_TEXT_FILES as $relativePath) {
            $path = self::assertSafeTemplatePath($dir, $relativePath);
            $content = file_get_contents($path);
            if ($content === false) {
                throw new GeneratorException("Konnte Template-Datei nicht lesen: $relativePath");
            }
            $content = strtr($content, $replacements);
            if (file_put_contents($path, $content) === false) {
                throw new GeneratorException("Konnte Template-Datei nicht schreiben: $relativePath");
            }
        }
    }

    /**
     * Das Template legt MainActivity.kt unter java/com/wrapper/ ab.
     * Hier wird die Datei (inkl. "package"-Deklaration, die bereits durch
     * replacePlaceholders() ersetzt wurde) in den tatsaechlichen,
     * dem Package-Namen entsprechenden Ordnerpfad verschoben.
     */
    private static function relocateJavaPackage(string $targetDir, string $packageName): void
    {
        $javaRoot = $targetDir . '/app/src/main/java';
        $oldPath = $javaRoot . '/com/wrapper';
        $newRelative = str_replace('.', '/', $packageName);
        $newPath = $javaRoot . '/' . $newRelative;

        if (!is_dir($oldPath)) {
            throw new GeneratorException('Template-Quellordner com/wrapper nicht gefunden.');
        }

        if ($oldPath === $newPath) {
            return;
        }

        if (!mkdir($newPath, 0775, true) && !is_dir($newPath)) {
            throw new GeneratorException("Konnte Zielverzeichnis fuer Package nicht anlegen: $newPath");
        }
        foreach (glob($oldPath . '/*.kt') as $file) {
            if (!rename($file, $newPath . '/' . basename($file))) {
                throw new GeneratorException('Konnte Quelldatei nicht in den Package-Pfad verschieben: ' . basename($file));
            }
        }

        // leere alte Verzeichnisstruktur (com/wrapper, ggf. com/) aufraeumen
        if (count(scandir($oldPath) ?: []) <= 2) {
            rmdir($oldPath);
        }
        self::removeEmptyDirsUpwards(dirname($oldPath), $javaRoot);
    }

    private static function removeEmptyDirsUpwards(string $dir, string $stopAt): void
    {
        while (str_starts_with($dir, $stopAt) && $dir !== $stopAt) {
            $isEmpty = count(scandir($dir) ?: []) <= 2;
            if (!$isEmpty) {
                break;
            }
            rmdir($dir);
            $dir = dirname($dir);
        }
    }

    private static function writeLocalProperties(string $targetDir): void
    {
        $content = "sdk.dir=" . ANDROID_SDK_ROOT . "\n";
        if (file_put_contents($targetDir . '/local.properties', $content) === false) {
            throw new GeneratorException('Konnte local.properties nicht schreiben.');
        }
    }

    /**
     * Erzeugt aus dem hochgeladenen Icon (oder, falls keines vorhanden ist,
     * aus einem generierten Platzhalter mit der Theme-Farbe) alle
     * benoetigten mipmap-Dichten.
     */
    private static function generateIcons(string $targetDir, ?string $sourceIconPath, string $themeColor): void
    {
        $densities = [
            'mipmap-mdpi' => 48,
            'mipmap-hdpi' => 72,
            'mipmap-xhdpi' => 96,
            'mipmap-xxhdpi' => 144,
            'mipmap-xxxhdpi' => 192,
        ];

        $sourceImage = $sourceIconPath
            ? self::loadImage($sourceIconPath)
            : self::createPlaceholderIcon($themeColor);

        foreach ($densities as $folder => $size) {
            $resDir = $targetDir . '/app/src/main/res/' . $folder;
            if (!is_dir($resDir)) {
                mkdir($resDir, 0775, true);
            }

            // Legacy-Icon (fuer Launcher ohne Adaptive-Icon-Unterstuetzung)
            $resized = imagescale($sourceImage, $size, $size, IMG_BICUBIC);
            imagepng($resized, $resDir . '/ic_launcher.png');
            imagepng($resized, $resDir . '/ic_launcher_round.png');
            imagedestroy($resized);

            // Adaptive-Icon-Vordergrund: das eigentliche Motiv wird auf ca.
            // 66% verkleinert und zentriert, damit es von der Systemmaske
            // (Kreis/Rundrechteck/Quadrat, je nach Hersteller) nicht
            // angeschnitten wird (siehe mipmap-anydpi-v26/ic_launcher.xml).
            $foreground = self::createForegroundCanvas($sourceImage, $size);
            imagepng($foreground, $resDir . '/ic_launcher_foreground.png');
            imagedestroy($foreground);
        }

        imagedestroy($sourceImage);
    }

    /**
     * @param \GdImage $sourceImage
     * @return \GdImage
     */
    private static function createForegroundCanvas($sourceImage, int $size)
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        $innerSize = (int) round($size * 0.66);
        $offset = (int) round(($size - $innerSize) / 2);
        $resizedInner = imagescale($sourceImage, $innerSize, $innerSize, IMG_BICUBIC);
        imagecopy($canvas, $resizedInner, $offset, $offset, 0, 0, $innerSize, $innerSize);
        imagedestroy($resizedInner);

        return $canvas;
    }

    /**
     * @return \GdImage
     */
    private static function loadImage(string $path)
    {
        $info = getimagesize($path);
        $image = match ($info[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            default => throw new GeneratorException('Nicht unterstuetztes Bildformat.'),
        };
        imagesavealpha($image, true);
        return $image;
    }

    /**
     * Erstellt ein einfaches, markantes Platzhalter-Icon (abgerundetes
     * Quadrat in der gewaehlten Theme-Farbe mit hellem Kreis), falls der
     * Nutzer kein eigenes Icon hochlaedt.
     *
     * @return \GdImage
     */
    private static function createPlaceholderIcon(string $themeColor)
    {
        $size = 512;
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        [$r, $g, $b] = self::hexToRgb($themeColor);
        $bg = imagecolorallocate($image, $r, $g, $b);
        $fg = imagecolorallocatealpha($image, 255, 255, 255, 40);

        imagefilledroundedrectangle($image, 0, 0, $size - 1, $size - 1, 96, $bg);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.42), (int) ($size * 0.42), $fg);

        return $image;
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Maskiert einen Wert fuer den Inhalt eines Android-<string>-Elements:
     * zuerst die Android-Sonderzeichen (\\ ' "), dann XML (& < > ...).
     * Ein fuehrendes @ oder ? (Ressourcen-Referenz) wird ebenfalls maskiert.
     */
    private static function androidStringResource(string $value): string
    {
        $value = str_replace(['\\', "'", '"'], ['\\\\', "\\'", '\\"'], $value);
        if ($value !== '' && ($value[0] === '@' || $value[0] === '?')) {
            $value = '\\' . $value;
        }
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

/**
 * imagefilledroundedrectangle() ist erst ab PHP 8.3 in GD enthalten.
 * Fuer maximale Kompatibilitaet mit aelteren PHP-Versionen wird hier ein
 * einfacher Fallback definiert, falls die Funktion nicht existiert.
 */
if (!function_exists('imagefilledroundedrectangle')) {
    function imagefilledroundedrectangle($image, $x1, $y1, $x2, $y2, $radius, $color): bool
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        return true;
    }
}
