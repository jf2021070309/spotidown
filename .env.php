<?php
/**
 * SpotiDown Configuration
 * Guarda las credenciales de Spotify para evitar límites de rate
 */

define('SPOTIPY_CLIENT_ID', '');
define('SPOTIPY_CLIENT_SECRET', '');

// Proveedor de audio predeterminado (Cambiado a piped para evitar bloques de YouTube)
define('DEFAULT_AUDIO_PROVIDER', 'piped');
