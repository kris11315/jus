<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/genesys.php';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
$id  = isset($in['id']) ? trim($in['id']) : '';

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID da transação não informado']);
    exit;
}


// Formato normalizado (o mesmo que genesys_normalize() devolve ao front)
$fakePayload = [
    'type' => 'transaction',
    'data' => [
        'id'            => $id,
        'external_id'   => 'simulacao-' . $id,
        'status'        => 'paid', // Genesys: AUTHORIZED
        'amount'        => 7847,   // centavos — valor padrão para ativar como "front"
        'paymentMethod' => 'pix',
        'items'    => [['title' => 'simulacao de pagamento', 'unitPrice' => 7847, 'quantity' => 1]],
        'metadata' => '{"isTest":true,"src":"test-simulator"}',
        'customer' => ['name' => 'Teste Simulação', 'email' => 'teste@simula.com', 'document'=>['number'=>'00000000000']],
        'fee'      => ['fixedAmount' => 0],
        'createdAt'=> date('c'),
        'paidAt'   => date('c')
    ]
];

// Salva o JSON como se fosse aprovado, mas na mesma pasta da API.
// A limpeza do id tem que ser a MESMA do verificar.php, que lê este arquivo —
// desalinhar as duas faria a simulação gravar num nome que a leitura não acha.
$logFile = __DIR__ . '/status_pago_simulado_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $id) . '.json';
file_put_contents($logFile, json_encode([
    'id' => $id, 
    'status' => 'paid', 
    'updatedAt' => date('c'), 
    'data' => $fakePayload['data']
], JSON_PRETTY_PRINT));

// Log alternativo não obrigatório
$unifiedFile = __DIR__ . '/simulacoes_postbacks.json';
$logs = file_exists($unifiedFile) ? json_decode(file_get_contents($unifiedFile), true) ?? [] : [];
$logs[] = ['time' => date('c'), 'ip' => 'simulator', 'payload' => $fakePayload];
if(count($logs)>500) $logs = array_slice($logs,-500);
file_put_contents($unifiedFile, json_encode($logs, JSON_PRETTY_PRINT));

// Disparar para a Utmify Simulando a Venda
$utmPayload = [
    'orderId'       => $id,
    'platform'      => 'SerasaFundo',
    'paymentMethod' => 'pix',
    'status'        => 'paid',
    'createdAt'     => gmdate('Y-m-d H:i:s'),
    'approvedDate'  => gmdate('Y-m-d H:i:s'),
    'refundedAt'    => null,
    'customer'      => [
        'name'     => 'Teste Simulação',
        'email'    => 'teste@simula.com',
        'phone'    => '11999999999',
        'document' => '00000000000',
        'country'  => 'BR',
        'ip'       => '127.0.0.1'
    ],
    'products' => [[
        'id'           => 'produto-01',
        'name'         => 'front novosrs',
        'planId'       => null,
        'planName'     => null,
        'quantity'     => 1,
        'priceInCents' => 7847,
    ]],
    'trackingParameters' => [
        'src' => 'test-simulator'
    ],
    'commission' => [
        'totalPriceInCents'     => 7847,
        'gatewayFeeInCents'     => 10,
        'userCommissionInCents' => 7837,
    ],
    'isTest' => false,
];

$ch = curl_init('https://api.utmify.com.br/api-credentials/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($utmPayload),
    CURLOPT_HTTPHEADER     => [
        'x-api-token: auGDvp5DZCnHQczrSYqy5vKFkwz0Mcokx3ha',
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 5,
]);
curl_exec($ch);
curl_close($ch);

echo json_encode([
    'success'   => true,
    'http_code' => 200,
    'status'    => 'paid',
    'data'      => $fakePayload['data'],
]);
