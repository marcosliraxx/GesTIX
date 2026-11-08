<?php
/* Caminho: api/network-logo.php */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
if (!arc_is_user() && !arc_is_admin()) { http_response_code(403); exit; }
$pdo = arc_db();
arc_ensure_user_schema($pdo);
$id = (int)($_GET['network'] ?? 0);
if (arc_is_admin()) {
    $stmt = $pdo->prepare('SELECT logo_file FROM ' . arc_table('managed_networks') . ' WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare('SELECT logo_file FROM ' . arc_table('managed_networks') . ' n WHERE n.id=? AND (n.account_id=? OR EXISTS (SELECT 1 FROM ' . arc_table('network_team_members') . ' m WHERE m.network_id=n.id AND m.member_user_id=? AND m.enabled=1)) LIMIT 1');
    $stmt->execute([$id, arc_user_id(), arc_user_id()]);
}
$file = $stmt->fetchColumn();
if (!is_string($file) || !preg_match('/^[a-f0-9]{40}\\.(png|jpg|webp)$/', $file)) { http_response_code(404); exit; }
$path = ARC_PRIVATE_DIR . '/storage/network-logos/' . $file;
if (!is_file($path)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
