<?php
// config.php — shared config & helpers

session_start();

// ---------- CONFIG ----------
define('ADMIN_PASS', '@Sohel73405@');
define('VIDEO_LINK', 'https://example.com');   // 👈 bottom button destination

define('BASE_DIR', __DIR__);
define('UPLOAD_DIR', BASE_DIR . '/uploads/');
define('TEMP_DIR', UPLOAD_DIR . 'temp/');
define('THUMB_FILE', UPLOAD_DIR . 'thumbnail.jpg');
define('VIDEO_FILE', UPLOAD_DIR . 'video.mp4');

define('THUMB_URL', 'uploads/thumbnail.jpg');
define('VIDEO_URL', 'uploads/video.mp4');

define('MAX_IMAGE_BYTES', 8 * 1024 * 1024);
define('MAX_VIDEO_BYTES', 100 * 1024 * 1024);
define('MAX_WIDTH', 1600);
define('JPEG_QUALITY', 90);

define('FFMPEG_BIN',  'ffmpeg');
define('FFPROBE_BIN', 'ffprobe');

// ---------- ENSURE FOLDERS ----------
foreach ([UPLOAD_DIR, TEMP_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// ---------- HELPERS ----------
function runCmd(string $cmd, string &$output = null): int {
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    $output = implode("\n", $output);
    return $code;
}

function ffmpegAvailable(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = '';
    return $cache = (runCmd(FFMPEG_BIN . ' -version', $out) === 0);
}

function getDuration(string $path): float {
    $out = '';
    $cmd = FFPROBE_BIN . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path);
    if (runCmd($cmd, $out) === 0) {
        return (float) trim($out);
    }
    return 0.0;
}

function formatTime(float $seconds): string {
    $m = floor($seconds / 60);
    $s = $seconds - ($m * 60);
    return sprintf('%02d:%05.2f', $m, $s);
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: admin.php');
        exit;
    }
}

// ---------- DEFAULT PLACEHOLDER ----------
function defaultThumb(): string {
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='450' viewBox='0 0 800 450'%3E%3Crect width='800' height='450' fill='%23181818'/%3E%3Ctext x='400' y='225' font-family='system-ui, sans-serif' font-size='24' fill='%23555555' text-anchor='middle' dominant-baseline='middle'%3EThumbnail%3C/text%3E%3C/svg%3E";
}

function currentThumbURL(): string {
    if (file_exists(THUMB_FILE)) {
        return THUMB_URL . '?v=' . filemtime(THUMB_FILE);
    }
    return defaultThumb();
}

function currentVideoURL(): string {
    if (file_exists(VIDEO_FILE)) {
        return VIDEO_URL . '?v=' . filemtime(VIDEO_FILE);
    }
    return '';
}