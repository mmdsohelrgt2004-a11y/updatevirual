<?php
// admin.php — login + admin dashboard
require_once __DIR__ . '/config.php';

$isLoggedIn = isAdminLoggedIn();
$message    = '';
$msgType    = '';

// ---------- HANDLE LOGIN / LOGOUT ----------
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = trim($_POST['password'] ?? '');
    if ($pass === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $message = '❌ Wrong password.';
        $msgType = 'error';
    }
}

$isLoggedIn = isAdminLoggedIn();

// ---------- FILE UPLOAD / TRIM (only if logged in) ----------
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // Delete actions
    if ($action === 'delete_thumb' && file_exists(THUMB_FILE)) {
        @unlink(THUMB_FILE);
        $message = '✅ Thumbnail deleted.';
        $msgType = 'success';
    }
    if ($action === 'delete_video' && file_exists(VIDEO_FILE)) {
        @unlink(VIDEO_FILE);
        $message = '✅ Video deleted.';
        $msgType = 'success';
    }

    // Upload / trim
    if ($action === 'upload') {
        $didSomething = false;
        $errors = [];

        // ---- Image ----
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['thumbnail'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mime, $allowed)) {
                $errors[] = 'Thumbnail must be JPG, PNG, GIF or WEBP.';
            } elseif ($file['size'] > MAX_IMAGE_BYTES) {
                $errors[] = 'Thumbnail too large (max 8MB).';
            } else {
                $src = false;
                switch ($mime) {
                    case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
                    case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
                    case 'image/gif':  $src = @imagecreatefromgif($file['tmp_name']);  break;
                    case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
                }
                if ($src) {
                    $w = imagesx($src); $h = imagesy($src);
                    if ($w > MAX_WIDTH) {
                        $nw = MAX_WIDTH;
                        $nh = (int) round($h * (MAX_WIDTH / $w));
                        $res = imagecreatetruecolor($nw, $nh);
                        $white = imagecolorallocate($res, 255, 255, 255);
                        imagefilledrectangle($res, 0, 0, $nw, $nh, $white);
                        imagecopyresampled($res, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        imagedestroy($src);
                        $src = $res;
                    }
                    if (imagejpeg($src, THUMB_FILE, JPEG_QUALITY)) {
                        $didSomething = true;
                    } else {
                        $errors[] = 'Could not save thumbnail.';
                    }
                    imagedestroy($src);
                } else {
                    if (move_uploaded_file($file['tmp_name'], THUMB_FILE)) {
                        $didSomething = true;
                    } else {
                        $errors[] = 'Could not save thumbnail.';
                    }
                }
            }
        }

        // ---- Video ----
        if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
            $vf = $_FILES['video'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $vmime = finfo_file($finfo, $vf['tmp_name']);
            finfo_close($finfo);
            $allowedVideo = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];

            if (!in_array($vmime, $allowedVideo)) {
                $errors[] = 'Video must be MP4, WEBM, OGG or MOV.';
            } elseif ($vf['size'] > MAX_VIDEO_BYTES) {
                $errors[] = 'Video too large (max 100MB).';
            } else {
                $tempInput = TEMP_DIR . 'raw_' . uniqid() . '.mp4';
                if (move_uploaded_file($vf['tmp_name'], $tempInput)) {
                    $start = isset($_POST['trim_start']) ? max(0, (float)$_POST['trim_start']) : 0;
                    $end   = isset($_POST['trim_end']) ? (float)$_POST['trim_end'] : 0;
                    $total = getDuration($tempInput);

                    if ($end > 0 && $end <= $start) $end = 0;
                    if ($start >= $total && $total > 0) $start = 0;
                    if ($end > $total && $total > 0) $end = $total;
                    $trimDuration = ($end > 0 && $start < $end) ? ($end - $start) : 0;

                    $saved = false;
                    if (ffmpegAvailable() && $trimDuration > 0) {
                        $tempOut = TEMP_DIR . 'trimmed_' . uniqid() . '.mp4';
                        $cmd = sprintf(
                            '%s -y -ss %s -i %s -t %s -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart %s',
                            escapeshellarg(FFMPEG_BIN),
                            escapeshellarg((string)$start),
                            escapeshellarg($tempInput),
                            escapeshellarg((string)$trimDuration),
                            escapeshellarg($tempOut)
                        );
                        $out = ''; $code = runCmd($cmd, $out);
                        if ($code === 0 && file_exists($tempOut) && filesize($tempOut) > 0) {
                            if (file_exists(VIDEO_FILE)) @unlink(VIDEO_FILE);
                            if (rename($tempOut, VIDEO_FILE)) {
                                $saved = true;
                                $message = '✅ Video uploaded & trimmed to ' . formatTime($trimDuration);
                                $msgType = 'success';
                            }
                            @unlink($tempInput);
                        } else {
                            @unlink($tempOut);
                        }
                    }
                    if (!$saved) {
                        // Save raw
                        if (file_exists(VIDEO_FILE)) @unlink(VIDEO_FILE);
                        if (rename($tempInput, VIDEO_FILE)) {
                            $saved = true;
                            if ($trimDuration > 0) {
                                $message = '⚠️ Trim failed, full video saved.';
                                $msgType = 'warn';
                            } else {
                                $message = '✅ Full video uploaded.';
                                $msgType = 'success';
                            }
                        }
                    }
                    if ($saved) $didSomething = true;
                } else {
                    $errors[] = 'Could not save video upload.';
                }
            }
        }

        if (!empty($errors)) {
            $message = '❌ ' . implode(' ', $errors);
            $msgType = 'error';
        } elseif ($didSomething && !$message) {
            $message = '✅ Saved.';
            $msgType = 'success';
        } elseif (!$didSomething && !$message) {
            $message = '⚠️ No file selected.';
            $msgType = 'warn';
        }
    }

    // ---- Re-trim existing ----
    if ($action === 'retrim') {
        if (!file_exists(VIDEO_FILE)) {
            $message = '⚠️ No existing video to trim.';
            $msgType = 'warn';
        } elseif (!ffmpegAvailable()) {
            $message = '❌ FFmpeg not available on this server.';
            $msgType = 'error';
        } else {
            $start = max(0, (float)($_POST['trim_start'] ?? 0));
            $end   = (float)($_POST['trim_end'] ?? 0);
            $total = getDuration(VIDEO_FILE);

            if ($end <= $start || $end > $total) {
                $message = '❌ Invalid trim range. Video is ' . formatTime($total) . ' long.';
                $msgType = 'error';
            } else {
                $tempOut = TEMP_DIR . 'retrim_' . uniqid() . '.mp4';
                $cmd = sprintf(
                    '%s -y -ss %s -i %s -t %s -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart %s',
                    escapeshellarg(FFMPEG_BIN),
                    escapeshellarg((string)$start),
                    escapeshellarg(VIDEO_FILE),
                    escapeshellarg((string)($end - $start)),
                    escapeshellarg($tempOut)
                );
                $out = ''; $code = runCmd($cmd, $out);
                if ($code === 0 && file_exists($tempOut) && filesize($tempOut) > 0) {
                    @unlink(VIDEO_FILE);
                    if (rename($tempOut, VIDEO_FILE)) {
                        $message = '✅ Video trimmed. New length: ' . formatTime(getDuration(VIDEO_FILE));
                        $msgType = 'success';
                    } else {
                        $message = '❌ Could not save trimmed video.';
                        $msgType = 'error';
                    }
                } else {
                    @unlink($tempOut);
                    $message = '❌ FFmpeg trim failed.';
                    $msgType = 'error';
                }
            }
        }
    }

    // ---- Change video link ----
    if ($action === 'save_settings') {
        $newLink = trim($_POST['video_link'] ?? '');
        if ($newLink !== '') {
            // Save to a settings file
            $settingsFile = BASE_DIR . '/settings.json';
            $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
            $settings['video_link'] = $newLink;
            file_put_contents($settingsFile, json_encode($settings));
            $message = '✅ Video link saved.';
            $msgType = 'success';
        }
    }
}

// ---------- CURRENT STATE ----------
$thumbURL       = currentThumbURL();
$videoURL       = currentVideoURL();
$videoDuration  = file_exists(VIDEO_FILE) ? getDuration(VIDEO_FILE) : 0.0;
$ffmpegMissing  = !ffmpegAvailable();

// Load custom video link
$settingsFile = BASE_DIR . '/settings.json';
$customLink = VIDEO_LINK;
if (file_exists($settingsFile)) {
    $s = json_decode(file_get_contents($settingsFile), true);
    if (!empty($s['video_link'])) $customLink = $s['video_link'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0e0e10;
            color: #ddd;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
            padding: 30px 20px;
            display: flex;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 720px;
        }

        /* ---------- LOGIN ---------- */
        .login-box {
            background: #18181b;
            border: 1px solid #2a2a2e;
            border-radius: 18px;
            padding: 40px 32px;
            max-width: 400px;
            margin: 80px auto 0;
            box-shadow: 0 24px 60px rgba(0,0,0,0.7);
        }
        .login-box h1 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #fff;
            text-align: center;
        }
        .login-box p {
            color: #888;
            font-size: 0.85rem;
            text-align: center;
            margin-bottom: 24px;
        }
        .login-box input {
            width: 100%;
            background: #222226;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 13px 16px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            margin-bottom: 14px;
            transition: border 0.2s;
        }
        .login-box input:focus { border-color: #4f6ef7; }
        .btn {
            background: #4f6ef7;
            border: none;
            border-radius: 10px;
            padding: 13px 24px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn:hover { background: #6480ff; }
        .btn:active { transform: scale(0.98); }
        .btn.full { width: 100%; }
        .btn.ghost {
            background: #2a2a2e;
            color: #ccc;
            border: 1px solid #3a3a3e;
        }
        .btn.ghost:hover { background: #35353a; color: #fff; }
        .btn.danger {
            background: #c0392b;
        }
        .btn.danger:hover { background: #d94e3f; }
        .btn.warn {
            background: #b25f2c;
        }
        .btn.warn:hover { background: #c96d33; }
        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .message {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 18px;
            text-align: center;
            line-height: 1.4;
        }
        .message.success { background: rgba(111,207,151,0.12); color: #6fcf97; border: 1px solid rgba(111,207,151,0.3); }
        .message.error   { background: rgba(235,87,87,0.12);  color: #eb5757; border: 1px solid rgba(235,87,87,0.3); }
        .message.warn    { background: rgba(242,153,74,0.12); color: #f2994a; border: 1px solid rgba(242,153,74,0.3); }

        /* ---------- DASHBOARD ---------- */
        .dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .dash-header h1 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #fff;
        }
        .dash-header .subtitle {
            color: #777;
            font-size: 0.82rem;
            margin-top: 4px;
        }

        .card {
            background: #18181b;
            border: 1px solid #2a2a2e;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card h2 .num {
            background: #4f6ef7;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .preview-box {
            background: #0a0a0c;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 180px;
            border: 1px solid #2a2a2e;
        }
        .preview-box img {
            max-width: 100%;
            max-height: 280px;
            display: block;
        }
        .preview-box video {
            max-width: 100%;
            max-height: 280px;
            display: block;
            background: #000;
        }
        .preview-box .empty {
            color: #555;
            font-size: 0.85rem;
            padding: 40px;
            text-align: center;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-top: 1px solid #242428;
            font-size: 0.82rem;
            color: #999;
        }
        .info-row:first-child { border-top: none; }
        .info-row strong { color: #ddd; font-weight: 600; }
        .info-row .mono { font-family: 'SF Mono', Menlo, Consolas, monospace; }

        .field {
            margin-bottom: 14px;
        }
        .field label {
            display: block;
            color: #999;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 6px;
            letter-spacing: 0.2px;
        }
        .field input[type="file"],
        .field input[type="text"],
        .field input[type="number"],
        .field input[type="password"] {
            width: 100%;
            background: #222226;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 11px 14px;
            color: #fff;
            font-size: 0.88rem;
            outline: none;
            transition: border 0.2s;
        }
        .field input:focus { border-color: #4f6ef7; }
        .field input[type="file"] {
            padding: 8px 10px;
            cursor: pointer;
        }
        .field input[type="file"]::file-selector-button {
            background: #2e2e33;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            color: #fff;
            font-weight: 500;
            margin-right: 10px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .field input[type="file"]::file-selector-button:hover {
            background: #3a3a42;
        }

        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .hint {
            color: #666;
            font-size: 0.73rem;
            line-height: 1.4;
            margin-top: 4px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .divider {
            height: 1px;
            background: #242428;
            margin: 18px 0;
        }

        @media (max-width: 600px) {
            body { padding: 16px 12px; }
            .login-box { margin-top: 40px; padding: 30px 24px; }
            .dash-header h1 { font-size: 1.15rem; }
            .card { padding: 18px; }
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
    <!-- ============= LOGIN SCREEN ============= -->
    <div class="login-box">
        <h1>🔒 Admin Login</h1>
        <p>Enter password to access the panel</p>
        <?php if ($message): ?>
            <div class="message <?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST" action="admin.php">
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" placeholder="Password" autocomplete="current-password" autofocus required>
            <button type="submit" class="btn full">Sign in</button>
        </form>
        <div style="text-align:center; margin-top:16px;">
            <a href="index.php" style="color:#666; font-size:0.78rem; text-decoration:none;">← Back to site</a>
        </div>
    </div>

<?php else: ?>
    <!-- ============= DASHBOARD ============= -->
    <div class="container">
        <div class="dash-header">
            <div>
                <h1>🎬 Admin Dashboard</h1>
                <div class="subtitle">Manage your landing page content</div>
            </div>
            <div style="display:flex; gap:8px;">
                <a href="index.php" class="btn ghost" style="padding:10px 18px; font-size:0.82rem;">View site →</a>
                <a href="admin.php?action=logout" class="btn danger" style="padding:10px 18px; font-size:0.82rem;">Logout</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($ffmpegMissing): ?>
            <div class="message warn">
                ⚠️ FFmpeg is not installed on this server. Video trimming is disabled.
            </div>
        <?php endif; ?>

        <!-- ============= THUMBNAIL ============= -->
        <div class="card">
            <h2><span class="num">1</span> Thumbnail image</h2>

            <div class="preview-box">
                <?php if (file_exists(THUMB_FILE)): ?>
                    <img src="<?= htmlspecialchars($thumbURL) ?>" alt="Current thumbnail">
                <?php else: ?>
                    <div class="empty">No thumbnail uploaded yet<br>(visitors see the default placeholder)</div>
                <?php endif; ?>
            </div>

            <form method="POST" action="admin.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="field">
                    <label>Upload new image (JPG/PNG/WEBP, max 8MB)</label>
                    <input type="file" name="thumbnail" accept="image/*">
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Upload thumbnail</button>
                </div>
            </form>

            <?php if (file_exists(THUMB_FILE)): ?>
                <div class="divider"></div>
                <form method="POST" action="admin.php" onsubmit="return confirm('Delete thumbnail?');">
                    <input type="hidden" name="action" value="delete_thumb">
                    <button type="submit" class="btn danger" style="font-size:0.82rem; padding:9px 16px;">🗑 Delete thumbnail</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- ============= VIDEO ============= -->
        <div class="card">
            <h2><span class="num">2</span> Popup video</h2>

            <div class="preview-box">
                <?php if ($videoURL): ?>
                    <video src="<?= htmlspecialchars($videoURL) ?>"
                           poster="<?= htmlspecialchars($thumbURL) ?>"
                           controls playsinline preload="metadata"></video>
                <?php else: ?>
                    <div class="empty">No video uploaded<br>(popup shows only the image)</div>
                <?php endif; ?>
            </div>

            <?php if ($videoURL): ?>
                <div class="info-row">
                    <span>Current video</span>
                    <strong class="mono"><?= formatTime($videoDuration) ?></strong>
                </div>
            <?php endif; ?>

            <form method="POST" action="admin.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="field">
                    <label>Upload new video (MP4/WEBM, max 100MB)</label>
                    <input type="file" name="video" accept="video/mp4,video/webm,video/ogg,video/quicktime" id="videoInput">
                </div>
                <div class="field">
                    <label>Trim (optional) — seconds</label>
                    <div class="field-row">
                        <input type="number" step="0.1" min="0" name="trim_start" placeholder="Start (0.0)" id="trimStart">
                        <input type="number" step="0.1" min="0" name="trim_end" placeholder="End (e.g. 15.0)" id="trimEnd">
                    </div>
                    <div class="hint">Leave blank to keep full video. FFmpeg will cut precisely.</div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Upload video</button>
                </div>
            </form>

            <?php if ($videoURL && !$ffmpegMissing): ?>
                <div class="divider"></div>
                <form method="POST" action="admin.php">
                    <input type="hidden" name="action" value="retrim">
                    <label style="display:block;color:#999;font-size:0.8rem;font-weight:500;margin-bottom:6px;">Re-trim existing video</label>
                    <div class="field-row" style="margin-bottom:10px;">
                        <input type="number" step="0.1" min="0" name="trim_start" placeholder="Start" required>
                        <input type="number" step="0.1" min="0" name="trim_end" placeholder="End" required>
                    </div>
                    <button type="submit" class="btn warn" style="font-size:0.82rem; padding:9px 16px;">✂️ Re-trim</button>
                </form>
            <?php endif; ?>

            <?php if ($videoURL): ?>
                <div class="divider"></div>
                <form method="POST" action="admin.php" onsubmit="return confirm('Delete video?');">
                    <input type="hidden" name="action" value="delete_video">
                    <button type="submit" class="btn danger" style="font-size:0.82rem; padding:9px 16px;">🗑 Delete video</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- ============= SETTINGS ============= -->
        <div class="card">
            <h2><span class="num">3</span> Settings</h2>
            <form method="POST" action="admin.php">
                <input type="hidden" name="action" value="save_settings">
                <div class="field">
                    <label>Popup play button link</label>
                    <input type="text" name="video_link" value="<?= htmlspecialchars($customLink) ?>" placeholder="https://...">
                    <div class="hint">The "Watch Video" button in the popup opens this link.</div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Save settings</button>
                </div>
            </form>
        </div>

        <div style="text-align:center; color:#444; font-size:0.72rem; padding:20px 0;">
            Admin panel · <?= date('Y') ?>
        </div>
    </div>

<?php endif; ?>

<script>
    // Auto-hint end time when video is picked
    const videoInput = document.getElementById('videoInput');
    if (videoInput) {
        videoInput.addEventListener('change', (e) => {
            const f = e.target.files[0];
            if (!f) return;
            const url = URL.createObjectURL(f);
            const tmp = document.createElement('video');
            tmp.preload = 'metadata';
            tmp.src = url;
            tmp.onloadedmetadata = () => {
                const dur = tmp.duration;
                const endInput = document.getElementById('trimEnd');
                if (endInput && !endInput.value) {
                    endInput.placeholder = `max ${dur.toFixed(1)}s`;
                }
                URL.revokeObjectURL(url);
            };
            tmp.onerror = () => URL.revokeObjectURL(url);
        });
    }
</script>

</body>
</html>