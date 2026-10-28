<?php
// index.php — public landing page
require_once __DIR__ . '/config.php';

$currentThumb  = currentThumbURL();
$currentVideo  = currentVideoURL();

// Load custom video link from settings
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Play · Thumbnail</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: #0b0b0b; height: 100vh; width: 100vw;
            display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            overflow: hidden; position: relative;
        }

        /* ---------- ADMIN LINK BUTTON (TOP-RIGHT) ---------- */
        .admin-link-btn {
            position: fixed;
            top: 18px;
            right: 18px;
            z-index: 1500;
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(35, 35, 35, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 40px;
            padding: 8px 14px;
            color: #ccc;
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.3px;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, transform 0.2s;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.5);
            user-select: none;
        }
        .admin-link-btn:hover {
            background: rgba(60, 60, 60, 0.95);
            color: #fff;
            transform: translateY(-1px);
        }
        .admin-link-btn svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
        }

        /* ---------- THUMBNAIL ---------- */
        .thumbnail-wrapper {
            position: relative;
            max-width: 95vw; max-height: 95vh;
            aspect-ratio: 16 / 9;
            border-radius: 24px; overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.05);
            cursor: pointer; background-color: #1a1a1a;
            display: flex; align-items: center; justify-content: center;
        }
        .thumbnail-img {
            position: absolute; top: 0; left: 0;
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.3s cubic-bezier(0.2,0.9,0.3,1);
            pointer-events: none; user-select: none;
        }
        .thumbnail-wrapper:hover .thumbnail-img { transform: scale(1.04); }

        .play-button {
            position: relative; z-index: 10;
            width: 90px; height: 90px;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.2);
            pointer-events: none;
        }
        .play-button::after {
            content: ''; display: block;
            width: 0; height: 0;
            border-style: solid;
            border-width: 18px 0 18px 30px;
            border-color: transparent transparent transparent #fff;
            margin-left: 8px;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5));
        }
        .thumbnail-wrapper:hover .play-button {
            background: rgba(0,0,0,0.75); transform: scale(1.02);
        }

        /* ---------- POPUP ---------- */
        .fullscreen-popup {
            position: fixed; inset: 0; z-index: 5000;
            background: #000;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            opacity: 0; pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .fullscreen-popup.active { opacity: 1; pointer-events: auto; }

        .popup-close {
            position: absolute; top: 18px; right: 18px; z-index: 5100;
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff; font-size: 1.6rem; line-height: 1;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            transition: background 0.2s, transform 0.2s;
        }
        .popup-close:hover { background: rgba(40,40,40,0.9); transform: scale(1.05); }

        .popup-media {
            max-width: 100vw; max-height: calc(100vh - 130px);
            width: 100%; display: flex; align-items: center; justify-content: center;
            padding: 0 16px; margin: auto 0;
        }
        .popup-media video, .popup-media img {
            max-width: 100%; max-height: calc(100vh - 130px);
            width: auto; height: auto; object-fit: contain;
            display: block; border-radius: 8px;
            user-select: none; -webkit-user-drag: none;
        }
        .popup-media video { background: #000; }

        .popup-bottom {
            position: absolute; bottom: 0; left: 0; right: 0;
            padding: 22px 20px calc(22px + env(safe-area-inset-bottom, 0px));
            background: linear-gradient(to top, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.7) 60%, rgba(0,0,0,0) 100%);
            display: flex; align-items: center; justify-content: center;
            z-index: 5050;
        }
        .popup-play-btn {
            display: inline-flex; align-items: center; gap: 12px;
            background: #4f6ef7; color: #fff;
            border: none; border-radius: 50px;
            padding: 16px 36px;
            font-size: 1.05rem; font-weight: 600; letter-spacing: 0.4px;
            cursor: pointer; text-decoration: none;
            box-shadow: 0 10px 30px rgba(79,110,247,0.5);
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
            user-select: none;
        }
        .popup-play-btn:hover {
            background: #6480ff; transform: translateY(-2px);
            box-shadow: 0 14px 36px rgba(79,110,247,0.65);
        }
        .popup-play-btn svg { width: 20px; height: 20px; flex-shrink: 0; }

        @media (max-width: 600px) {
            .play-button { width: 70px; height: 70px; }
            .play-button::after {
                border-width: 14px 0 14px 24px;
                margin-left: 6px;
            }
            .thumbnail-wrapper {
                border-radius: 16px;
                max-width: 98vw; max-height: 98vh;
            }
            .admin-link-btn {
                top: 12px; right: 12px;
                padding: 7px 12px; font-size: 0.72rem;
            }
            .popup-play-btn { padding: 14px 30px; font-size: 0.95rem; }
            .popup-close {
                top: 12px; right: 12px;
                width: 40px; height: 40px; font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>

    <!-- ADMIN LINK BUTTON (goes to admin.php) -->
    <a class="admin-link-btn" href="admin.php" aria-label="Admin panel">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <span>Admin</span>
    </a>

    <!-- THUMBNAIL -->
    <div class="thumbnail-wrapper" id="thumbnailWrapper" role="button" tabindex="0" aria-label="Open video">
        <img class="thumbnail-img" id="thumbnailImage"
             src="<?= htmlspecialchars($currentThumb) ?>" alt="Thumbnail">
        <div class="play-button" aria-hidden="true"></div>
    </div>

    <!-- POPUP -->
    <div class="fullscreen-popup" id="fullscreenPopup" role="dialog" aria-modal="true">
        <button class="popup-close" id="popupClose" aria-label="Close">&times;</button>

        <div class="popup-media">
            <?php if ($currentVideo): ?>
                <video id="popupVideo"
                       src="<?= htmlspecialchars($currentVideo) ?>"
                       poster="<?= htmlspecialchars($currentThumb) ?>"
                       controls playsinline preload="metadata"></video>
            <?php else: ?>
                <img src="<?= htmlspecialchars($currentThumb) ?>" alt="Preview">
            <?php endif; ?>
        </div>

        <div class="popup-bottom">
            <a class="popup-play-btn" href="<?= htmlspecialchars($customLink) ?>"
               target="_blank" rel="noopener noreferrer">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <span>Watch Video</span>
            </a>
        </div>
    </div>

<script>
    (function() {
        const thumbnailWrapper = document.getElementById('thumbnailWrapper');
        const fullscreenPopup = document.getElementById('fullscreenPopup');
        const popupClose      = document.getElementById('popupClose');
        const popupVideo      = document.getElementById('popupVideo');

        function openPopup() {
            fullscreenPopup.classList.add('active');
            document.body.style.overflow = 'hidden';
            if (popupVideo) { popupVideo.currentTime = 0; popupVideo.play().catch(()=>{}); }
        }
        function closePopup() {
            fullscreenPopup.classList.remove('active');
            document.body.style.overflow = '';
            if (popupVideo) popupVideo.pause();
        }

        thumbnailWrapper.addEventListener('click', openPopup);
        thumbnailWrapper.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPopup(); }
        });
        popupClose.addEventListener('click', closePopup);
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && fullscreenPopup.classList.contains('active')) closePopup();
        });
    })();
</script>
</body>
</html>