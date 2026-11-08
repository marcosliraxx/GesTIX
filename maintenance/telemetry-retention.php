<?php
/* Caminho: maintenance/telemetry-retention.php */
/**
 * Manutenção de telemetria Arcoll. CLI apenas; --dry-run (padrão) ou --execute.
 * Não limpa inventário atual, alertas, auditoria ou histórico de comandos.
 * Agende de hora em hora após inspecionar a execução de teste.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__).'/includes/bootstrap.php';
$execute = in_array('--execute', $argv, true);
$pdo = arc_db();
$table = arc_table('gestor_telemetry');
// Retenção de dados brutos. Preserva configurações, usuários, inventário atual,
// resultados de testes, alertas, comandos e logs de auditoria.
$policies = [
    ['server_metrics',3,'HOUR'],
    ['speed_progress',1,'DAY'],
    ['telemetry',30,'DAY'],
    ['dashboard',30,'DAY'],
    ['network_scan',45,'DAY'],
];
foreach ($policies as [$type,$age,$unit]) {
    // Constantes da política são controladas no código; os valores de dados são parametrizados.
    $cond = "event_type=? AND captured_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL $age $unit)";
    $count=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE $cond");
    $count->execute([$type]);
    $total=(int)$count->fetchColumn();
    echo "$type: $total registros elegíveis".($execute ? ' (limpeza)' : ' (simulação)').PHP_EOL;
    if (!$execute || !$total) continue;
    // Limita escrita/locks por execução: execução seguinte continua do ponto restante.
    $removed = 0;
    // Pequenos lotes com limite global por tipo evitam locks longos em produção.
    do {
        $delete=$pdo->prepare("DELETE FROM $table WHERE $cond ORDER BY id LIMIT 500");
        $delete->execute([$type]);
        $batch = $delete->rowCount();
        $removed += $batch;
        if ($batch === 500 && $removed < 5000) usleep(50000);
    } while ($batch === 500 && $removed < 5000);
    echo '  removidos nesta execução: '.$removed.PHP_EOL;
}
