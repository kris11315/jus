<?php
// Teste da API de Conversões. Só roda pela linha de comando:
//   php api/meta-teste.php
// Cria uma transação falsa no cache, manda um Purchase com o
// META_TEST_EVENT_CODE configurado e mostra a resposta da Meta.
// Confira depois na aba "Testar eventos" do Gerenciador de Eventos.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/genesys.php';
require_once __DIR__ . '/utmify-lib.php';
require_once __DIR__ . '/meta-capi.php';

if (META_TEST_EVENT_CODE === '') { // o teste CLI usa utm_source=teste, então o código se aplica
    fwrite(STDERR, "META_TEST_EVENT_CODE vazio: preencha antes de testar, senão o evento entra em produção.\n");
    exit(1);
}

$txId = 'teste-capi-' . time();

genesys_tx_save($txId, array(
    'id'        => $txId,
    'amount'    => 6892,
    'product'   => 'Pilates em Casa',
    'etapa'     => 'front',
    'customer'  => array('name' => 'Teste Capi', 'email' => '', 'phone' => '', 'document' => '12345678909'),
    'utm'       => array('utm_source' => 'teste'),
    'meta'      => array(
        'fbp'       => 'fb.1.' . (time() * 1000) . '.1234567890',
        'fbc'       => 'fb.1.' . (time() * 1000) . '.IwARteste',
        'ip'        => '177.10.10.10',
        'ua'        => 'Mozilla/5.0 (Linux; Android 14) Chrome/128 Mobile Safari/537.36',
        'sourceUrl' => 'https://exemplo.com/desen/atendimento-else/',
    ),
    'createdAt' => date('c'),
));

$r = meta_capi_purchase($txId, date('c'));
echo "tx: $txId\n";
echo "resultado: " . json_encode($r) . "\n";
echo "última linha do log:\n";
$log = genesys_data_dir() . '/meta_capi_log.txt';
$linhas = file_exists($log) ? file($log) : array();
echo end($linhas);

// Limpa o rastro do teste.
@unlink(genesys_tx_file($txId));
meta_liberar_envio($txId);
