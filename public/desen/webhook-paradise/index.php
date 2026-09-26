<?php
// Webhook da Paradise Pags — repassa para o handler real em /api/webhook-paradise.php
//
// ATENÇÃO ao número de níveis: este arquivo fica em /desen/webhook-paradise/, e
// a pasta api/ fica na RAIZ do site, não dentro de /desen/. Com um "../" só o
// require apontaria para /desen/api/, que não existe, e o receptor morreria
// antes de processar qualquer confirmação de pagamento. É a mesma armadilha
// que já pegou o receptor da Genesys em /desen/webhook/index.php.
require __DIR__ . '/../../api/webhook-paradise.php';
