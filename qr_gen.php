<?php
require_once __DIR__ . '/phpqrcode.php';
header('Content-Type: image/png');
$url = $_GET['url'] ?? '';
if (!$url) {
    $img = imagecreatetruecolor(128, 128);
    $bg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $bg);
    imagepng($img);
    imagedestroy($img);
    exit;
}
QRcode::png($url, false, QR_ECLEVEL_L, 4, 0);
