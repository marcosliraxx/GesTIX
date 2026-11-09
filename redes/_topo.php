<?php
/* ARQUIVO: /redes/_topo.php */

require_once __DIR__ . '/../includes/error-logging.php';
require dirname(__DIR__).'/includes/header.php';
require dirname(__DIR__).'/includes/sidebar.php';
?>
<link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url().'assets/gestao/workspace.css')?>">
<main class="app-main arc-redes-page"><div class="container-wide arc-redes-shell" style=" max-width: none;">

<?php require __DIR__.'/_menu.php'; ?>
<div class="arc-command-log" id="arc-command-log" role="status" aria-live="polite" aria-atomic="true" hidden></div>
<div class="gw-toolbar" id="gw-toolbar"><span class="gw-toolbar-title" id="gw-page-title"></span><div class="gw-page-actions" id="gw-page-actions"></div><button type="button" class="gw-icon" id="gw-activities-toggle" title="Atividades" aria-label="Atividades" aria-pressed="false" aria-controls="gw-activities"><i class="fa fa-exclamation-circle" aria-hidden="true"></i></button></div>
<div class="gw-body" id="gw-body"><div class="arc-redes-conteudo" id="arc-tab-scroll">

