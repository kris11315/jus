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

// Conversão "paid" enviada pelo front quando o verificar.php detecta pagamento.
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

$payload = utmify_montar_payload($in, $orderId, array(
    'status'       => 'paid',
    // Reaproveita o createdAt fixado no envio "waiting_payment".
    'createdAt'    => utmify_created_at($orderId),
    'approvedDate' => gmdate('Y-m-d H:i:s'),
    'refundedAt'   => null,
));

$res = utmify_enviar($payload);
utmify_log('utmify.php', $payload['orderId'], $res, $payload);

echo json_encode(array(
    'success'   => $res['httpCode'] >= 200 && $res['httpCode'] < 300,
    'http_code' => $res['httpCode'],
    'response'  => json_decode($res['body'], true),
    'error'     => $res['error'] !== '' ? $res['error'] : null,
));
