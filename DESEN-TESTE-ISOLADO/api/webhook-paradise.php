<?php
header('Content-Type: application/json');

// ============================================================
//  Webhook da Paradise Pags (gateway de fallback).
//
//  Receptor separado do da Genesys de proposito: o payload, os status e o
//  campo do id sao outros, e o webhook da Genesys e o que fatura hoje — nao
//  vale a pena arriscar os dois no mesmo parser.
//
//  Payload (flat):
//    { transaction_id, external_id, status, amount, payment_method,
//      customer{}, pix_code, raw_status, webhook_type, timestamp, tracking{} }
//
//  Status: pending | approved (pago) | processing | under_review | failed
//          | refunded | chargeback
//
//  amount vem em CENTAVOS (a Genesys manda em reais). Precisa responder 200.
// ============================================================

require_once __DIR__ . '/paradise.php';
require_once __DIR__ . '/utmify-lib.php';
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/meta-capi.php';

$dataDir = genesys_data_dir();

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$event = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;

$interno    = strval($event['transaction_id'] ?? '');
$externalId = strval($event['external_id'] ?? '');
$status     = strtolower(strval($event['status'] ?? ''));

if ($interno === '' && $externalId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Transação não identificada']);
    exit;
}

// O resto do sistema conhece esta transação pelo id prefixado (ver paradise.php)
$transactionId = $interno !== '' ? paradise_token($interno) : '';

$logEntry = date('c') . ' | paradise | id=' . $interno . ' | external_id=' . $externalId . ' | status=' . $status . "\n";
file_put_contents($dataDir . '/webhook_log.txt', $logEntry, FILE_APPEND | LOCK_EX);

// O webhook não traz produto, etapa nem UTMs: vem tudo do cache da criação
$local = genesys_tx_load($transactionId, $externalId);

// Sem cache local (transação criada fora daqui), pergunta para a Paradise
if (empty($local) && $interno !== '') {
    $res = paradise_request('GET', '/api/v1/query.php?action=get_transaction&id=' . rawurlencode($interno), null, 15);
    if (!paradise_failed($res)) {
        $event = array_merge($res['data'], $event);
    }
}

$transaction = paradise_normalize($event, $local);
if ($transactionId === '') $transactionId = strval($transaction['id']);

$paid = paradise_is_paid($status);
// A Paradise separa devolução (refunded) de contestação (chargeback); para a
// Utmify as duas viram chargedback, igual já acontece do lado da Genesys.
$refunded = paradise_is_refunded($status);

if ($transactionId !== '') {
    genesys_tx_save($transactionId, [
        'id'            => $transactionId,
        'gateway'       => 'paradise',
        'paradise_id'   => $interno !== '' ? $interno : ($local['paradise_id'] ?? null),
        'external_id'   => $externalId !== '' ? $externalId : ($local['external_id'] ?? null),
        'status'        => $paid ? 'paid' : $status,
        'amount'        => $transaction['amount'],
        'paymentMethod' => 'pix',
        'paidAt'        => $transaction['paidAt'] ?: ($local['paidAt'] ?? null),
        'refundedAt'    => $refunded ? ($local['refundedAt'] ?? date('c')) : ($local['refundedAt'] ?? null),
        'customer'      => [
            'name'     => $transaction['customer']['name'],
            'email'    => $transaction['customer']['email'],
            'phone'    => $transaction['customer']['phone'],
            'document' => $transaction['customer']['document']['number'],
        ],
        'product'       => $transaction['items'][0]['title'] ?? ($local['product'] ?? ''),
        'utm'           => $local['utm'] ?? [],
        'rawWebhook'    => $payload,
    ]);

    // Funil do CPF. Só pelo código da etapa gravado na criação: toda transação
    // da Paradise nasceu depois que esse código passou a existir, então aqui
    // não há a heurística por nome/valor que o webhook da Genesys carrega para
    // transações antigas.
    if ($paid) {
        $cpf  = preg_replace('/\D/', '', $transaction['customer']['document']['number'] ?? '');
        $step = catalogo_step($local['etapa'] ?? '');

        if ($cpf !== '' && $step > 0) {
            $funilFile = $dataDir . '/funil_' . $cpf . '.json';
            $currentStep = 0;
            if (file_exists($funilFile)) {
                $funilData = json_decode(file_get_contents($funilFile), true);
                $currentStep = $funilData['step'] ?? 0;
            }
            // Só avança, nunca retrocede
            if ($step > $currentStep) {
                file_put_contents($funilFile, json_encode([
                    'step'       => $step,
                    'cpf'        => $cpf,
                    'updated_at' => date('c'),
                    'last_tx'    => $transactionId,
                ]), LOCK_EX);
            }
        }
    }
}

// ===== UTMIFY: conversão (paid) ou estorno (chargedback) =====
if (($paid || $refunded) && $transactionId !== '') {
    $amountInCents = intval($transaction['amount'] ?? 0);

    $utmProductName = $transaction['items'][0]['title'] ?? ($local['product'] ?? '');
    if ($utmProductName === '') $utmProductName = 'Pagamento Seguro';

    // A Paradise devolve utm_* no webhook, mas gclid/fbclid/ttclid não cabem no
    // objeto tracking dela: esses só existem no cache gravado na criação.
    $savedUtm = is_array($local['utm'] ?? null) ? $local['utm'] : [];
    if (isset($event['tracking']) && is_array($event['tracking'])) {
        $savedUtm = array_merge($savedUtm, array_filter($event['tracking'], 'strlen'));
    }

    $utmPayload = [
        'orderId'       => $transactionId,
        'platform'      => 'Paradise',
        'paymentMethod' => 'pix',
        'status'        => $paid ? 'paid' : 'chargedback',
        // Mesmo createdAt do envio "waiting_payment": a Utmify exige que ele
        // não mude entre as atualizações do mesmo pedido.
        'createdAt'     => utmify_created_at($transactionId),
        'approvedDate'  => $paid
            ? (utmify_utc($transaction['paidAt'] ?? null) ?? gmdate('Y-m-d H:i:s'))
            : utmify_utc($local['paidAt'] ?? null),
        'leadId'        => $savedUtm['leadId'] ?? null,
        'refundedAt'    => $refunded ? (utmify_utc($local['refundedAt'] ?? null) ?? gmdate('Y-m-d H:i:s')) : null,
        'customer'      => [
            'name'     => $transaction['customer']['name']  ?? 'Cliente',
            'email'    => $transaction['customer']['email'] ?? 'cliente@email.com',
            'phone'    => $transaction['customer']['phone'] ?? '',
            'document' => preg_replace('/\D/', '', $transaction['customer']['document']['number'] ?? ''),
            'country'  => 'BR',
        ],
        'products' => [[
            'id'           => utmify_slug($utmProductName),
            'name'         => $utmProductName,
            'planId'       => null,
            'planName'     => null,
            'quantity'     => 1,
            'priceInCents' => $amountInCents,
        ]],
        'trackingParameters' => [
            'src'          => $savedUtm['src']          ?? null,
            'sck'          => $savedUtm['sck']          ?? null,
            'utm_source'   => $savedUtm['utm_source']   ?? null,
            'utm_medium'   => $savedUtm['utm_medium']   ?? null,
            'utm_campaign' => $savedUtm['utm_campaign'] ?? null,
            'utm_term'     => $savedUtm['utm_term']     ?? null,
            'utm_content'  => $savedUtm['utm_content']  ?? null,
            'gclid'        => $savedUtm['gclid']        ?? null,
            'fbclid'       => $savedUtm['fbclid']       ?? null,
            'ttclid'       => $savedUtm['ttclid']       ?? null,
            'keyword'      => $savedUtm['keyword']      ?? null,
        ],
        'commission' => [
            // a Paradise não expõe a taxa no payload da transação
            'totalPriceInCents'     => $amountInCents,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $amountInCents,
            'currency'              => 'BRL',
        ],
    ];

    $utmRes = utmify_enviar($utmPayload);
    utmify_log('WEBHOOK PARADISE ' . $utmPayload['status'], $transactionId, $utmRes, $utmPayload);
}

// ===== META: Purchase pela API de Conversões =====
// Idempotente por transação (trava em meta-capi.php), então webhook e polling
// podem detectar o pagamento ao mesmo tempo sem duplicar o evento.
// Estorno NÃO vai para a Meta: prejudica a otimização da campanha.
if ($paid && $transactionId !== '') {
    meta_capi_purchase($transactionId, $transaction['paidAt'] ?? null);
}

echo json_encode(['success' => true, 'received' => $transactionId, 'status' => $status]);
