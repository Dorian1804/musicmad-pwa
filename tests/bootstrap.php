<?php

require_once __DIR__ . '/paths.php';
define('MUSICMAD_TEST_RUN', true);
foreach (['MAIL_HOST','MAIL_USER','MAIL_PASS','MAIL_FROM','MAIL_PORT','VAPID_PUBLIC_KEY','VAPID_PRIVATE_KEY','VAPID_SUBJECT'] as $testSetting) {
    putenv('MM_' . $testSetting);
}
putenv('MM_APP_URL=http://127.0.0.1:8765');
putenv('MM_PUBLIC_URL=https://127.0.0.1:8765');
putenv('MM_ENC_KEY=' . str_repeat('test-only-key-', 5));
putenv('MM_ENC_KEY_LEGACY_UNTIL=0');
if (getenv('MM_RUN_QA') === '1') {
    $qaEnv = parse_ini_file(musicmadQaRoot() . '/.env', false, INI_SCANNER_RAW);
    define('DB_HOST', '127.0.0.1;port=3308');
    define('DB_NAME', 'musicmad_qa');
    define('DB_USER', 'root');
    define('DB_PASS', $qaEnv['MM_TEST_DB_PASS']);
    define('APP_UPLOAD_ROOT', musicmadQaRoot() . '/uploads');
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/MusicMad_PWA/tests/phpunit';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/social.php';
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/004_account_sessions.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/006_two_factor_state.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/007_jobs_and_email.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/008_chat_live.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/009_chat_stream.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/010_web_push.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/011_global_search.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/012_band_workspace.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/013_band_reminders.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/014_musician_matching.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/015_scene_map.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/016_event_workspace.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/017_account_controls.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/018_artist_portfolio.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/019_public_embed.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/020_public_read_limits.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/021_listening_rooms.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/022_precise_audio_comments.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/023_creative_studio.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/024_artist_statistics.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/025_weekly_challenges.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/026_musician_endorsements.sql'));
}


if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/027_musician_onboarding.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/028_track_feedback.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/029_collaborative_playlists.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/030_repost_comments.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/031_account_verification.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/032_required_email.sql'));
}
if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/033_required_onboarding.sql'));
}

if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/034_push_controls.sql'));
}

if (getenv('MM_RUN_QA') === '1') {
    getDB()->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/035_user_locale.sql'));
}
