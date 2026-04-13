<?php
/**
 * SpotiDown API - DEBUG VERSION
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Cargar configuración
if (file_exists(__DIR__ . '/.env.php')) {
    include_once __DIR__ . '/.env.php';
}

if (!defined('SPOTIPY_CLIENT_ID')) define('SPOTIPY_CLIENT_ID', '');
if (!defined('SPOTIPY_CLIENT_SECRET')) define('SPOTIPY_CLIENT_SECRET', '');
if (!defined('SPOTIFY_COOKIE')) define('SPOTIFY_COOKIE', '');
if (!defined('DEFAULT_AUDIO_PROVIDER')) define('DEFAULT_AUDIO_PROVIDER', 'piped');

define('SPOTDL_CMD',  __DIR__ . '/.venv/Scripts/spotdl');
define('DOWNLOADS_DIR', __DIR__ . '/downloads/');
define('JOBS_DIR',       __DIR__ . '/jobs/');

foreach ([DOWNLOADS_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'info':           handleInfo();           break;
    case 'get_config':     handleGetConfig();      break;
    case 'save_config':    handleSaveConfig();     break;
    case 'check_deps':     handleCheckDeps();      break;
    case 'start_zip':      handleStartZip();       break;
    case 'status':         handleStatus();         break;
    case 'download_single':handleDownloadSingle(); break;
    case 'serve':          handleServe();           break;
    default: jsonError('Acción no válida');
}

function handleGetConfig() {
    jsonSuccess([
        'client_id' => SPOTIPY_CLIENT_ID,
        'client_secret' => SPOTIPY_CLIENT_SECRET,
        'spotify_cookie' => SPOTIFY_COOKIE
    ]);
}

function handleInfo() {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $url  = trim($body['url'] ?? '');
    if (!$url) jsonError('URL no válida');

    $meta = parseSpotdlMeta($url);
    if (!$meta['success']) jsonError($meta['error']);
    jsonSuccess($meta);
}

function parseSpotdlMeta($url) {
    $tmpFile = JOBS_DIR . 'meta_' . uniqid() . '.spotdl';
    
    // Inyectar cookie en spotdl.json local
    if (SPOTIFY_COOKIE) {
        $config = json_encode(['spotify' => ['cookie' => SPOTIFY_COOKIE]]);
        file_put_contents(__DIR__ . '/spotdl.json', $config);
    }

    $cmd = sprintf('"%s.exe" save "%s" --save-file "%s" --no-cache 2>&1', SPOTDL_CMD, $url, $tmpFile);
    
    // Ejecutar con PATH configurado
    $venvPath = __DIR__ . '/.venv/Scripts';
    putenv("PATH=" . $venvPath . PATH_SEPARATOR . getenv("PATH"));
    
    $output = shell_exec($cmd) ?? '';
    
    // DEBUG LOG
    file_put_contents(__DIR__ . '/debug.log', "[".date('H:i:s')."] CMD: $cmd\nOUT: $output\n\n", FILE_APPEND);

    if (file_exists($tmpFile)) {
        $json = file_get_contents($tmpFile);
        @unlink($tmpFile);
        $data = json_decode($json, true);
        if ($data && isset($data['songs'])) {
            $tracks = [];
            foreach ($data['songs'] as $s) {
                $tracks[] = [
                    'name' => $s['name'],
                    'artists' => $s['artists'] ?? [],
                    'url' => $s['url'],
                    'image' => $s['cover_url'] ?? ''
                ];
            }
            return [
                'success' => true,
                'name' => $data['name'] ?? 'Playlist',
                'tracks' => $tracks
            ];
        }
    }

    // FALLBACK: Emergencia Embed
    return parseEmergency($url, $output);
}

function parseEmergency($url, $originalOutput) {
    $url = preg_replace('/\?.*/', '', $url);
    $embedUrl = str_replace(['/playlist/', '/track/', '/album/'], ['/embed/playlist/', '/embed/track/', '/embed/album/'], $url);
    
    $options = ['http' => ['header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"]];
    $html = @file_get_contents($embedUrl, false, stream_context_create($options));
    
    if (!$html) {
        return ['success' => false, 'error' => "BLOQUEO: Spotify rechazó la conexión. SpotDL dijo: ".substr($originalOutput, 0, 100)];
    }

    // Guardar para inspección
    file_put_contents(__DIR__ . '/debug_html.txt', $html);

    if (preg_match('/<script id="resource" type="application\/json">(.+?)<\/script>/s', $html, $matches)) {
        $json = json_decode($matches[1], true);
        if ($json) {
            $items = $json['tracks']['items'] ?? [$json];
            $tracks = [];
            foreach ($items as $it) {
                $t = $it['track'] ?? $it;
                $tracks[] = ['name' => $t['name'] ?? '?', 'artists' => ['Spotify']];
            }
            return ['success' => true, 'name' => 'Rescate: ' . ($json['name'] ?? 'Spotify'), 'tracks' => $tracks];
        }
    }

    return ['success' => false, 'error' => "No se pudo extraer metadata (Debug guardado en servidor)"];
}

// Stubs para el resto de acciones (mantener funcionalidad)
function handleSaveConfig() { /* ... similar a antes ... */ }
function handleCheckDeps() { /* ... */ }
function handleStartZip() { /* ... */ }
function handleStatus() { /* ... */ }
function handleDownloadSingle() { /* ... */ }
function handleServe() { /* ... */ }

function jsonSuccess($data) { echo json_encode(array_merge(['success'=>true], $data)); exit; }
function jsonError($msg) { http_response_code(400); echo json_encode(['success'=>false, 'error'=>$msg]); exit; }
