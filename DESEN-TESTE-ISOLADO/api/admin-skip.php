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

// Bypass admin do funil: marca a transação como paga (simulada) e avança o step do CPF.
// Não dispara conversão na Utmify — é apenas para navegação interna/administrativa.
define('ADMIN_KEY', 'pataco-gov-2026');

$in  = json_decode(file_get_contents('php://input'), true);
$key = isset($in['key']) ? $in['key'] : '';
if ($key !== ADMIN_KEY) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'error' => 'Chave admin inválida'));
    exit;
}

$id   = isset($in['id']) ? trim($in['id']) : '';
$cpf  = isset($in['cpf']) ? preg_replace('/\D/', '', $in['cpf']) : '';
$step = isset($in['step']) ? intval($in['step']) : 0;

// 1) Marca a transação como paga para o verificar.php retornar PAID
if ($id !== '') {
    $simFile = __DIR__ . '/status_pago_simulado_' . $id . '.json';
    file_put_contents($simFile, json_encode(array(
        'id'        => $id,
        'status'    => 'paid',
        'simulated' => true,
        'updatedAt' => date('c'),
        'data'      => array(
            'id'       => $id,
            'status'   => 'paid',
            'customer' => array('name' => 'Admin', 'email' => 'admin@local', 'document' => array('number' => $cpf)),
        ),
    ), JSON_PRETTY_PRINT));
}

// 2) Avança o funil do CPF (mesmo arquivo do funil.php / webhook.php, nunca retrocede)
if ($cpf !== '' && $step > 0) {
    $dataDir  = __DIR__ . '/data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
    $funilFile = $dataDir . '/funil_' . $cpf . '.json';
    $state = file_exists($funilFile) ? (json_decode(file_get_contents($funilFile), true) ?: array('step' => 0)) : array('step' => 0);
    if ($step > (isset($state['step']) ? $state['step'] : 0)) {
        $state['step']      = $step;
        $state['cpf']       = $cpf;
        $state['updated_at'] = date('c');
        if ($id !== '') $state['last_tx'] = $id;
        file_put_contents($funilFile, json_encode($state));
    }
}

echo json_encode(array('success' => true, 'step' => $step, 'cpf' => $cpf));
