<?php
/* Caminho: includes/gestor-presence.php — política comum ao cabeçalho, páginas e APIs. */
declare(strict_types=1);
require_once __DIR__.'/error-logging.php';

/** Mesmo período para web e gestor: tolera três heartbeats de 15 segundos. */
function arc_inventory_presence_seconds(): int {
    static $seconds = null;
    if ($seconds === null) {
        $seconds = 45;
        try { $seconds = max(1, min(1000, (int)arc_platform_setting(arc_db(), 'platform_gestor_presence_seconds', '45'))); }
        catch (Throwable $e) { arc_log_error('Arcoll presença: ' . $e->getMessage()); }
    }
    return $seconds;
}

