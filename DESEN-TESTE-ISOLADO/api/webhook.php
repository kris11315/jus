<?php
header('Content-Type: application/json');

// Recebe os webhooks da Genesys quando o status de uma transação muda.
// Docs: https://docs.genesys.finance/docs/webhooks/transacoes
// Payload (flat): { id, external_id, total_amount, status, payment_method }
// Status: PENDING | AUTHORIZED (pago) | FAILED | CHARGEBACK | IN_DISPUTE
// Precisa responder HTTP 200 para a Genesys confirmar o recebimento.

require_once __DIR__ . '/genesys.php';
require_once __DIR__ . '/utmify-lib.php';
require_once __DIR__ . '/produtos.php';
require_once __DIR__ . '/meta-capi.php';

$dataDir = genesys_data_dir();

// Lê o body do webhook
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

// A Genesys manda o objeto direto; aceita também envelope {"data":{...}}
$event = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;

$transactionId = strval($event['id'] ?? '');
$externalId    = strval($event['external_id'] ?? '');
$status        = strtolower(strval($event['status'] ?? ''));

if ($transactionId === '' && $externalId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Transação não identificada']);
    exit;
}

// Log do webhook para debug
$logFile = $dataDir . '/webhook_log.txt';
$logEntry = date('c') . ' | genesys | id=' . $transactionId . ' | external_id=' . $externalId . ' | status=' . $status . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// O webhook não traz cliente, itens nem UTMs: recupera o que gravamos na criação
$local = genesys_tx_load($transactionId, $externalId);

// Sem cache local (ex.: transação criada fora daqui), consulta a Genesys
if (empty($local) && $transactionId !== '') {
    $res = genesys_request('GET', '/v1/transactions/' . rawurlencode($transactionId), null, 15);
    if (!genesys_failed($res)) {
        $event = array_merge($res['data'], $event);
    }
}

$transaction = genesys_normalize($event, $local);
if ($transactionId === '') $transactionId = strval($transaction['id']);

$paid = genesys_is_paid($status);

// Estorno/chargeback: a Genesys não tem status REFUNDED; devolução de PIX
// chega como CHARGEBACK (ou IN_DISPUTE enquanto a contestação está aberta).
$refunded = in_array($status, array('chargeback', 'in_dispute'), true);

if ($transactionId !== '') {
    // Salva o status da transação localmente (mantém o cache da criação)
    genesys_tx_save($transactionId, [
        'id'            => $transactionId,
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

    // Se pago, atualiza também o funil do CPF (se disponível)
    if ($paid) {
        $cpf = preg_replace('/\D/', '', $transaction['customer']['document']['number'] ?? '');

        if (!empty($cpf)) {
            // Identifica qual produto é pelo título do item
            $productName = '';
            if (!empty($transaction['items'][0]['title'])) {
                $productName = $transaction['items'][0]['title'];
            }

            // Fonte primária: o código da etapa gravado na criação da transação.
            // Independe do nome e do preço da oferta.
            $step = catalogo_step($local['etapa'] ?? '');

            // Heurística antiga, só para transações anteriores ao código de etapa.
            $pn = strtolower($productName);
            if ($step > 0) {
                // já resolvido pelo catálogo
            } elseif (strpos($pn, 'pagamento com desconto') !== false || strpos($pn, 'front') !== false || strpos($pn, 'efetiva') !== false) {
                $step = 2; // Produto principal pago
            } elseif (strpos($pn, 'pagamento importante') !== false || strpos($pn, 'up1') !== false || strpos($pn, 'lucros 1x') !== false) {
                $step = 4; // Upsell 1 pago
            } elseif (strpos($pn, 'verificação 2026') !== false || strpos($pn, 'verificacao 2026') !== false || strpos($pn, 'up2') !== false || strpos($pn, 'renda 2x') !== false) {
                $step = 6; // Upsell 2 pago
            } elseif (strpos($pn, 'pagamento seguro 2026') !== false || strpos($pn, 'up3') !== false || strpos($pn, 'ativador 3x') !== false) {
                $step = 8; // Upsell 3 pago
            } elseif (strpos($pn, 'taxa de preservação') !== false || strpos($pn, 'taxa de preservacao') !== false || strpos($pn, 'up4') !== false || strpos($pn, 'renda express') !== false) {
                $step = 10; // Upsell 4 pago
            }

            // Sem título reconhecível, cai no valor (mesma tabela do verificar.php)
            if ($step === 0) {
                $amt = intval($transaction['amount']);
                if ($amt === 6892 || $amt === 1092 || $amt === 7847)  $step = 2;
                elseif ($amt === 4392)               $step = 4;
                elseif ($amt === 3840)               $step = 6;
                elseif ($amt === 4560)               $step = 8;
                elseif ($amt === 6743)               $step = 10;
            }

            if ($step > 0) {
                $funilFile = $dataDir . '/funil_' . $cpf . '.json';
                $currentStep = 0;
                if (file_exists($funilFile)) {
                    $funilData = json_decode(file_get_contents($funilFile), true);
                    $currentStep = $funilData['step'] ?? 0;
                }
                // Só avança, nunca retrocede
                if ($step > $currentStep) {
                    file_put_contents($funilFile, json_encode([
                        'step' => $step,
                        'cpf' => $cpf,
                        'updated_at' => date('c'),
                        'last_tx' => $transactionId
                    ]), LOCK_EX);
                }
            }
        }
    }
}

// ===== UTMIFY: Envia conversão (paid) ou estorno (chargedback) para a API =====
// O estorno vai SÓ pra Utmify. O bloco da Meta (abaixo) continua preso ao
// $paid: reembolso não vira evento no pixel, de propósito.
if ($paid || $refunded) {
    $utmifyToken = 'auGDvp5DZCnHQczrSYqy5vKFkwz0Mcokx3ha';
    $amountInCents = intval($transaction['amount'] ?? 0);

    // Nome do produto: o nome interno gravado na criação da transação
    // (Lucros 1x, Renda 2x, Ativador 3x, ...). Antes daqui saía um nome de
    // vitrine adivinhado por palavra-chave, que não batia com o do pendente.
    $utmProductName = '';
    if (!empty($transaction['items'][0]['title'])) {
        $utmProductName = $transaction['items'][0]['title'];
    } elseif (!empty($local['product'])) {
        $utmProductName = $local['product'];
    }

    if ($utmProductName === '') {
        $utmProductName = 'Pagamento Seguro';
    }

    $utmProductId = utmify_slug($utmProductName);

    $customerDoc = preg_replace('/\D/', '', $transaction['customer']['document']['number'] ?? '');

    // Lê UTMs: a Genesys não devolve os utm_* no webhook, então a fonte é o
    // cache local gravado na criação da transação (tx_{id}.json)
    $savedUtm = [];
    if (!empty($transaction['metadata'])) {
        $metadata = json_decode($transaction['metadata'], true);
        if (is_array($metadata)) {
            $savedUtm = $metadata;
        }
    }

    if (empty($savedUtm['utm_source']) && !empty($local['utm']) && is_array($local['utm'])) {
        $savedUtm = array_merge($local['utm'], $savedUtm);
    }

    $utmPayload = [
        'orderId'       => $transactionId,
        'platform'      => utmify_platform($transactionId),
        'paymentMethod' => 'pix',
        // Utmify só aceita: waiting_payment | paid | refused | refunded | chargedback
        'status'        => $paid ? 'paid' : 'chargedback',
        // Mesmo createdAt já usado no envio "waiting_payment" (UTC 0). A Utmify
        // exige que ele não mude entre as atualizações do mesmo pedido.
        'createdAt'     => utmify_created_at($transactionId),
        // No chargeback mantém a data em que o pedido foi pago (cache local).
        'approvedDate'  => $paid
            ? (utmify_utc($transaction['paidAt'] ?? null) ?? gmdate('Y-m-d H:i:s'))
            : utmify_utc($local['paidAt'] ?? null),
        'leadId'        => $savedUtm['leadId']       ?? null,
        'refundedAt'    => $refunded ? (utmify_utc($local['refundedAt'] ?? null) ?? gmdate('Y-m-d H:i:s')) : null,
        'customer'      => [
            'name'     => $transaction['customer']['name']  ?? 'Cliente',
            'email'    => $transaction['customer']['email'] ?? 'cliente@email.com',
            'phone'    => $transaction['customer']['phone'] ?? '',
            'document' => $customerDoc,
            'country'  => 'BR',
        ],
        'products' => [[
            'id'           => $utmProductId,
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
            // a Genesys não expõe a taxa no payload da transação
            'totalPriceInCents'     => $amountInCents,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $amountInCents,
            'currency'              => 'BRL',
        ],
    ];

    $ch = curl_init('https://api.utmify.com.br/api-credentials/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($utmPayload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-token: ' . $utmifyToken,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $utmResponse = curl_exec($ch);
    $utmHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log da resposta Utmify
    $utmLog = date('c') . ' | UTMIFY ' . $utmPayload['status'] . ' | tx=' . $transactionId . ' | http=' . $utmHttpCode . ' | resp=' . $utmResponse . "\n";
    file_put_contents($dataDir . '/utmify_log.txt', $utmLog, FILE_APPEND | LOCK_EX);
}
// ===== FIM UTMIFY =====

// ===== META: Purchase pela API de Conversões (dedup com o browser por event_id) =====
// Só pagamento. Chargeback/estorno NÃO é enviado pra Meta: evento de reembolso
// no pixel prejudica a otimização da campanha.
if ($paid && $transactionId !== '') {
    meta_capi_purchase($transactionId, $transaction['paidAt'] ?? null);
}

// Responde 200 OK para a Genesys confirmar recebimento
echo json_encode(['success' => true, 'received' => $transactionId, 'status' => $status]);
