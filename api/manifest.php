<?php
/* Caminho: api/manifest.php */
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(503);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';
try {
    // O manifesto precisa ser público: o navegador o consulta sem depender da sessão.
    // As páginas e APIs privadas continuam protegidas por autenticação.
    $settings = arc_public_settings(arc_db());
} catch (Throwable $exception) {
    error_log('Arcoll manifest error: ' . $exception->getMessage());
    http_response_code(503);
    exit;
}
$name = (string)($settings['site_name'] ?? 'Arcoll Speed Connection');
$logoName = trim((string)($settings['logo_path'] ?? ''));
$logoVersion = $logoName !== '' ? substr(hash('sha256', $logoName), 0, 12) : '';
$icon192 = $logoName !== '' ? arc_url('api/brand-logo.php?icon=192&v=' . rawurlencode($logoVersion)) : arc_url('assets/img/app-icon-192.png');
$icon512 = $logoName !== '' ? arc_url('api/brand-logo.php?icon=512&v=' . rawurlencode($logoVersion)) : arc_url('assets/img/app-icon-512.png');
$iconType = 'image/png'; $iconSizes = ['192x192','512x512'];
if ($logoName !== '' && !function_exists('imagecreatefromstring')) {
    $extension = strtolower(pathinfo($logoName, PATHINFO_EXTENSION));
    $iconType = ['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$extension] ?? 'image/png';
    $iconSizes = ['any','any'];
}
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'id' => arc_url('pwa/app.php'),
    'name' => $name,
    'version' => arc_app_version(),
    'short_name' => mb_substr($name, 0, 16),
    'description' => (string)($settings['tagline'] ?? ''),
    'lang' => 'pt-BR',
    'start_url' => arc_url('pages/painel.php'),
    'scope' => arc_base_url() . '/',
    'display' => 'standalone',
    'prefer_related_applications' => false,
    'related_applications' => [['platform' => 'webapp', 'url' => arc_url('manifest.webmanifest')]],
    'handle_links' => 'preferred',
    'launch_handler' => ['client_mode' => 'navigate-existing'],
    'background_color' => '#eef2f8',
    'theme_color' => (string)($settings['accent_color'] ?? '#22d3ee'),
    'icons' => [
        ['src' => $icon192, 'sizes' => $iconSizes[0], 'type' => $iconType, 'purpose' => 'any'],
        ['src' => $icon512, 'sizes' => $iconSizes[1], 'type' => $iconType, 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);