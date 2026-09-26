<?php
// Webhook da Genesys — repassa para o handler real em /api/webhook.php
//
// ATENÇÃO ao número de níveis: este arquivo fica em /desen/webhook/, e a pasta
// api/ fica na RAIZ do site, não dentro de /desen/. Com um "../" só o require
// apontava para /desen/api/webhook.php, que não existe, e o receptor morria
// antes de processar qualquer confirmação de pagamento.
require __DIR__ . '/../../api/webhook.php';
