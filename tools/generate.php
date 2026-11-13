<?php
/**
 * generate.php
 *
 * Erzeugt aus dem Template in android/ ein fertiges Android-Projekt.
 * Wird vom GitHub-Workflow (.github/workflows/apk-bauen.yml) aufgerufen.
 * Die Eingaben kommen ausschliesslich ueber Umgebungsvariablen (nie direkt
 * in den Shell-Befehl eingesetzt), damit niemand ueber ein Eingabefeld
 * Befehle einschleusen kann.
 *
 *   IN_QUELLE        url | ordner
 *   IN_URL           Website-Adresse (nur bei url)
 *   IN_APP_NAME      App-Name
 *   IN_PACKAGE_NAME  optional, sonst automatisch
 *   IN_ANZEIGE       standalone | immersive
 *   IN_FARBE         #RRGGBB
 *   IN_ICON          optional: Pfad zu PNG/JPG im Repository
 *   IN_VERSION_CODE  ganze Zahl (Build-Nummer)
 *
 * Aufruf: php tools/generate.php <Zielordner>
 */

declare(strict_types=1);

namespace WrapperGenerator;

$repoRoot = dirname(__DIR__);

// ---------------------------------------------------------------------
// Konstanten, die die uebernommenen Klassen erwarten (frueher config.php)
// ---------------------------------------------------------------------
define('TEMPLATE_DIR', $repoRoot . '/android');
define('ANDROID_SDK_ROOT', getenv('ANDROID_HOME') ?: (getenv('ANDROID_SDK_ROOT') ?: '/usr/local/lib/android/sdk'));
define('ANDROID_COMPILE_SDK', 34);
define('ANDROID_TARGET_SDK', 34);
define('ANDROID_MIN_SDK', 24);
define('PACKAGE_NAME_PATTERN', '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*){1,}$/');
define('PACKAGE_NAME_MIN_LENGTH', 3);
define('PACKAGE_NAME_MAX_LENGTH', 150);
define('ICON_MAX_DIMENSION_PX', 4096);
define('BLOCKED_HOSTNAMES', ['localhost']);
define('BLOCKED_HOSTNAME_SUFFIXES', ['.local', '.internal']);
define('BLOCKED_IPV4_RANGES', [
    '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16',
    '0.0.0.0/8', '100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
]);
define('BLOCKED_IPV6_PREFIXES', ['fc', 'fd', 'fe8', 'fe9', 'fea', 'feb', 'ff']);

/** Startadresse fuer Variante 2 (siehe WebViewAssetLoader in MainActivity.kt). */
const LOCAL_START_URL = 'https://appassets.androidplatform.net/assets/www/index.html';
const WEB_DIR = 'web';

require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/SsrfGuard.php';
require __DIR__ . '/lib/Validator.php';
require __DIR__ . '/lib/Generator.php';

function fail(string $message): never
{
    // ::error:: erscheint auf GitHub als rote Meldung oben im Build.
    fwrite(STDERR, "::error::$message\n");
    exit(1);
}

function env(string $name): string
{
    return trim((string) getenv($name));
}

/** Package-Name fuer Variante 2 aus dem App-Namen ableiten (keine URL vorhanden). */
function packageFromAppName(string $appName): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $appName) ?: ''));
    if ($slug === '') {
        $slug = 'app';
    }
    if (!preg_match('/^[a-z]/', $slug)) {
        $slug = 'a' . $slug;
    }
    $slug = substr($slug, 0, 40);
    // Endung "app" schliesst Java-/Kotlin-Schluesselwoerter wie "new" aus.
    return 'com.wrapper.' . (str_ends_with($slug, 'app') ? $slug : $slug . 'app');
}

function iconPath(string $repoRoot, string $relative): ?string
{
    if ($relative === '') {
        return null;
    }
    if (str_contains($relative, "\0") || str_contains($relative, '..')) {
        fail('Ungueltiger Icon-Pfad.');
    }
    $path = realpath($repoRoot . '/' . ltrim($relative, '/'));
    if ($path === false || !is_file($path) || !str_starts_with($path, $repoRoot . '/')) {
        fail("Icon nicht gefunden: $relative (Pfad relativ zum Repository angeben, z. B. icons/logo.png)");
    }
    $info = @getimagesize($path);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        fail('Das Icon muss eine PNG- oder JPG-Datei sein.');
    }
    if ($info[0] > ICON_MAX_DIMENSION_PX || $info[1] > ICON_MAX_DIMENSION_PX) {
        fail('Das Icon ist zu gross (maximal 4096 x 4096 Pixel).');
    }
    return $path;
}

/** Kopiert den Ordner web/ (ohne versteckte Dateien und Links) nach assets/www. */
function copyWebFiles(string $source, string $target): int
{
    if (!is_file($source . '/index.html')) {
        fail('Fuer quelle = ordner muss im Repository die Datei web/index.html existieren.');
    }
    $count = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        /** @var \SplFileInfo $item */
        $relative = substr($item->getPathname(), strlen($source) + 1);
        if ($item->isLink() || preg_match('#(^|/)\.#', $relative)) {
            continue;
        }
        $dest = $target . '/' . $relative;
        if ($item->isDir()) {
            is_dir($dest) || mkdir($dest, 0775, true);
        } else {
            is_dir(dirname($dest)) || mkdir(dirname($dest), 0775, true);
            copy($item->getPathname(), $dest) || fail("Konnte $relative nicht kopieren.");
            $count++;
        }
    }
    return $count;
}

// ---------------------------------------------------------------------
// Eingaben pruefen
// ---------------------------------------------------------------------
$targetDir = $argv[1] ?? '';
if ($targetDir === '' || file_exists($targetDir)) {
    fail('Aufruf: php tools/generate.php <neuer Zielordner>');
}

$quelle = env('IN_QUELLE') ?: 'url';
if (!in_array($quelle, ['url', 'ordner'], true)) {
    fail('quelle muss "url" oder "ordner" sein.');
}

try {
    $appName = Validator::appName(env('IN_APP_NAME'));
    $fullscreenMode = Validator::fullscreenMode(env('IN_ANZEIGE') ?: 'standalone');

    if ($quelle === 'url') {
        if (env('IN_URL') === '') {
            fail('Bei quelle = url bitte eine Website-Adresse angeben.');
        }
        $websiteUrl = Validator::url(env('IN_URL'));
        $packageName = Validator::packageName(env('IN_PACKAGE_NAME'), $websiteUrl);
    } else {
        $websiteUrl = LOCAL_START_URL;
        $packageName = Validator::packageName(env('IN_PACKAGE_NAME') ?: packageFromAppName($appName));
    }
} catch (ValidationException $e) {
    fail($e->getMessage());
}

$themeColor = strtoupper(env('IN_FARBE') ?: '#3C507D');
if (!preg_match('/^#[0-9A-F]{6}$/', $themeColor)) {
    fail('Die Farbe muss im Format #RRGGBB angegeben werden, z. B. #3C507D.');
}

$versionCode = (int) (env('IN_VERSION_CODE') ?: '1');
if ($versionCode < 1 || $versionCode > 2100000000) {
    fail('Ungueltige Versionsnummer.');
}

// ---------------------------------------------------------------------
// Projekt erzeugen
// ---------------------------------------------------------------------
try {
    Generator::generate($targetDir, [
        'appName' => $appName,
        'packageName' => $packageName,
        'websiteUrl' => $websiteUrl,
        'fullscreenMode' => $fullscreenMode,
        'themeColor' => $themeColor,
        'sourceMode' => $quelle === 'ordner' ? 'local' : 'url',
        'iconPath' => iconPath($repoRoot, env('IN_ICON')),
        'versionCode' => $versionCode,
        'versionName' => '1.0.' . $versionCode,
    ]);
} catch (GeneratorException $e) {
    fail($e->getMessage());
}

$fileCount = 0;
if ($quelle === 'ordner') {
    $fileCount = copyWebFiles($repoRoot . '/' . WEB_DIR, $targetDir . '/app/src/main/assets/www');
}

// Werte fuer die naechsten Workflow-Schritte
$output = getenv('GITHUB_OUTPUT');
if ($output) {
    file_put_contents($output, "package_name=$packageName\nversion_name=1.0.$versionCode\n", FILE_APPEND);
}

echo "Projekt erzeugt: $packageName\n";
echo $quelle === 'url' ? "Variante 1: laedt $websiteUrl\n" : "Variante 2: $fileCount Dateien aus web/ eingebaut\n";
