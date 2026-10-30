<?php
/* Caminho: api/brand-logo.php */
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';
try {
    $name = trim((string)(arc_setting_values(arc_db())['logo_path'] ?? ''));
    if ($name === '' || basename($name) !== $name || !preg_match('/^[a-f0-9]{32,40}\.(png|jpg|webp)$/', $name)) {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile(__DIR__ . '/../assets/img/app-mark.svg');
        exit;
    }
    $path = arc_upload_dir('brand') . '/' . $name;
    if (!is_file($path)) {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile(__DIR__ . '/../assets/img/app-mark.svg');
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        http_response_code(404);
        exit;
    }
    header('X-Content-Type-Options: nosniff');
    $iconSize = (int)($_GET['icon'] ?? 0);
    if ($iconSize > 0 && in_array($iconSize, [192, 512], true) && function_exists('imagecreatefromstring')) {
        $source = @imagecreatefromstring((string)file_get_contents($path));
        if ($source !== false) {
            $canvas = imagecreatetruecolor($iconSize, $iconSize);
            imagealphablending($canvas, false); imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127); imagefilledrectangle($canvas, 0, 0, $iconSize, $iconSize, $transparent);
            $sw = imagesx($source); $sh = imagesy($source); $max = (int)floor($iconSize * 0.82);
            $scale = min($max / max(1, $sw), $max / max(1, $sh));
            $dw = max(1, (int)round($sw * $scale)); $dh = max(1, (int)round($sh * $scale));
            imagecopyresampled($canvas, $source, (int)(($iconSize - $dw) / 2), (int)(($iconSize - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
            header('Content-Type: image/png'); header('Cache-Control: no-cache, must-revalidate'); ob_start(); imagepng($canvas); $png = ob_get_clean(); header('Content-Length: ' . strlen($png)); echo $png;
            imagedestroy($source); imagedestroy($canvas); exit;
        }
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-cache, must-revalidate');
    header('ETag: "' . hash_file('sha256', $path) . '"');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
} catch (Throwable $exception) {
    error_log('Arcoll logo error: ' . $exception->getMessage());
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile(__DIR__ . '/../assets/img/app-mark.svg');
}
