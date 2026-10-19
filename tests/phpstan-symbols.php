<?php

// Symbol discovery only, never bootstrap application configuration.
const APP_ROOT = __DIR__ . '/..';
define('GOOGLE_CLIENT_ID', (string) getenv('MUSICMAD_STATIC_ANALYSIS_UNUSED'));
define('GOOGLE_CLIENT_SECRET', (string) getenv('MUSICMAD_STATIC_ANALYSIS_UNUSED'));
define('GOOGLE_REDIRECT_URI', (string) getenv('MUSICMAD_STATIC_ANALYSIS_UNUSED'));
define('AUDIO_DIR', APP_ROOT . '/uploads/audio');
define('VIDEO_DIR', APP_ROOT . '/uploads/videos');
define('PARTITURA_DIR', APP_ROOT . '/uploads/partituras');
