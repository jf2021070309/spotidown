<?php
$url = "https://open.spotify.com/embed/playlist/48970m7qEd5Q2vxMih2nxz";
$options = [
    'http' => [
        'method' => "GET",
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = file_get_contents($url, false, $context);
if ($html === false) {
    $error = error_get_last();
    echo "FAILED: " . ($error['message'] ?? 'Unknown error');
} else {
    echo "SUCCESS: Length " . strlen($html);
    if (preg_match('/<script id="resource" type="application\/json">(.+?)<\/script>/s', $html, $matches)) {
        echo "\nJSON FOUND";
    } else {
        echo "\nJSON NOT FOUND";
    }
}
