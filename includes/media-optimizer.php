<?php
/* Caminho: includes/media-optimizer.php | v1.0.0
 * Compactacao conservadora de JPG/PNG/WebP ja validados no upload.
 * Se GD nao estiver disponivel ou resultado nao for menor, preserva o original.
 */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';

function arc_optimize_uploaded_image(string $path, int $maxSide=1024): bool {
    if (!is_file($path) || is_link($path) || !function_exists('imagecreatefromstring')) return false;
    $sourceBytes=@filesize($path);
    if (!$sourceBytes || $sourceBytes>12*1048576) return false;
    $info=@getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) return false;
    [$width,$height]=$info;
    if ($width*$height>16000000) return false; // evita excesso de memoria
    $type=(int)$info[2];
    if (!in_array($type,[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) return false;
    $bytes=@file_get_contents($path);
    if (!is_string($bytes)) return false;
    $image=@imagecreatefromstring($bytes);
    if (!$image) return false;
    $maxSide=max(96,min(2400,$maxSide));
    $scale=min(1,$maxSide/max($width,$height));
    $w=max(1,(int)round($width*$scale));$h=max(1,(int)round($height*$scale));
    if ($scale<1) {
        $canvas=imagecreatetruecolor($w,$h);
        if (!$canvas) {imagedestroy($image);return false;}
        imagealphablending($canvas,false);imagesavealpha($canvas,true);
        imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));
        imagecopyresampled($canvas,$image,0,0,0,0,$w,$h,$width,$height);
        imagedestroy($image);$image=$canvas;
    }
    $tmp=tempnam(dirname($path),'.opt-');
    if ($tmp===false) {imagedestroy($image);return false;}
    $saved=false;
    try {
        if ($type===IMAGETYPE_JPEG) $saved=imagejpeg($image,$tmp,86);
        elseif ($type===IMAGETYPE_PNG) $saved=imagepng($image,$tmp,8);
        elseif (function_exists('imagewebp')) $saved=imagewebp($image,$tmp,85);
        imagedestroy($image);
        // somente substitui quando economiza ao menos 5%: evita trabalho sem ganho.
        if ($saved && is_file($tmp) && filesize($tmp)<$sourceBytes*0.95) {
            @chmod($tmp,fileperms($path)&0777);
            if (@rename($tmp,$path)) return true;
        }
    } catch (Throwable $e) { arc_log_error('Otimização de imagem: '.$e->getMessage()); }
    @unlink($tmp);
    return false;
}
