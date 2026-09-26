<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('success' => false, 'error' => 'Método não permitido'));
    exit;
}

require_once __DIR__ . '/genesys.php';
require_once __DIR__ . '/utmify-lib.php';

// Pedido aguardando pagamento, enviado pelo front assim que o PIX é gerado.
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(array('success' => false, 'error' => 'Payload inválido'));
    exit;
}

$orderId = utmify_order_id($in);
if ($orderId === '') {
    echo json_encode(array('success' => false, 'error' => 'orderId ausente'));
    exit;
}

// createdAt tem que ser IDÊNTICO em todas as atualizações do mesmo pedido.
// Fixamos aqui (UTC 0) e gravamos no cache da transação para o utmify.php e o
// webhook reaproveitarem quando o pagamento for confirmado.
$createdAt = utmify_created_at($orderId);

$payload = utmify_montar_payload($in, $orderId, array(
    // A Utmify só aceita: waiting_payment, paid, refused, refunded, chargedback.
    'status'       => 'waiting_payment',
    'createdAt'    => $createdAt,
    'approvedDate' => null,
    'refundedAt'   => null,
));

$res = utmify_enviar($payload);
utmify_log('utmify-pendente.php', $payload['orderId'], $res, $payload);

echo json_encode(array(
    'success'   => $res['httpCode'] >= 200 && $res['httpCode'] < 300,
    'http_code' => $res['httpCode'],
    'response'  => json_decode($res['body'], true),
    'error'     => $res['error'] !== '' ? $res['error'] : null,
));
