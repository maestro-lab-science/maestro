<?php
/**
 * videos.php — the site's two YouTube videos, in one place.
 *
 * The short links maestro-lab.science/video/70-seconds and
 * maestro-lab.science/video/step-by-step (Apache rewrite to app/video.php),
 * named after the videos' YouTube titles, and the players on the landing page
 * and the portal all read their YouTube id from here. To swap a video, upload
 * the new one to YouTube and change its id below: the shared links stay the same.
 *
 * @author Marc Becker
 */

const SITE_VIDEOS = [
    '70-seconds'   => 'mDvUbN42LOY', // "MAESTRO in 70 seconds": landing page hero
    'step-by-step' => 'A7Qw_CfcEiM', // "MAESTRO step by step", the full tutorial: "Getting started" tile on the portal
];

/** Address of the privacy-enhanced YouTube player for a video, started by the click that loads it. */
function site_video_embed(string $name): string
{
    return 'https://www.youtube-nocookie.com/embed/' . SITE_VIDEOS[$name] . '?autoplay=1&rel=0&playsinline=1';
}
