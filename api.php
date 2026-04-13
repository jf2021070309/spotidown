<?php
/**
 * SpotiDown API
 * PHP backend que usa spotdl para descargar tracks de Spotify en 320 kbps
 *
 * Acciones:
 *  - info           : obtiene metadata de la playlist/album/track via spotdl --print-errors
 *  - start_zip      : inicia un job de descarga completa en background, devuelve job_id
 *  - status         : polling del estado del job
 *  - download_single: descarga una sola canción
 *  - serve          : sirve el archivo ZIP resultante
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ─────────────────────────────────────────────
   CONFIGURATION
   ───────────────────────────────────────────── */
define('SPOTDL_CMD',  __DIR__ . '/.venv/Scripts/spotdl');
define('PYTHON_CMD',  __DIR__ . '/.venv/Scripts/python');
define('DOWNLOADS_DIR', __DIR__ . '/downloads/');
define('JOBS_DIR',       __DIR__ . '/jobs/');
define('AUDIO_FORMAT',   'mp3');
define('AUDIO_QUALITY',  '320k');
define('MAX_EXEC_TIME',  1800);           // segundos (30 min)

// Crear directorios necesarios
foreach ([DOWNLOADS_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

/* ─────────────────────────────────────────────
   ROUTER
   ───────────────────────────────────────────── */
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'info':           handleInfo();           break;
    case 'start_zip':      handleStartZip();       break;
    case 'status':         handleStatus();         break;
    case 'download_single':handleDownloadSingle(); break;
    case 'serve':          handleServe();           break;
    case 'check_deps':     handleCheckDeps();      break;
    default:
        jsonError('Acción no válida');
}

/* ═════════════════════════════════════════════
   ACTION: INFO
   Obtiene metadata de playlist/álbum/track
   ═════════════════════════════════════════════ */
function handleInfo() {
    $body = getJsonBody();
    $url  = sanitizeUrl($body['url'] ?? '');
    if (!$url) jsonError('URL no válida');

    // Usar spotdl --print-errors --format json para obtener metadata
    $cmd = buildSpotdlCmd(['--print-errors', '--save-file', '/dev/null', '--output', '/dev/null'], $url);

    // Alternativa: usar la API de Spotify directamente vía spotdl
    // Como spotdl puede tardar, usamos un timeout corto solo para metadata
    $output = execCommand(SPOTDL_CMD . ' save "' . escapeshellarg($url) . '"' .
        ' --save-file ' . escapeshellarg(JOBS_DIR . 'meta_' . uniqid() . '.spotdl') .
        ' 2>&1', 60);

    // Parsear el archivo .spotdl generado (JSON)
    $meta = parseSpotdlMeta($url);

    jsonSuccess($meta);
}

/* ═════════════════════════════════════════════
   ACTION: START_ZIP
   Inicia descarga completa en background
   ═════════════════════════════════════════════ */
function handleStartZip() {
    $body = getJsonBody();
    $url  = sanitizeUrl($body['url'] ?? '');
    if (!$url) jsonError('URL no válida');

    $jobId  = uniqid('job_', true);
    $jobDir = JOBS_DIR . $jobId . '/';
    $dlDir  = DOWNLOADS_DIR . $jobId . '/';
    mkdir($jobDir, 0755, true);
    mkdir($dlDir,  0755, true);

    // Crear archivo de estado inicial
    $state = [
        'status'           => 'running',
        'progress'         => 0,
        'done'             => 0,
        'total'            => 0,
        'current_track'    => '',
        'completed_tracks' => [],
        'zip_filename'     => '',
        'zip_url'          => '',
        'zip_size_mb'      => '',
        'error'            => '',
        'started_at'       => time(),
        'url'              => $url,
    ];
    saveJobState($jobId, $state);

    // Lanzar proceso en background
    $scriptPath = __DIR__ . '/worker.php';
    $logFile    = $jobDir . 'worker.log';

    // Windows: start /B php worker.php jobId url
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            'start /B php "%s" %s "%s" > "%s" 2>&1',
            $scriptPath,
            escapeshellarg($jobId),
            addslashes($url),
            $logFile
        );
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = sprintf(
            'php "%s" %s "%s" > "%s" 2>&1 &',
            $scriptPath,
            escapeshellarg($jobId),
            escapeshellarg($url),
            $logFile
        );
        exec($cmd);
    }

    jsonSuccess(['job_id' => $jobId]);
}

/* ═════════════════════════════════════════════
   ACTION: STATUS
   Retorna el estado del job para polling
   ═════════════════════════════════════════════ */
function handleStatus() {
    $jobId = preg_replace('/[^a-zA-Z0-9_.]/', '', $_GET['job_id'] ?? '');
    if (!$jobId) jsonError('job_id no válido');

    $state = loadJobState($jobId);
    if (!$state) jsonError('Job no encontrado');

    jsonSuccess($state);
}

/* ═════════════════════════════════════════════
   ACTION: DOWNLOAD_SINGLE
   Descarga una sola canción
   ═════════════════════════════════════════════ */
function handleDownloadSingle() {
    $body  = getJsonBody();
    $url   = sanitizeUrl($body['url'] ?? '');
    $index = intval($body['index'] ?? 0);
    if (!$url) jsonError('URL no válida');

    $tmpDir = DOWNLOADS_DIR . 'single_' . uniqid() . '/';
    mkdir($tmpDir, 0755, true);

    $cmd = sprintf(
        '%s download "%s" --output "%s" --format %s --bitrate %s --threads 4 2>&1',
        SPOTDL_CMD,
        addslashes($url),
        rtrim($tmpDir, '/\\'),
        AUDIO_FORMAT,
        AUDIO_QUALITY
    );

    $output = execCommand($cmd, 300);

    // Buscar el archivo descargado
    $files = glob($tmpDir . '*.' . AUDIO_FORMAT);
    if (empty($files)) {
        // Limpiar y reportar error
        removeDir($tmpDir);
        jsonError('No se pudo descargar la canción. Verifica que spotdl esté instalado.');
    }

    $file     = $files[0];
    $filename = basename($file);
    $destDir  = DOWNLOADS_DIR . 'singles/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $dest = $destDir . $filename;
    rename($file, $dest);
    removeDir($tmpDir);

    jsonSuccess([
        'download_url' => 'api.php?action=serve&file=' . urlencode('singles/' . $filename),
        'filename'     => $filename,
    ]);
}

/* ═════════════════════════════════════════════
   ACTION: SERVE
   Sirve un archivo para descarga
   ═════════════════════════════════════════════ */
function handleServe() {
    $file = $_GET['file'] ?? '';
    // Sanitize – prevent path traversal
    $file = ltrim(str_replace(['..', '\\'], ['', '/'], $file), '/');
    $path = DOWNLOADS_DIR . $file;

    if (!file_exists($path) || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Archivo no encontrado']);
        exit;
    }

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}

/* ═════════════════════════════════════════════
   ACTION: CHECK_DEPS
   Verifica que spotdl y ffmpeg estén instalados
   ═════════════════════════════════════════════ */
function handleCheckDeps() {
    $venvPath = __DIR__ . '/.venv/Scripts';
    putenv("PATH=" . $venvPath . PATH_SEPARATOR . getenv("PATH"));

    $spotdl = trim(shell_exec(SPOTDL_CMD . '.exe --version 2>&1') ?? '');
    $ffmpeg = trim(shell_exec('ffmpeg -version 2>&1') ?? '');
    $python = trim(shell_exec(PYTHON_CMD . '.exe --version 2>&1') ?? '');

    jsonSuccess([
        'spotdl_version' => $spotdl ?: 'No encontrado',
        'ffmpeg_version' => $ffmpeg ? explode("\n", $ffmpeg)[0] : 'No encontrado',
        'python_version' => $python ?: 'No encontrado',
        'spotdl_ok'      => (bool)preg_match('/^\d+\.\d+\.\d+/', $spotdl), // Verifica si empieza con un número de versión
        'ffmpeg_ok'      => str_contains(strtolower($ffmpeg), 'ffmpeg'),
    ]);
}

/* ─────────────────────────────────────────────
   HELPER: Parse Spotify metadata
   ───────────────────────────────────────────── */
function parseSpotdlMeta(string $url): array {
    // Intentar con spotdl save para obtener JSON
    $tmpFile = tempnam(JOBS_DIR, 'meta') . '.spotdl';

    $cmd = sprintf(
        '%s save "%s" --save-file "%s" 2>&1',
        SPOTDL_CMD,
        addslashes($url),
        addslashes($tmpFile)
    );

    $output = execCommand($cmd, 120);

    $meta = [
        'success' => true,
        'name'    => 'Playlist',
        'type'    => 'playlist',
        'owner'   => '',
        'image'   => '',
        'tracks'  => [],
    ];

    if (file_exists($tmpFile)) {
        $json = file_get_contents($tmpFile);
        @unlink($tmpFile);
        $data = json_decode($json, true);

        if ($data && isset($data['songs'])) {
            // spotdl .spotdl format
            $meta['type']  = $data['type'] ?? 'playlist';
            $meta['name']  = $data['name'] ?? 'Playlist';
            $meta['owner'] = $data['artist'] ?? ($data['owner'] ?? '');
            $meta['image'] = $data['cover_url'] ?? '';

            foreach (($data['songs'] ?? []) as $idx => $song) {
                $meta['tracks'][] = [
                    'name'     => $song['name'] ?? "Track " . ($idx + 1),
                    'artists'  => $song['artists'] ?? [],
                    'duration' => isset($song['duration']) ? intval($song['duration'] * 1000) : 0,
                    'image'    => $song['cover_url'] ?? '',
                    'url'      => $song['url'] ?? '',
                    'id'       => $song['song_id'] ?? '',
                ];
            }
        }
    }

    // Fallback: parse from spotdl output lines
    if (empty($meta['tracks'])) {
        $meta = parseFallbackMeta($url, $output);
    }

    return $meta;
}

function parseFallbackMeta(string $url, string $output): array {
    // Determine type from URL
    $type = 'playlist';
    if (str_contains($url, '/album/'))   $type = 'album';
    if (str_contains($url, '/track/'))   $type = 'track';
    if (str_contains($url, '/artist/'))  $type = 'artist';

    // Try to count songs from spotdl output
    preg_match_all('/Downloaded "([^"]+)"/', $output, $m);
    $names = $m[1] ?? [];

    $tracks = [];
    foreach ($names as $i => $name) {
        $tracks[] = [
            'name'    => $name,
            'artists' => [],
            'duration'=> 0,
            'image'   => '',
            'url'     => $url,
        ];
    }

    // If still no tracks, create placeholder from URL
    if (empty($tracks)) {
        $tracks[] = [
            'name'    => 'Cargando canciones...',
            'artists' => ['Buscando...'],
            'duration'=> 0,
            'image'   => '',
            'url'     => $url,
        ];
    }

    return [
        'success' => true,
        'name'    => ucfirst($type) . ' de Spotify',
        'type'    => $type,
        'owner'   => '',
        'image'   => '',
        'tracks'  => $tracks,
    ];
}

/* ─────────────────────────────────────────────
   JOB STATE HELPERS
   ───────────────────────────────────────────── */
function jobStateFile(string $jobId): string {
    return JOBS_DIR . $jobId . '/state.json';
}
function saveJobState(string $jobId, array $state): void {
    file_put_contents(jobStateFile($jobId), json_encode($state, JSON_UNESCAPED_UNICODE));
}
function loadJobState(string $jobId): ?array {
    $f = jobStateFile($jobId);
    if (!file_exists($f)) return null;
    return json_decode(file_get_contents($f), true);
}

/* ─────────────────────────────────────────────
   GENERAL HELPERS
   ───────────────────────────────────────────── */
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function sanitizeUrl(string $url): string {
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $host = parse_url($url, PHP_URL_HOST);
    if ($host !== 'open.spotify.com') return '';
    return $url;
}

function buildSpotdlCmd(array $flags, string $url): string {
    $parts = [SPOTDL_CMD . '.exe'];
    foreach ($flags as $f) $parts[] = $f;
    $parts[] = '"' . addslashes($url) . '"';
    return implode(' ', $parts);
}

function execCommand(string $cmd, int $timeout = 60): string {
    $venvPath = __DIR__ . '/.venv/Scripts';
    putenv("PATH=" . $venvPath . PATH_SEPARATOR . getenv("PATH"));
    
    set_time_limit($timeout + 10);
    if (PHP_OS_FAMILY === 'Windows') {
        return shell_exec($cmd) ?? '';
    }
    return shell_exec('timeout ' . $timeout . ' ' . $cmd . ' 2>&1') ?? '';
}

function removeDir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (glob($dir . '*') as $f) {
        is_dir($f) ? removeDir($f . '/') : unlink($f);
    }
    rmdir($dir);
}

function jsonSuccess(array $data): never {
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
