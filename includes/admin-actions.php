<?php
declare(strict_types=1);
/** Caminho: includes/admin-actions.php — comandos da administração. */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503); exit('Instalação pendente.');
}
require __DIR__ . '/bootstrap.php';

function arc_admin_pw_material(string $password): string
{
    // bcrypt ignora tudo após 72 bytes; senhas longas passam antes por SHA-256 para que todos os caracteres contem.
    return strlen($password) > 72 ? base64_encode(hash('sha256', $password, true)) : $password;
}

function arc_admin_pw_hash(string $password): string
{
    return password_hash(arc_admin_pw_material($password), PASSWORD_DEFAULT);
}

function arc_admin_pw_check(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }
    if (password_verify(arc_admin_pw_material($password), $hash)) {
        return true;
    }
    // Compatibilidade: senhas longas criadas antes desta alteração (hash direto, truncado em 72 bytes).
    return strlen($password) > 72 && password_verify($password, $hash);
}

function arc_admin_flash(string $message, string $type = 'info'): void
{
    $_SESSION['arc_admin_flash'] = ['message' => $message, 'type' => $type];
}

function arc_admin_redirect(string $anchor = ''): never
{
    
    $views = ['identity','pages','fields','releases','migration','security'];
    $actionViews = ['save_settings'=>'identity','save_page'=>'pages','create_page'=>'pages','delete_page'=>'pages','save_field'=>'fields','delete_field'=>'fields','upload_release'=>'releases','delete_release'=>'releases','import_legacy'=>'migration','change_password'=>'security','update_user_account'=>'users'];
    $action = (string)($_POST['action'] ?? '');
    // Erros e retornos de salvamento de Configurações nunca vão para admin.php (inexistente).
    $currentScript = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($currentScript === 'configuracoes.php') {
        $tab = (string)($_POST['tab'] ?? 'dados');
        $allowedTabs = ['dados','designer','seguranca','smtp','apis','nuvem','login','log','manutencao'];
        if (!in_array($tab, $allowedTabs, true)) $tab = 'dados';
        header('Location: '.arc_workspace_base_url().'admin/configuracoes.php?tab='.rawurlencode($tab).'#'.rawurlencode($tab), true, 303);
        exit;
    }
    $view = in_array($anchor, $views, true) ? $anchor : ($actionViews[$action] ?? 'overview');
    $destination = ['identity'=>'configuracoes.php?tab=dados','security'=>'configuracoes.php?tab=seguranca','users'=>'clientes.php','releases'=>'gestorwin.php','overview'=>'painel.php'][$view] ?? 'painel.php';
    header('Location: ' . arc_workspace_base_url() . 'pages/' . $destination . ($anchor !== '' && $anchor!=='releases' ? '#' . rawurlencode($anchor) : ''), true, 303);
    exit;
}

function arc_admin_post(string $key, int $max = 255): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function arc_admin_csrf(): void
{
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(arc_csrf_token(), $sent)) {
        http_response_code(403);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

try {
    $pdo = arc_db();
} catch (Throwable $exception) {
    error_log('Arcoll admin database error: ' . $exception->getMessage());
    http_response_code(503);
    exit('Não foi possível conectar ao banco. Revise a configuração da instalação.');
}
arc_ensure_user_schema($pdo);
arc_v16_release_schema($pdo);
if (!arc_is_admin() && ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['action']??'') !== 'login')) {
    header('Location: ../index.php',true,303);
    exit;
}

// Os formulários específicos de Configurações são tratados pela própria página.
// O roteador geral continua responsável apenas por save_settings (identidade visual legada)
// e pelas ações administrativas globais. Manter esta lista alinhada aos handlers da página
// evita interceptar save_group (credenciais Drive) e descartar o POST antes de salvar.
$arcConfigLocalActions = [
    'save', 'save_group', 'upload_designer_icon', 'smtp_test',
    'upload_maintenance_image', 'save_log_policies', 'clear_logs',
    'delete_audit_rows', 'clear_cache',
];
$arcConfigLocalPost = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'configuracoes.php'
    && in_array((string)($_POST['action'] ?? 'save'), $arcConfigLocalActions, true);
// admin/clean.php processa seus proprios comandos com verificacao CSRF e JSON.
// Nao deixar o roteador geral interceptar esses POSTs e emitir HTML/redirect.
$arcCleanLocalPost = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'clean.php'
    && in_array((string)($_POST['action'] ?? ''), ['backup', 'logs_purge', 'retention_run', 'cache'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$arcConfigLocalPost && !$arcCleanLocalPost) {
    arc_admin_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login' && !arc_is_admin()) {
        $email = strtolower(arc_admin_post('email', 190));
        try {
            $loginAttemptId = arc_login_attempt_begin($pdo, $email);
        } catch (PDOException | RuntimeException $exception) {
            error_log('Arcoll admin login limiter: ' . $exception->getMessage());
            http_response_code(503);
            exit('Não foi possível concluir o acesso. Tente novamente mais tarde.');
        }
        if ($loginAttemptId === null) {
            arc_admin_flash('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.', 'warning');
        } else {
            $password = (string)($_POST['password'] ?? '');
            $query = $pdo->prepare('SELECT id, email, password_hash FROM ' . arc_table('admins') . ' WHERE email = ? LIMIT 1');
            $query->execute([$email]);
            $admin = $query->fetch();
            if ($admin && arc_admin_pw_check($password, (string)$admin['password_hash'])) {
                arc_login_attempt_succeeded($pdo, $loginAttemptId);
                session_regenerate_id(true);
                $_SESSION['arc_admin_id'] = (int)$admin['id'];
                $_SESSION['arc_admin_email'] = (string)$admin['email'];
                arc_audit($pdo, 'admin.login', 'Acesso administrativo iniciado.');
                arc_admin_flash('Acesso autorizado.', 'success');
            } else {
                usleep(250000);
                arc_admin_flash('E-mail ou senha incorretos.', 'error');
            }
        }
        arc_admin_redirect();
    }

    if (!arc_is_admin()) {
        http_response_code(401);
        exit('Acesso não autorizado.');
    }

    try {
        if ($action === 'logout') {
            arc_audit($pdo, 'admin.logout', 'Sessão administrativa encerrada.');
            unset($_SESSION['arc_admin_id'], $_SESSION['arc_admin_email']);
            session_regenerate_id(true);
            arc_admin_flash('Sessão encerrada.', 'success');
            arc_admin_redirect();
        }

        if ($action === 'update_user_account') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $status = arc_admin_post('account_status', 20);
            $role = arc_admin_post('access_role', 32);
            $notes = mb_substr(trim((string)($_POST['access_notes'] ?? '')), 0, 5000);
            if ($userId < 1 || !in_array($status, ['active', 'suspended', 'pending'], true) || !in_array($role, ['user', 'manager', 'viewer'], true)) {
                throw new RuntimeException('Status ou papel de acesso inválido.');
            }
            $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET account_status=?, access_role=?, access_notes=? WHERE id=?')
                ->execute([$status, $role, $notes, $userId]);
            arc_audit($pdo, 'user.account_updated', 'Conta de usuário ' . $userId . ' atualizada pelo administrador.');
            arc_admin_flash('Permissões e status do usuário atualizados.', 'success');
            arc_admin_redirect('users');
        }

        if ($action === 'save_settings') {
            // Salvar somente os campos da aba; preservar todos os demais parâmetros.
            $groups = [
                'dados' => ['site_name','tagline','domain_label','accent_color'],
                'designer' => ['accent_color','portal_heading_start','portal_heading_emphasis',
                    'feature_monitor_title','feature_monitor_text','feature_devices_title',
                    'feature_devices_text','feature_reports_title','feature_reports_text'],
                'retencao' => ['retention_days'],
            ];
            $tab = (string)($_POST['tab'] ?? 'legacy');
            $types = [
                'site_name'=>['text',80], 'tagline'=>['text',180], 'domain_label'=>['text',253],
                'accent_color'=>['color',7],
                'portal_heading_start'=>['text',100], 'portal_heading_emphasis'=>['text',100],
                'feature_monitor_title'=>['text',120], 'feature_monitor_text'=>['text',255],
                'feature_devices_title'=>['text',120], 'feature_devices_text'=>['text',255],
                'feature_reports_title'=>['text',120], 'feature_reports_text'=>['text',255],
                'retention_days'=>['number',8],
            ];
            $keys = $groups[$tab] ?? array_keys($types);
            $values = [];
            foreach ($keys as $key) {
                [$type,$limit] = $types[$key];
                $values[$key] = mb_substr(trim((string)($_POST[$key] ?? '')),0,$limit);
            }
            if (isset($values['site_name']) && $values['site_name']==='') throw new RuntimeException('Informe o nome do sistema.');
            
            if (isset($values['accent_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/',$values['accent_color'])) throw new RuntimeException('Cor inválida.');
            if (isset($values['retention_days'])) {
                $n=filter_var($values['retention_days'],FILTER_VALIDATE_INT);
                if ($n===false || $n<1 || $n>3650) throw new RuntimeException('Retenção inválida.');
            }
            $stmt=$pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type)');
            foreach($values as $key=>$value) $stmt->execute([$key,$value,$types[$key][0]]);
            if ($tab==='dados' || $tab==='legacy') {
                if (isset($_FILES['logo']) && (int)($_FILES['logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    if ((int)$_FILES['logo']['error']!==UPLOAD_ERR_OK || (int)$_FILES['logo']['size']>1572864) throw new RuntimeException('Logo inválida ou maior que 1,5 MB.');
                    $tmp=(string)$_FILES['logo']['tmp_name'];
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                    $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;
                    if(!$ext || !@getimagesize($tmp))throw new RuntimeException('Imagem inválida. Utilize PNG, JPG ou WebP.');
                    $name=bin2hex(random_bytes(20)).'.'.$ext;
                    if(!is_uploaded_file($tmp)||!move_uploaded_file($tmp,arc_upload_dir('brand').'/'.$name))throw new RuntimeException('Não foi possível gravar a logo.');
                    require_once __DIR__.'/media-optimizer.php';arc_optimize_uploaded_image(arc_upload_dir('brand').'/'.$name,1200);
                    $stmt->execute(['logo_path',$name,'text']);
                }
            }
            arc_audit($pdo,'settings.updated','Configurações da aba '.$tab.' atualizadas.');
            arc_admin_flash('Configurações salvas.','success');
            if (isset($groups[$tab])) {
                $destinationTab = $tab === 'retencao' ? 'manutencao' : $tab;
                header('Location: '.arc_workspace_base_url().'admin/configuracoes.php?tab='.$destinationTab.'#'.$destinationTab,true,303);
                exit;
            }
            arc_admin_redirect('identity');
        }

        if ($action === 'save_page') {
            $id = (int)($_POST['page_id'] ?? 0);
            $slug = arc_admin_post('slug', 64);
            $title = arc_admin_post('title', 120);
            $subtitle = arc_admin_post('subtitle', 255);
            $content = mb_substr(trim((string)($_POST['content'] ?? '')), 0, 20000);
            $icon = arc_admin_post('icon', 32);
            $screen = arc_admin_post('screen_id', 48);
            $type = arc_admin_post('page_type', 24);
            $order = max(-9999, min(9999, (int)($_POST['nav_order'] ?? 0)));
            $enabled = isset($_POST['is_enabled']) ? 1 : 0;
            $allowedScreens = ['', 'panel', 'devices', 'map', 'conn', 'diag', 'alerts', 'history', 'reports', 'nettools', 'tools', 'settings', 'setup'];
            if ($id < 1 || !preg_match('/^[a-z0-9_-]{1,64}$/', $slug) || $title === ''
                || !in_array($type, ['system', 'content'], true) || !in_array($screen, $allowedScreens, true)
                || ($type === 'content' && $screen !== '') || ($type === 'system' && $screen === '')) {
                throw new RuntimeException('Dados da página inválidos.');
            }
            $stmt = $pdo->prepare(
                'UPDATE ' . arc_table('pages') . ' SET title=?, subtitle=?, content=?, icon=?, screen_id=?, page_type=?, nav_order=?, is_enabled=? WHERE id=?'
            );
            $stmt->execute([$title, $subtitle, $content, $icon, $screen, $type, $order, $enabled, $id]);
            arc_audit($pdo, 'page.updated', 'Página ' . $slug . ' atualizada.');
            arc_admin_flash('Página atualizada no banco.', 'success');
            arc_admin_redirect('pages');
        }

        if ($action === 'create_page') {
            $slug = arc_admin_post('new_slug', 64);
            $title = arc_admin_post('new_title', 120);
            $subtitle = arc_admin_post('new_subtitle', 255);
            $content = mb_substr(trim((string)($_POST['new_content'] ?? '')), 0, 20000);
            if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug) || $title === '') {
                throw new RuntimeException('Informe um identificador simples e um título para a página.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ' . arc_table('pages') . ' (slug, title, subtitle, content, icon, screen_id, page_type, nav_order, is_enabled) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$slug, $title, $subtitle, $content, 'file-text', '', 'content', 100]);
            arc_audit($pdo, 'page.created', 'Página ' . $slug . ' criada.');
            arc_admin_flash('Nova página criada no banco.', 'success');
            arc_admin_redirect('pages');
        }

        if ($action === 'delete_page') {
            $id = (int)($_POST['page_id'] ?? 0);
            $query = $pdo->prepare('SELECT slug,page_type FROM ' . arc_table('pages') . ' WHERE id=?');
            $query->execute([$id]);
            $page = $query->fetch();
            if (!$page || $page['page_type'] !== 'content') {
                throw new RuntimeException('Somente páginas de conteúdo criadas no painel podem ser excluídas.');
            }
            $pdo->prepare('DELETE FROM ' . arc_table('pages') . ' WHERE id=?')->execute([$id]);
            arc_audit($pdo, 'page.deleted', 'Página de conteúdo ' . (string)$page['slug'] . ' removida.');
            arc_admin_flash('Página e campos associados removidos.', 'success');
            arc_admin_redirect('pages');
        }

        if ($action === 'save_field') {
            $id = (int)($_POST['field_id'] ?? 0);
            $pageId = (int)($_POST['page_id'] ?? 0);
            $key = arc_admin_post('field_key', 80);
            $label = arc_admin_post('field_label', 160);
            $type = arc_admin_post('field_type', 32);
            $optionsText = trim((string)($_POST['options_json'] ?? ''));
            $default = mb_substr((string)($_POST['default_value'] ?? ''), 0, 10000);
            $order = max(-9999, min(9999, (int)($_POST['sort_order'] ?? 0)));
            $allowedTypes = ['text', 'textarea', 'number', 'email', 'url', 'date', 'choice', 'toggle'];
            if ($pageId < 1 || !preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key) || $label === '' || !in_array($type, $allowedTypes, true)) {
                throw new RuntimeException('Revise a página, o identificador, o rótulo e o tipo do campo.');
            }
            $options = null;
            if ($type === 'choice') {
                $decoded = json_decode($optionsText, true);
                if (!is_array($decoded) || count($decoded) < 1 || count($decoded) > 30) {
                    throw new RuntimeException('As opções devem ser uma lista JSON com até 30 itens.');
                }
                $options = json_encode(
                    array_values(array_map(static fn($item) => mb_substr((string)$item, 0, 100), $decoded)),
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }
            $required = isset($_POST['is_required']) ? 1 : 0;
            $enabled = isset($_POST['field_enabled']) ? 1 : 0;
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE ' . arc_table('page_fields') . ' SET page_id=?, field_key=?, label=?, field_type=?, is_required=?, options_json=?, default_value=?, sort_order=?, is_enabled=? WHERE id=?'
                );
                $stmt->execute([$pageId, $key, $label, $type, $required, $options, $default, $order, $enabled, $id]);
                arc_audit($pdo, 'page_field.updated', 'Campo ' . $key . ' atualizado.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . arc_table('page_fields') . ' (page_id, field_key, label, field_type, is_required, options_json, default_value, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$pageId, $key, $label, $type, $required, $options, $default, $order, $enabled]);
                arc_audit($pdo, 'page_field.created', 'Campo ' . $key . ' criado.');
            }
            arc_admin_flash('Campo atualizado no banco.', 'success');
            arc_admin_redirect('fields');
        }

        if ($action === 'delete_field') {
            $id = (int)($_POST['field_id'] ?? 0);
            $pdo->prepare('DELETE FROM ' . arc_table('page_fields') . ' WHERE id=?')->execute([$id]);
            arc_audit($pdo, 'page_field.deleted', 'Campo de página removido.');
            arc_admin_flash('Campo removido.', 'success');
            arc_admin_redirect('fields');
        }

        if ($action === 'upload_release') {
            $version = arc_app_version();
            $platform = arc_admin_post('platform', 24);
            $file = $_FILES['release_file'] ?? null;
            $platforms = ['web', 'windows', 'android', 'macos', 'linux'];
            $extensions = [
                'web' => ['html', 'zip'], 'windows' => ['exe', 'msi', 'zip'],
                'android' => ['apk', 'zip'], 'macos' => ['dmg', 'pkg', 'zip'],
                'linux' => ['zip', 'gz'],
            ];
            if (!preg_match('/^[A-Za-z0-9.+_-]{1,32}$/', $version) || !in_array($platform, $platforms, true)
                || !is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int)$file['size'] < 1 || (int)$file['size'] > 104857600) {
                throw new RuntimeException('Versão, plataforma ou arquivo inválido. O limite é 100 MB.');
            }
            $notes=mb_substr(trim((string)($_POST['release_notes']??'')),0,3000);
            $channel=(string)($_POST['release_channel']??'stable');
            if(!in_array($channel,['stable','beta'],true))throw new RuntimeException('Canal de versão inválido.');
            $original = basename((string)$file['name']);
            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions[$platform], true)) {
                throw new RuntimeException('A extensão do arquivo não corresponde à plataforma selecionada.');
            }
            $id = bin2hex(random_bytes(12));
            $path = arc_upload_dir('releases') . '/' . $id . '.bin';
            if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
                throw new RuntimeException('Não foi possível salvar o arquivo enviado.');
            }
            if($extension==='zip'){
                $archive=new ZipArchive();
                if($archive->open($path)!==true){@unlink($path);throw new RuntimeException('ZIP inválido.');}
                if($archive->numFiles>3000){$archive->close();@unlink($path);throw new RuntimeException('ZIP com arquivos demais.');}
                for($i=0;$i<$archive->numFiles;$i++){
                    $entry=$archive->getNameIndex($i);
                    if(!is_string($entry)||str_contains($entry,'\\')||str_starts_with($entry,'/')||preg_match('~(^|/)\.\.(/|$)~',$entry)){
                        $archive->close();@unlink($path);throw new RuntimeException('ZIP contém caminho inválido.');
                    }
                }
                $archive->close();
            }
            $sha = hash_file('sha256', $path);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
            $pdo->prepare(
                'INSERT INTO ' . arc_table('releases') . ' (id, version, platform, original_name, stored_name, mime_type, file_size, sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $version, $platform, $original, $id . '.bin', $mime, filesize($path), $sha]);
            $pdo->prepare('INSERT INTO '.arc_table('gestor_release_metadata').' (release_id,notes,channel) VALUES (?,?,?)')->execute([$id,$notes,$channel]);
            arc_audit($pdo, 'release.created', 'Arquivo publicado: ' . $version . ' (' . $platform . ').');
            arc_admin_flash('Arquivo publicado e disponível para download.', 'success');
            arc_admin_redirect('releases');
        }

        if ($action === 'delete_release') {
            $id = arc_admin_post('release_id', 24);
            if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
                throw new RuntimeException('Identificador de arquivo inválido.');
            }
            $query = $pdo->prepare('SELECT version FROM ' . arc_table('releases') . ' WHERE id=?');
            $query->execute([$id]);
            $version = $query->fetchColumn();
            if ($version !== false) {
                @unlink(arc_upload_dir('releases') . '/' . $id . '.bin');
                $pdo->prepare('DELETE FROM '.arc_table('gestor_release_metadata').' WHERE release_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM ' . arc_table('releases') . ' WHERE id=?')->execute([$id]);
                arc_audit($pdo, 'release.deleted', 'Arquivo ' . (string)$version . ' removido.');
            }
            arc_admin_flash($version === false ? 'Arquivo não encontrado.' : 'Arquivo removido.', $version === false ? 'warning' : 'success');
            arc_admin_redirect('releases');
        }

        if ($action === 'import_legacy') {
            $legacyPrefix = trim((string)($_POST['legacy_prefix'] ?? 'arc_'));
            if (!preg_match('/^[A-Za-z0-9_]{1,18}$/', $legacyPrefix) || $legacyPrefix === arc_prefix()) {
                throw new RuntimeException('Informe um prefixo legado diferente do prefixo atual.');
            }
            $tableExists = static function (string $table) use ($pdo): bool {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
                );
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
            };
            if (!$tableExists($legacyPrefix . 'settings') || !$tableExists($legacyPrefix . 'pages')) {
                throw new RuntimeException('Não encontrei as tabelas de configurações e páginas com esse prefixo no banco atual.');
            }
            $oldTable = static fn(string $suffix): string => '`' . $legacyPrefix . $suffix . '`';
            $countsImported = ['settings' => 0, 'pages' => 0, 'fields' => 0, 'samples' => 0, 'devices' => 0, 'alerts' => 0];
            $pdo->beginTransaction();
            try {
                $saveSetting = $pdo->prepare(
                    'INSERT INTO ' . arc_table('settings') . ' (setting_key, setting_value, value_type) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), value_type=VALUES(value_type)'
                );
                foreach ($pdo->query('SELECT setting_key, setting_value, value_type FROM ' . $oldTable('settings'))->fetchAll() as $row) {
                    $key = (string)$row['setting_key'];
                    if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key)) continue;
                    $saveSetting->execute([$key, (string)$row['setting_value'], (string)($row['value_type'] ?? 'text')]);
                    $countsImported['settings']++;
                }

                $screenMap = [
                    'panel' => 'panel', 'devices' => 'devices', 'map' => 'map', 'conn' => 'conn',
                    'diag' => 'diag', 'alerts' => 'alerts', 'history' => 'history', 'reports' => 'reports',
                    'nettools' => 'nettools', 'tools' => 'tools', 'settings' => 'settings', 'install' => 'setup',
                ];
                $savePage = $pdo->prepare(
                    'INSERT INTO ' . arc_table('pages') . ' (slug,title,subtitle,content,icon,screen_id,page_type,nav_order,is_enabled) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),subtitle=VALUES(subtitle),content=VALUES(content),'
                    . 'icon=VALUES(icon),screen_id=VALUES(screen_id),page_type=VALUES(page_type),nav_order=VALUES(nav_order),is_enabled=VALUES(is_enabled)'
                );
                $legacyPages = $pdo->query(
                    'SELECT slug,title,subtitle,content,icon,nav_order,is_enabled FROM ' . $oldTable('pages') . ' ORDER BY nav_order,id'
                )->fetchAll();
                foreach ($legacyPages as $row) {
                    $slug = (string)$row['slug'];
                    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $slug)) continue;
                    $systemScreen = $screenMap[$slug] ?? '';
                    $savePage->execute([
                        $slug, mb_substr((string)$row['title'], 0, 120), mb_substr((string)($row['subtitle'] ?? ''), 0, 255),
                        mb_substr((string)($row['content'] ?? ''), 0, 20000), mb_substr((string)($row['icon'] ?? ''), 0, 32),
                        $systemScreen, $systemScreen === '' ? 'content' : 'system',
                        (int)($row['nav_order'] ?? 0), !empty($row['is_enabled']) ? 1 : 0,
                    ]);
                    $countsImported['pages']++;
                }

                if ($tableExists($legacyPrefix . 'page_fields')) {
                    $legacyFields = $pdo->query(
                        'SELECT p.slug,f.field_key,f.label,f.field_type,f.is_required,f.options_json,f.default_value,f.sort_order,f.is_enabled '
                        . 'FROM ' . $oldTable('page_fields') . ' f JOIN ' . $oldTable('pages') . ' p ON p.id=f.page_id'
                    )->fetchAll();
                    $findPage = $pdo->prepare('SELECT id FROM ' . arc_table('pages') . ' WHERE slug=?');
                    $saveField = $pdo->prepare(
                        'INSERT INTO ' . arc_table('page_fields') . ' (page_id,field_key,label,field_type,is_required,options_json,default_value,sort_order,is_enabled) '
                        . 'VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),'
                        . 'is_required=VALUES(is_required),options_json=VALUES(options_json),default_value=VALUES(default_value),'
                        . 'sort_order=VALUES(sort_order),is_enabled=VALUES(is_enabled)'
                    );
                    $allowedTypes = ['text','textarea','number','email','url','date','choice','toggle'];
                    foreach ($legacyFields as $row) {
                        $key = (string)$row['field_key'];
                        if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key)) continue;
                        $findPage->execute([(string)$row['slug']]);
                        $pageId = (int)$findPage->fetchColumn();
                        if ($pageId < 1) continue;
                        $type = in_array((string)$row['field_type'], $allowedTypes, true) ? (string)$row['field_type'] : 'text';
                        $saveField->execute([
                            $pageId, $key, mb_substr((string)$row['label'], 0, 160), $type,
                            !empty($row['is_required']) ? 1 : 0, $row['options_json'],
                            isset($row['default_value']) ? mb_substr((string)$row['default_value'], 0, 10000) : null,
                            (int)($row['sort_order'] ?? 0), !empty($row['is_enabled']) ? 1 : 0,
                        ]);
                        $countsImported['fields']++;
                    }
                }

                if ($tableExists($legacyPrefix . 'sessions')) {
                    $sessionInsert = $pdo->prepare(
                        'INSERT IGNORE INTO ' . arc_table('sessions') . ' (id,started_at_ms,last_seen_at,created_at) VALUES (?,?,?,?)'
                    );
                    foreach ($pdo->query('SELECT id,started_at_ms,last_seen_at,created_at FROM ' . $oldTable('sessions'))->fetchAll() as $row) {
                        $sessionInsert->execute([$row['id'], $row['started_at_ms'], $row['last_seen_at'], $row['created_at']]);
                    }
                }
                if ($tableExists($legacyPrefix . 'preferences')) {
                    $prefInsert = $pdo->prepare(
                        'INSERT INTO ' . arc_table('preferences') . ' (session_id,preference_key,preference_value) VALUES (?,?,?) '
                        . 'ON DUPLICATE KEY UPDATE preference_value=VALUES(preference_value)'
                    );
                    foreach ($pdo->query('SELECT session_id,preference_key,preference_value FROM ' . $oldTable('preferences'))->fetchAll() as $row) {
                        $prefInsert->execute([$row['session_id'], $row['preference_key'], $row['preference_value']]);
                    }
                }
                if ($tableExists($legacyPrefix . 'samples')) {
                    $insert = $pdo->prepare(
                        'INSERT INTO ' . arc_table('samples') . ' (session_id,client_id,captured_at_ms,is_success,latency_ms,jitter_ms,download_mbps,upload_mbps,loss_percent,quality_percent,detail) '
                        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_success=VALUES(is_success),latency_ms=VALUES(latency_ms),'
                        . 'jitter_ms=VALUES(jitter_ms),download_mbps=VALUES(download_mbps),upload_mbps=VALUES(upload_mbps),'
                        . 'loss_percent=VALUES(loss_percent),quality_percent=VALUES(quality_percent),detail=VALUES(detail)'
                    );
                    foreach ($pdo->query('SELECT session_id,client_id,captured_at_ms,is_success,latency_ms,jitter_ms,download_mbps,upload_mbps,loss_percent,quality_percent,detail FROM ' . $oldTable('samples'))->fetchAll() as $row) {
                        $insert->execute(array_values($row));
                        $countsImported['samples']++;
                    }
                }
                if ($tableExists($legacyPrefix . 'devices')) {
                    $insert = $pdo->prepare(
                        'INSERT INTO ' . arc_table('devices') . ' (session_id,device_key,device_name,alias,ip_address,mac_address,device_type,source,status,ping_ms,link_mbps,is_trusted,first_seen_ms,last_seen_ms,extra_json) '
                        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),alias=VALUES(alias),'
                        . 'ip_address=VALUES(ip_address),mac_address=VALUES(mac_address),device_type=VALUES(device_type),source=VALUES(source),'
                        . 'status=VALUES(status),ping_ms=VALUES(ping_ms),link_mbps=VALUES(link_mbps),is_trusted=VALUES(is_trusted),'
                        . 'first_seen_ms=VALUES(first_seen_ms),last_seen_ms=VALUES(last_seen_ms),extra_json=VALUES(extra_json)'
                    );
                    foreach ($pdo->query('SELECT session_id,device_key,device_name,alias,ip_address,mac_address,device_type,source,status,ping_ms,link_mbps,is_trusted,first_seen_ms,last_seen_ms,extra_json FROM ' . $oldTable('devices'))->fetchAll() as $row) {
                        $insert->execute(array_values($row));
                        $countsImported['devices']++;
                    }
                }
                if ($tableExists($legacyPrefix . 'alerts')) {
                    $insert = $pdo->prepare(
                        'INSERT IGNORE INTO ' . arc_table('alerts') . ' (session_id,client_id,created_at_ms,level,message,is_read) VALUES (?,?,?,?,?,?)'
                    );
                    foreach ($pdo->query('SELECT session_id,client_id,created_at_ms,level,message,is_read FROM ' . $oldTable('alerts'))->fetchAll() as $row) {
                        $insert->execute(array_values($row));
                        $countsImported['alerts']++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            arc_audit($pdo, 'legacy.imported', 'Dados legados importados do prefixo ' . $legacyPrefix . '.');
            $summary = [];
            foreach ($countsImported as $key => $value) $summary[] = $key . ': ' . $value;
            arc_admin_flash('Importação concluída. ' . implode(' · ', $summary) . '. Administradores e arquivos privados não foram copiados.', 'success');
            arc_admin_redirect('migration');
        }

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $next = (string)($_POST['new_password'] ?? '');
            $query = $pdo->prepare('SELECT password_hash FROM ' . arc_table('admins') . ' WHERE id=?');
            $query->execute([arc_admin_id()]);
            $hash = (string)$query->fetchColumn();
            if (!arc_admin_pw_check($current, $hash)) {
                throw new RuntimeException('A senha atual não confere.');
            }
            if ($next === '') {
                throw new RuntimeException('Informe a nova senha.');
            }
            $pdo->prepare('UPDATE ' . arc_table('admins') . ' SET password_hash=? WHERE id=?')->execute([arc_admin_pw_hash($next), arc_admin_id()]);
            session_regenerate_id(true);
            arc_audit($pdo, 'admin.password_changed', 'Senha administrativa alterada.');
            arc_admin_flash('Senha atualizada.', 'success');
            arc_admin_redirect('security');
        }
        throw new RuntimeException('Ação administrativa não reconhecida.');
    } catch (Throwable $exception) {
        error_log('Arcoll admin action error: ' . $exception->getMessage());
        arc_admin_flash($exception instanceof RuntimeException ? $exception->getMessage() : 'A ação não foi concluída. Revise os dados informados.', 'error');
        arc_admin_redirect();
    }
}

$flash = $_SESSION['arc_admin_flash'] ?? null;
unset($_SESSION['arc_admin_flash']);
$login = !arc_is_admin();
$csrf = arc_csrf_token();
$baseUrl = arc_url();
$adminSettingsValues = arc_setting_values($pdo);
$brandName = (string)($adminSettingsValues['site_name'] ?? 'Arcoll Speed Connection');
$brandFile = trim((string)($adminSettingsValues['logo_path'] ?? ''));
$brandVersion = $brandFile !== '' ? substr(hash('sha256', $brandFile), 0, 12) : 'default';
$adminLogoUrl = $brandFile !== '' ? '../api/brand-logo.php?v=' . rawurlencode($brandVersion) : 'assets/img/arcoll-mark.svg';
