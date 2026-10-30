<?php
// Modelo apenas. Copie para config.php e preencha no servidor de destino.
// Nunca envie config.php ao Git.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'gesti_dev',
    'db_user' => 'SEU_USUARIO_LOCAL',
    'db_password' => '',
    'table_prefix' => 'arc_',
    'app_secret' => '', // Gere com: php -r 'echo bin2hex(random_bytes(32));'
];
