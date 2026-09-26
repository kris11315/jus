<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Consulta de status. Quem pergunta ao gateway certo é o api/pix.php: id com
// prefixo pdx_ é da Paradise, qualquer outro é da Genesys.
//   Genesys : PENDING | AUTHORIZED (pago) | FAILED | CHARGEBACK | IN_DISPUTE
//   Paradise: pending | approved (pago) | processing | under_review | failed
//             | refunded | chargeback
require_once __DIR__ . '/pix.php';
require_once __DIR__ . '/produtos.php';

$dataDir = genesys_data_dir();

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($id === '') { echo json_encode(array('success' => false, 'error' => 'ID não informado')); exit; }

// O id vem da query string e vira nome de arquivo: sem esta limpeza um
// ?id=../../algo sai da pasta e devolve o conteúdo de outro .json na resposta.
// Ids reais (UUID da Genesys, pdx_1234 da Paradise) passam intactos.
// Tem que ser a MESMA limpeza do simular-pago.php, que grava este arquivo.
$logFile = __DIR__ . '/status_pago_simulado_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $id) . '.json';
if (file_exists($logFile)) {
    $fData = json_decode(file_get_contents($logFile), true);
    if ($fData && genesys_is_paid(isset($fData['status']) ? $fData['status'] : '')) {
        echo json_encode(array(
            'success'        => true,
            'status'         => 'PAID',
            'transaction_id' => $id,
            'data'           => isset($fData['data']) ? $fData['data'] : array()
        ));
        exit;
    }
}

$res = pix_consultar($id);

if (empty($res['ok'])) { echo json_encode(array('success' => false, 'error' => $res['erro'])); exit; }

$local = genesys_tx_load($id);
$data  = $res['data'];
$paid  = $res['paid'];

// Mantem o cache local em dia (nenhum dos webhooks traz cliente/itens)
if (!empty($data['id'])) {
    genesys_tx_save($data['id'], array('status' => $paid ? 'paid' : $data['status']));
}

if ($paid) {
    // Purchase server-side. Idempotente: se o webhook já mandou, não repete.
    meta_capi_purchase($data['id'] !== '' ? $data['id'] : $id, isset($data['paidAt']) ? $data['paidAt'] : null);

    $cpfFunil = '';
    if (isset($data['customer']['document']['number'])) {
        $cpfFunil = $data['customer']['document']['number'];
    }
    if ($cpfFunil !== '') {
        $cpfFunil = preg_replace('/\D/', '', $cpfFunil);
        $amount = (int)(isset($data['amount']) ? $data['amount'] : 0);   // centavos
        $title  = strtolower(isset($data['items'][0]['title']) ? $data['items'][0]['title'] : '');
        // Fonte primária: o código da etapa gravado na criação da transação.
        // Independe do nome e do preço, então renomear oferta não quebra nada.
        $toStep = catalogo_step(isset($local['etapa']) ? $local['etapa'] : '');

        // Heurística antiga, só para transações criadas antes do código de etapa.
        if ($toStep === 0) {
            if ($amount === 6892 || $amount === 1092 || $amount === 7847 || strpos($title, 'novosrs') !== false || strpos($title, 'front') !== false || strpos($title, 'efetiva') !== false) $toStep = 2;
            elseif ($amount === 4392 || strpos($title, 'up1') !== false || strpos($title, 'lucros 1x') !== false) $toStep = 4;
            elseif ($amount === 3840 || strpos($title, 'up2') !== false || strpos($title, 'renda 2x') !== false) $toStep = 6;
            elseif ($amount === 4560 || strpos($title, 'up3') !== false || strpos($title, 'ativador 3x') !== false) $toStep = 8;
            elseif ($amount === 6743 || strpos($title, 'up4') !== false || strpos($title, 'renda express') !== false) $toStep = 10;
        }

        if ($toStep > 0) {
            // Mesmo arquivo usado por funil.php e webhook.php (nunca retrocede)
            $funilFile = $dataDir . '/funil_' . $cpfFunil . '.json';
            $state = file_exists($funilFile) ? (json_decode(file_get_contents($funilFile), true) ?: array('step' => 0)) : array('step' => 0);
            if ($toStep > (isset($state['step']) ? $state['step'] : 0)) {
                $state['step'] = $toStep;
                $state['cpf'] = $cpfFunil;
                $state['updated_at'] = date('c');
                $state['last_tx'] = $data['id'] !== '' ? $data['id'] : $id;
                @file_put_contents($funilFile, json_encode($state));
            }
        }
    }
}

echo json_encode(array(
    'success'        => true,
    'status'         => $paid ? 'PAID' : $res['status'],
    'transaction_id' => $data['id'] !== '' ? $data['id'] : $id,
    'gateway'        => $res['gateway'],
    'http_code'      => $res['http_code'],
    'data'           => $data,
));
