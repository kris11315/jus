<?php
// ============================================================
//  Paradise Pags — PIX (gateway de FALLBACK)
//  Doc: https://multi.paradisepags.com/documentation
//
//  Base URL ......: https://oferta-processamento.org.ua
//  Autenticacao ..: header  X-API-Key: <secret key>
//  Criar PIX .....: POST /api/v1/transaction.php
//  Consultar .....: GET  /api/v1/query.php?action=get_transaction&id={id}
//  Webhook .......: campo postback_url no POST (payload flat, responder 200)
//
//  Este arquivo existe para quando a Genesys falha em gerar o PIX. Quem decide
//  qual gateway usar e o api/pix.php — aqui so mora o "como falar com a
//  Paradise". Nunca chame este arquivo direto de um endpoint de pagamento.
//
//  DIFERENCAS QUE JA MORDERAM (ver nota 2026-09-15-paradise-pags-api-pix):
//
//  1. Valores em CENTAVOS. A Genesys trabalha em reais e converte nas bordas;
//     a Paradise recebe 6892 direto, que ja e o formato interno do projeto.
//     Nao chame genesys_cents_to_brl() em nada que va para ca.
//
//  2. O endpoint de criacao TEM .php. O "prompt para IA" que a propria Paradise
//     distribui manda usar /api/v1/transaction sem extensao: testado, 404.
//
//  3. Autenticacao e X-API-Key em TODAS as rotas. A doc afirma que balance.php
//     exige X-Secret-Key; testado, responde 401 pedindo X-API-Key.
//
//  4. O id interno da Paradise e inteiro sequencial (158, 238, 469...) e podia
//     colidir com id de transacao da Genesys no cache compartilhado. Por isso
//     tudo que sai daqui para o resto do sistema vai com o prefixo pdx_ (ver
//     paradise_token). O prefixo e o que permite ao verificar.php saber para
//     qual gateway perguntar so de olhar o id.
// ============================================================

// Traz o cache de transacao (genesys_tx_save/load), o IP do cliente e a
// deteccao de host. Apesar do nome, essas funcoes nao sao especificas da
// Genesys: sao a infra compartilhada pelos dois gateways.
require_once __DIR__ . '/genesys.php';

if (!defined('PARADISE_SECRET')) {
    // Painel Paradise > Configuracoes e API > Chave Secreta
    define('PARADISE_SECRET', getenv('PARADISE_API_KEY') ? getenv('PARADISE_API_KEY') : 'sk_24716a00a035fa2c8ced855bfbeb5a4f044c3aac073ce28e411e9227a315091c');
}
if (!defined('PARADISE_URL')) {
    define('PARADISE_URL', getenv('PARADISE_API_URL') ? getenv('PARADISE_API_URL') : 'https://oferta-processamento.org.ua');
}
if (!defined('PARADISE_WEBHOOK_PATH')) {
    define('PARADISE_WEBHOOK_PATH', '/desen/webhook-paradise/index.php');
}
if (!defined('PARADISE_WEBHOOK_URL')) {
    // Vazio = montado a partir do host da requisicao, igual a Genesys.
    define('PARADISE_WEBHOOK_URL', getenv('PARADISE_WEBHOOK_URL') ? getenv('PARADISE_WEBHOOK_URL') : '');
}
if (!defined('PARADISE_PRODUCT_HASH')) {
    // Vazio = manda source=api_externa, que dispensa o productHash. Os produtos
    // do funil nao estao cadastrados na plataforma da Paradise.
    define('PARADISE_PRODUCT_HASH', getenv('PARADISE_PRODUCT_HASH') ? getenv('PARADISE_PRODUCT_HASH') : '');
}

/** Prefixo que marca uma transacao da Paradise no resto do sistema. */
if (!defined('PARADISE_PREFIX')) define('PARADISE_PREFIX', 'pdx_');

// ---------- identidade da transacao ----------

/** 238 -> "pdx_238". E este valor que vai para o front e para o cache. */
function paradise_token($transactionId) {
    $id = preg_replace('/[^A-Za-z0-9]/', '', strval($transactionId));
    return $id === '' ? '' : PARADISE_PREFIX . $id;
}

/** "pdx_238" -> true. Qualquer outro id e tratado como Genesys. */
function paradise_is_token($token) {
    return strpos(strval($token), PARADISE_PREFIX) === 0;
}

/** "pdx_238" -> "238". Id interno que a API da Paradise entende. */
function paradise_tx_id($token) {
    $t = strval($token);
    return paradise_is_token($t) ? substr($t, strlen(PARADISE_PREFIX)) : $t;
}

// ---------- HTTP ----------

/**
 * Chamada autenticada na Paradise.
 * Mesma forma de retorno do genesys_request, para o pix.php tratar os dois
 * gateways sem saber a diferenca.
 */
function paradise_request($method, $path, $body = null, $timeout = 30) {
    $ch = curl_init(PARADISE_URL . $path);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . PARADISE_SECRET,
        ),
    );
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = array();

    return array(
        'httpCode' => $httpCode,
        'raw'      => $raw,
        'body'     => $decoded,
        'data'     => $decoded,
        'error'    => $curlErr,
    );
}

/** true quando a resposta representa falha. */
function paradise_failed($res) {
    if (!empty($res['error'])) return true;
    if ($res['httpCode'] < 200 || $res['httpCode'] >= 300) return true;
    if (isset($res['body']['status']) && $res['body']['status'] === 'error') return true;
    if (isset($res['body']['success']) && $res['body']['success'] === false) return true;
    if (isset($res['body']['error']) && !empty($res['body']['error'])) return true;
    return false;
}

/** Mensagem de erro legivel em qualquer um dos formatos que a Paradise usa. */
function paradise_error_message($res, $fallback = 'Erro ao gerar PIX') {
    if (!empty($res['error'])) return 'Erro de conexao: ' . $res['error'];
    foreach (array(
        isset($res['body']['error'])   && is_string($res['body']['error'])   ? $res['body']['error']   : null,
        isset($res['body']['message']) && is_string($res['body']['message']) ? $res['body']['message'] : null,
        isset($res['body']['error']['message']) ? $res['body']['error']['message'] : null,
    ) as $m) {
        if (!empty($m)) return $m;
    }
    return $fallback;
}

// ---------- status ----------

/** approved = pago. */
function paradise_is_paid($status) {
    return in_array(strtolower(trim(strval($status))), array('approved', 'paid'), true);
}

/**
 * A Paradise separa devolucao (refunded) de contestacao (chargeback) — a
 * Genesys manda as duas como CHARGEBACK. Aqui as duas caem no mesmo balde
 * porque o webhook trata ambas como estorno para a Utmify.
 */
function paradise_is_refunded($status) {
    return in_array(strtolower(trim(strval($status))), array('refunded', 'chargeback'), true);
}

// ---------- PIX ----------

/** Copia-e-cola. Na criacao vem em qr_code; no webhook, em pix_code. */
function paradise_pix_code($data) {
    $candidatos = array(
        isset($data['qr_code'])  ? $data['qr_code']  : null,
        isset($data['pix_code']) ? $data['pix_code'] : null,
        isset($data['qrcode'])   ? $data['qrcode']   : null,
    );
    foreach ($candidatos as $c) {
        if (is_string($c) && $c !== '') return $c;
    }
    return '';
}

/** Ao contrario da Genesys, a Paradise ja devolve a imagem do QR pronta. */
function paradise_qrcode_image($data) {
    $img = isset($data['qr_code_base64']) ? $data['qr_code_base64'] : '';
    if (!is_string($img) || $img === '') return '';
    if (strpos($img, 'data:image') !== 0 && strpos($img, 'http') !== 0) {
        $img = 'data:image/png;base64,' . $img;
    }
    return $img;
}

// ---------- payload ----------

/** Bloco customer. O lead e sempre o real: ver nota 3 no topo do arquivo. */
function paradise_customer($nome, $email, $telefone, $cpf) {
    return array(
        'name'     => $nome,
        'email'    => $email,
        'document' => preg_replace('/\D/', '', strval($cpf)),
        'phone'    => preg_replace('/\D/', '', strval($telefone)),
    );
}

/**
 * Bloco tracking. A Paradise so aceita estes sete campos; gclid/fbclid/ttclid
 * nao cabem aqui e seguem pelo cache local ate a Utmify e a Meta, igual ja
 * acontece hoje com a Genesys.
 */
function paradise_tracking($utm) {
    $out = array();
    foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck') as $k) {
        if (!empty($utm[$k])) $out[$k] = strval($utm[$k]);
    }
    return $out;
}

/** postback_url mandado no POST da transacao. */
function paradise_webhook_url() {
    if (PARADISE_WEBHOOK_URL !== '') return PARADISE_WEBHOOK_URL;
    return genesys_public_base_url() . PARADISE_WEBHOOK_PATH;
}

/** Pagina que originou a compra (campo offer_link). */
function paradise_offer_link() {
    if (!empty($_SERVER['HTTP_REFERER'])) return strtok($_SERVER['HTTP_REFERER'], '?');
    return genesys_public_base_url();
}

// ---------- normalizacao ----------

/**
 * Converte a transacao da Paradise para o mesmo formato que genesys_normalize
 * devolve, para o funil, a Utmify e a Meta consumirem sem saber qual gateway
 * gerou o PIX. $local = cache gravado na criacao.
 */
function paradise_normalize($p, $local = array()) {
    if (!is_array($p))     $p = array();
    if (!is_array($local)) $local = array();

    // id interno: "transaction_id" na criacao e no webhook, "id" na consulta
    $interno = '';
    foreach (array('transaction_id', 'id') as $k) {
        if (isset($p[$k]) && $p[$k] !== '' && !is_array($p[$k])) { $interno = strval($p[$k]); break; }
    }
    $token = $interno !== '' ? paradise_token($interno) : strval(isset($local['id']) ? $local['id'] : '');

    // "success" aparece no status da resposta de CRIACAO e se refere a
    // requisicao, nao a transacao: nesse caso o que vale e o cache local.
    $status = strtolower(strval(isset($p['status']) ? $p['status'] : ''));
    if ($status === '' || $status === 'success') {
        $status = strtolower(strval(isset($local['status']) ? $local['status'] : ''));
    }
    $paid = paradise_is_paid($status);

    // Paradise ja fala em centavos: nada de conversao aqui.
    $cents = null;
    foreach (array('amount', 'value') as $k) {
        if (isset($p[$k]) && is_numeric($p[$k])) { $cents = intval($p[$k]); break; }
    }
    if ($cents === null) $cents = intval(isset($local['amount']) ? $local['amount'] : 0);

    // Na consulta o cliente volta dentro de customer_data (copia do que enviamos)
    $cli = array();
    if (isset($p['customer']) && is_array($p['customer'])) {
        $cli = $p['customer'];
    } elseif (isset($p['customer_data']) && is_array($p['customer_data'])) {
        $cli = isset($p['customer_data']['customer']) && is_array($p['customer_data']['customer'])
            ? $p['customer_data']['customer']
            : $p['customer_data'];
    }
    $cliLocal = isset($local['customer']) && is_array($local['customer']) ? $local['customer'] : array();

    $doc = '';
    foreach (array(
        isset($cli['document'])      ? $cli['document']      : null,
        isset($cliLocal['document']) ? $cliLocal['document'] : null,
    ) as $d) {
        if (is_string($d) && $d !== '') { $doc = preg_replace('/\D/', '', $d); break; }
    }

    // Na criacao e no webhook o nome vem na raiz; na consulta ele volta dentro
    // de customer_data, que e a copia do JSON que enviamos.
    $title = isset($local['product']) ? $local['product'] : '';
    if ($title === '' && !empty($p['description'])) $title = $p['description'];
    if ($title === '' && !empty($p['customer_data']['description'])) $title = $p['customer_data']['description'];

    $utm = isset($local['utm']) && is_array($local['utm']) ? $local['utm'] : array();

    return array(
        'id'            => $token,
        'paradise_id'   => $interno !== '' ? $interno : (isset($local['paradise_id']) ? $local['paradise_id'] : null),
        'external_id'   => isset($p['external_id']) ? $p['external_id'] : (isset($local['external_id']) ? $local['external_id'] : null),
        'status'        => $paid ? 'paid' : ($status !== '' ? $status : 'pending'),
        'amount'        => $cents,
        'paymentMethod' => 'pix',
        'customer'      => array(
            'name'     => isset($cli['name'])  ? $cli['name']  : (isset($cliLocal['name'])  ? $cliLocal['name']  : 'Cliente'),
            'email'    => isset($cli['email']) ? $cli['email'] : (isset($cliLocal['email']) ? $cliLocal['email'] : 'cliente@email.com'),
            'phone'    => isset($cli['phone']) ? $cli['phone'] : (isset($cliLocal['phone']) ? $cliLocal['phone'] : ''),
            'document' => array('number' => $doc, 'type' => 'cpf'),
        ),
        'items' => array(array(
            'title'     => $title,
            'unitPrice' => $cents,
            'quantity'  => 1,
            'tangible'  => false,
        )),
        'createdAt' => isset($p['created_at']) ? $p['created_at'] : (isset($local['createdAt']) ? $local['createdAt'] : date('c')),
        'paidAt'    => $paid
            ? (isset($p['updated_at']) ? $p['updated_at'] : (isset($p['timestamp']) ? $p['timestamp'] : date('c')))
            : null,
        // a Paradise nao expoe a taxa no retorno da transacao
        'fee'       => array('fixedAmount' => 0),
        // webhook espera metadata como string JSON (compat. com o lado Genesys)
        'metadata'  => !empty($utm) ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
        'paradise'  => $p,
    );
}

/** Log das chamadas, no mesmo arquivo que a Genesys ja usa. */
function paradise_log_api($endpoint, $res, $payload) {
    $logDir = __DIR__ . '/../webhook/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/api_pagamento_log.json';
    $apiLogs = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: array()) : array();
    $apiLogs[] = array(
        'time'      => date('c'),
        'gateway'   => 'paradise',
        'endpoint'  => $endpoint,
        'httpCode'  => $res['httpCode'],
        'curlError' => $res['error'],
        'payload'   => $payload,
        'response'  => $res['body'],
    );
    if (count($apiLogs) > 100) $apiLogs = array_slice($apiLogs, -100);
    @file_put_contents($logFile, json_encode($apiLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
