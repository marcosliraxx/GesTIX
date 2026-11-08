<?php
/* Caminho: api/program-logo.php — logo de um programa do catálogo.
   Ordem: 1) PNG embutido em assets/program-logos (sem depender de internet); 2) favicon do site oficial em cache; 3) monograma colorido. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require __DIR__ . '/../includes/bootstrap.php';
if (!arc_is_user()) { http_response_code(401); exit; }
$id = (string)($_GET['id'] ?? '');
$domain = arc_program_logo_domains()[$id] ?? '';
$name = '';
foreach (arc_program_install_catalog() as $row) if ($row['id'] === $id) $name = (string)$row['name'];
function arc_program_logo_badge(string $name, string $id): string
{
    // Iniciais por palavra ou CamelCase: WinRAR => WR, SumatraPDF => SP, Paint.NET => PN.
    $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
    $parts = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $text = count($parts) > 1 ? strtoupper($parts[0][0] . $parts[1][0]) : strtoupper(substr($parts[0] ?? '?', 0, 2));
    $colors = ['#1f6feb', '#2ea043', '#bf8700', '#8957e5', '#d1242f', '#0969da', '#8250df', '#1a7f37', '#0a7ea4', '#bc4c00'];
    $bg = $colors[hexdec(substr(hash('sha256', $id), 0, 2)) % count($colors)];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="' . $bg . '"/><text x="16" y="20.5" font-size="12" text-anchor="middle" fill="#fff" font-family="Segoe UI,Arial" font-weight="700">' . htmlspecialchars($text, ENT_XML1) . '</text></svg>';
}
header('X-Content-Type-Options: nosniff');
$bundled = arc_program_logo_file($id);
if ($bundled !== '') {
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=2592000');
    readfile($bundled);
    exit;
}
if ($domain !== '') {
    $dir = ARC_PRIVATE_DIR . '/storage/program-logos';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . preg_replace('/[^a-z0-9.-]/', '_', strtolower($domain)) . '.png';
    if (!is_file($file) || filesize($file) < 100 || filemtime($file) < time() - 30 * 86400) {
        $png = '';
        $url = 'https://www.google.com/s2/favicons?sz=64&domain=' . rawurlencode($domain);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'ArcollPlatform']);
            $png = (string)curl_exec($ch);
            curl_close($ch);
        } else {
            $png = (string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6]]));
        }
        if (strncmp($png, "\x89PNG\r\n\x1a\n", 8) === 0 && strlen($png) > 100 && strlen($png) < 200000) @file_put_contents($file, $png, LOCK_EX);
    }
    if (is_file($file) && filesize($file) > 100) {
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=604800');
        readfile($file);
        exit;
    }
}
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: private, max-age=300');
echo arc_program_logo_badge($name !== '' ? $name : $id, $id);
