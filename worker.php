<?php
/**
 * SpotiDown Worker
 * Ejecutado en background por api.php
 * Descarga toda la playlist usando spotdl y crea un ZIP
 *
 * Uso: php worker.php <job_id> <spotify_url>
 */

define('SPOTDL_CMD',   __DIR__ . '/.venv/Scripts/spotdl');
define('DOWNLOADS_DIR', __DIR__ . '/downloads/');
define('JOBS_DIR',      __DIR__ . '/jobs/');
define('AUDIO_FORMAT',  'mp3');
define('AUDIO_QUALITY', '320k');

// Cargar configuración de credenciales
if (file_exists(__DIR__ . '/.env.php')) {
    include_once __DIR__ . '/.env.php';
}
if (!defined('SPOTIPY_CLIENT_ID')) define('SPOTIPY_CLIENT_ID', '');
if (!defined('SPOTIPY_CLIENT_SECRET')) define('SPOTIPY_CLIENT_SECRET', '');
if (!defined('DEFAULT_AUDIO_PROVIDER')) define('DEFAULT_AUDIO_PROVIDER', 'piped');

set_time_limit(0);
ignore_user_abort(true);

/* ─── Argumentos ─── */
$jobId = $argv[1] ?? null;
$url   = $argv[2] ?? null;

if (!$jobId || !$url) {
    die("Uso: php worker.php <job_id> <url>\n");
}

$jobDir  = JOBS_DIR      . $jobId . '/';
$dlDir   = DOWNLOADS_DIR . $jobId . '/';
$stateFile = $jobDir . 'state.json';

if (!is_dir($jobDir)) mkdir($jobDir, 0755, true);
if (!is_dir($dlDir))  mkdir($dlDir,  0755, true);

/* ─── Helpers ─── */
function loadState(string $f): array {
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
}
function saveState(string $f, array $s): void {
    file_put_contents($f, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function log_msg(string $msg): void {
    echo date('[H:i:s] ') . $msg . "\n";
    flush();
}

/* ─────────────────────────────────────────────
   STEP 1: Obtener info de la playlist via spotdl save
   ─────────────────────────────────────────────  */
log_msg("Obteniendo metadatos para: $url");

$saveFile = $jobDir . 'playlist.spotdl';
$cmd = sprintf(
    '%s save "%s" --save-file "%s" 2>&1',
    SPOTDL_CMD,
    addslashes($url),
    addslashes($saveFile)
);

$saveOutput = shell_exec($cmd);
log_msg("spotdl save output: " . substr($saveOutput, 0, 500));

$totalTracks = 1;
$trackNames  = [];

if (file_exists($saveFile)) {
    $playlistData = json_decode(file_get_contents($saveFile), true) ?? [];
    $songs = $playlistData['songs'] ?? [];
    $totalTracks = count($songs) ?: 1;
    foreach ($songs as $s) {
        $trackNames[] = ($s['name'] ?? 'Track') . ' - ' . implode(', ', $s['artists'] ?? []);
    }
    log_msg("Total canciones: $totalTracks");
}

$state = loadState($stateFile);
$state['total']  = $totalTracks;
$state['status'] = 'running';
saveState($stateFile, $state);

/* ─────────────────────────────────────────────
   STEP 2: Descargar con spotdl en modo sincrónico,
           parsear la salida en tiempo real
   ─────────────────────────────────────────────  */
log_msg("Iniciando descarga en: $dlDir");

// Inyectar PATH y credenciales de Spotify en el entorno del proceso
$venvPath = __DIR__ . '/.venv/Scripts';
putenv("PATH=" . $venvPath . PATH_SEPARATOR . getenv("PATH"));
if (SPOTIPY_CLIENT_ID) {
    putenv("SPOTIPY_CLIENT_ID=" . SPOTIPY_CLIENT_ID);
    putenv("SPOTIPY_CLIENT_SECRET=" . SPOTIPY_CLIENT_SECRET);
}

$downloadCmd = sprintf(
    '%s.exe download "%s" --output "%s" --format %s --bitrate %s --threads 4 --audio %s youtube-music 2>&1',
    SPOTDL_CMD,
    addslashes($url),
    rtrim(str_replace('\\', '/', $dlDir), '/'),
    AUDIO_FORMAT,
    AUDIO_QUALITY,
    DEFAULT_AUDIO_PROVIDER
);
log_msg("CMD: $downloadCmd");

// Abrir proceso y leer output línea por línea para actualizar progreso
$proc = popen($downloadCmd, 'r');
if (!$proc) {
    $state['status'] = 'error';
    $state['error']  = 'No se pudo iniciar spotdl. ¿Está instalado?';
    saveState($stateFile, $state);
    die("Error: no se pudo abrir proceso\n");
}

$doneTracks       = 0;
$completedIndices = [];

while (!feof($proc)) {
    $line = fgets($proc, 4096);
    if ($line === false) break;
    $line = trim($line);
    if (!$line) continue;

    log_msg($line);

    // spotdl imprime algo como:  Downloaded "Song Name - Artist"
    if (preg_match('/Downloaded\s+"(.+?)"/i', $line, $m) ||
        preg_match('/\[download\].*100%/i', $line) ||
        preg_match('/Finished downloading/i', $line)
    ) {
        $doneTracks++;
        $currentTrack = $m[1] ?? "Canción $doneTracks";
        $pct = $totalTracks > 0 ? min(round(($doneTracks / $totalTracks) * 100), 99) : 50;

        $state = loadState($stateFile);
        $state['done']             = $doneTracks;
        $state['progress']         = $pct;
        $state['current_track']    = $currentTrack;
        $completedIndices[]        = $doneTracks - 1;
        $state['completed_tracks'] = $completedIndices;
        saveState($stateFile, $state);
    }

    // Detectar errores fatales
    if (str_contains($line, 'Error') || str_contains($line, 'error')) {
        log_msg("Warning detectado: $line");
    }
}
pclose($proc);

/* ─────────────────────────────────────────────
   STEP 3: Crear ZIP con todos los archivos
   ─────────────────────────────────────────────  */
log_msg("Creando archivo ZIP...");

$state = loadState($stateFile);
$state['progress']      = 99;
$state['current_track'] = 'Empaquetando ZIP...';
saveState($stateFile, $state);

$zipFilename = 'playlist_' . $jobId . '.zip';
$zipPath     = DOWNLOADS_DIR . $zipFilename;

$mp3Files = glob($dlDir . '*.' . AUDIO_FORMAT);
if (empty($mp3Files)) {
    // Also look for other formats
    $mp3Files = array_merge(
        glob($dlDir . '*.mp3') ?: [],
        glob($dlDir . '*.m4a') ?: [],
        glob($dlDir . '*.ogg') ?: [],
        glob($dlDir . '*.flac') ?: []
    );
}

if (empty($mp3Files)) {
    $state['status'] = 'error';
    $state['error']  = 'No se descargaron archivos. Verifica spotdl y la URL.';
    saveState($stateFile, $state);
    log_msg("ERROR: No se encontraron archivos descargados en $dlDir");
    exit(1);
}

log_msg("Archivos encontrados: " . count($mp3Files));

// Crear ZIP usando ZipArchive
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $state['status'] = 'error';
    $state['error']  = 'No se pudo crear el archivo ZIP.';
    saveState($stateFile, $state);
    exit(1);
}

foreach ($mp3Files as $file) {
    $zip->addFile($file, basename($file));
    log_msg("Añadiendo: " . basename($file));
}
$zip->close();

$sizeMb = round(filesize($zipPath) / 1024 / 1024, 1);
log_msg("ZIP creado: $zipFilename ($sizeMb MB)");

/* ─────────────────────────────────────────────
   STEP 4: Actualizar estado final
   ─────────────────────────────────────────────  */
$state = loadState($stateFile);
$state['status']       = 'done';
$state['progress']     = 100;
$state['done']         = count($mp3Files);
$state['total']        = count($mp3Files);
$state['zip_filename'] = $zipFilename;
$state['zip_url']      = 'api.php?action=serve&file=' . urlencode($zipFilename);
$state['zip_size_mb']  = $sizeMb;
$state['current_track']= '✅ ¡Completado!';
saveState($stateFile, $state);

log_msg("¡Worker completado exitosamente!");
exit(0);
