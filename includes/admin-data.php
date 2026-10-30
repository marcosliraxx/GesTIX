<?php
$settings = arc_settings($pdo);
$pages = $pdo->query('SELECT * FROM ' . arc_table('pages') . ' ORDER BY nav_order, id')->fetchAll();
$fields = $pdo->query(
    'SELECT f.*, p.slug AS page_slug, p.title AS page_title FROM ' . arc_table('page_fields')
    . ' f JOIN ' . arc_table('pages') . ' p ON p.id=f.page_id ORDER BY p.nav_order, f.sort_order, f.id'
)->fetchAll();
$releases = $pdo->query('SELECT * FROM ' . arc_table('releases') . ' ORDER BY created_at DESC')->fetchAll();
$userAccounts = $pdo->query('SELECT u.*, (SELECT COUNT(*) FROM ' . arc_table('managed_networks') . ' n WHERE n.account_id=u.id) AS network_count FROM ' . arc_table('user_accounts') . ' u ORDER BY u.created_at DESC')->fetchAll();
$managedNetworks = $pdo->query('SELECT n.*, u.email AS owner_email, u.display_name AS owner_name FROM ' . arc_table('managed_networks') . ' n LEFT JOIN ' . arc_table('user_accounts') . ' u ON u.id=n.account_id ORDER BY n.created_at DESC')->fetchAll();
$counts = [];
foreach (['samples', 'devices', 'alerts', 'sessions', 'pages'] as $table) {
    $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . arc_table($table))->fetchColumn();
}
$setting = static fn(string $key, string $default = ''): string => (string)($settings[$key]['value'] ?? $default);
$typeChoices = ['text', 'textarea', 'number', 'email', 'url', 'date', 'choice', 'toggle'];
