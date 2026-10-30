<?php
/**
 * video.php — the short links to the site's YouTube videos.
 *
 * maestro-lab.science/video/70-seconds and maestro-lab.science/video/step-by-step arrive here
 * (Apache rewrite in the root .htaccess) and are sent on to YouTube. The
 * redirect is temporary and never cached, so changing a video's id in
 * includes/videos.php takes effect for every shared link at once.
 *
 * @author Marc Becker
 */
require_once __DIR__ . '/includes/videos.php';

$id = SITE_VIDEOS[$_GET['name'] ?? ''] ?? null;
if ($id === null) {
    http_response_code(404);
    exit;
}
header('Cache-Control: no-store');
header('Location: https://youtu.be/' . $id, true, 302);
