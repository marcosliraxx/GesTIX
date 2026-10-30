<?php
/* Caminho: pwa/app.php */
declare(strict_types=1);
// Keep the installed PWA identity and old launch URLs on the shared application shell.
require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('Location: '.arc_workspace_base_url().'pages/painel.php', true, 302);
exit;
