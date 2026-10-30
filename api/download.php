<?php
/* Caminho: api/download.php */
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    http_response_code(503);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
try {
    $query = arc_db()->prepare('SELECT * FROM ' . arc_table('releases') . ' WHERE id=? AND is_active=1 LIMIT 1');
    $query->execute([$id]);
    $release = $query->fetch();
    if (!$release) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }
    $path = arc_upload_dir('releases') . '/' . $id . '.bin';
    if (!is_file($path) || !hash_equals((string)$release['sha256'], (string)hash_file('sha256', $path))) {
        http_response_code(404);
        exit('O arquivo não está disponível ou não passou na verificação de integridade.');
    }
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)$release['original_name'])) ?: 'download.bin';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode((string)$release['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $exception) {
    error_log('Arcoll download error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Não foi possível concluir o download.');
}
