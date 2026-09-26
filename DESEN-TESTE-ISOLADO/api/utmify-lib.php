<?php
// ============================================================
//  Utmify — envio de pedidos (https://docs.utmify.com.br/envio-de-vendas)
//
//  Endpoint .....: POST https://api.utmify.com.br/api-credentials/orders
//  Auth .........: header  x-api-token: <token>
//  status .......: waiting_payment | paid | refused | refunded | chargedback
//  Datas ........: 'Y-m-d H:i:s' em UTC 0 (por isso gmdate(), nunca date())
//  createdAt ....: precisa ser o MESMO valor em todas as atualizações do pedido,
//                  então ele é fixado no 1º envio e guardado no cache tx_*.json.
// ============================================================

if (!defined('UTMIFY_TOKEN')) {
    define('UTMIFY_TOKEN', getenv('UTMIFY_API_TOKEN') ? getenv('UTMIFY_API_TOKEN') : 'auGDvp5DZCnHQczrSYqy5vKFkwz0Mcokx3ha');
}
if (!defined('UTMIFY_URL')) {
    define('UTMIFY_URL', 'https://api.utmify.com.br/api-credentials/orders');
}

function utmify_order_id($in) {
    if (!empty($in['orderId'])) return strval($in['orderId']);
    if (!empty($in['token']))   return strval($in['token']);
    return '';
}

/** 'Y-m-d H:i:s' em UTC 0 a partir de qualquer data reconhecida pelo PHP. */
function utmify_utc($valor) {
    if ($valor === null || $valor === '') return null;
    if (is_numeric($valor)) return gmdate('Y-m-d H:i:s', intval($valor));
    $ts = strtotime($valor);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

/**
 * createdAt estável do pedido. Reaproveita o que já foi gravado; senão deriva do
 * cache da transação; senão usa agora. Persiste para os envios seguintes.
 */
function utmify_created_at($orderId) {
    $local = function_exists('genesys_tx_load') ? genesys_tx_load($orderId) : array();

    if (!empty($local['utmify_created_at'])) {
        return $local['utmify_created_at'];
    }

    $createdAt = null;
    if (!empty($local['createdAt'])) {
        $createdAt = utmify_utc($local['createdAt']);
    }
    if ($createdAt === null) {
        $createdAt = gmdate('Y-m-d H:i:s');
    }

    if (function_exists('genesys_tx_save')) {
        genesys_tx_save($orderId, array('utmify_created_at' => $createdAt));
    }
    return $createdAt;
}

/**
 * Slug estável para o id do produto na Utmify. Deriva do nome interno, então
 * "waiting_payment" e "paid" do mesmo pedido sempre chegam com o mesmo id.
 * Ex.: "Renda Express 4x" -> "renda-express-4x"
 */
function utmify_slug($texto) {
    // Tabela explícita em vez de iconv//TRANSLIT: o resultado do iconv muda
    // conforme a libc do servidor (no macOS "ç" vira "c," e sujaria o slug).
    $acentos = array(
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y',
        'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a','Å'=>'a',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Õ'=>'o','Ô'=>'o','Ö'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'Ç'=>'c','Ñ'=>'n','Ý'=>'y',
    );
    $t = strtr(strval($texto), $acentos);
    $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    $t = trim($t, '-');
    return $t !== '' ? $t : 'produto-01';
}

function utmify_digitos($v) {
    return preg_replace('/\D/', '', strval($v));
}

/**
 * Nome da plataforma para a Utmify, lido do cache da transação.
 *
 * Precisa ser o MESMO em todos os envios do pedido (waiting_payment -> paid),
 * senão a Utmify trata como pedidos de origens diferentes. Por isso sai daqui
 * e não fica escrito na mão em cada ponto de envio.
 */
function utmify_platform($orderId) {
    // O prefixo do id é a fonte primária, igual ao roteamento do api/pix.php:
    // funciona mesmo sem o cache da transação e sem carregar o paradise.php
    // (o api/utmify.php, que manda o waiting_payment, não carrega).
    $prefixo = defined('PARADISE_PREFIX') ? PARADISE_PREFIX : 'pdx_';
    if (strpos(strval($orderId), $prefixo) === 0) return 'Paradise';

    $local = function_exists('genesys_tx_load') ? genesys_tx_load($orderId) : array();
    return (isset($local['gateway']) && $local['gateway'] === 'paradise') ? 'Paradise' : 'Genesys';
}

function utmify_str($v) {
    if ($v === null) return null;
    $v = trim(strval($v));
    return $v === '' ? null : $v;
}

/**
 * Monta o corpo da requisição a partir do payload do front.
 * $fixos sobrescreve status / createdAt / approvedDate / refundedAt.
 */
function utmify_montar_payload($in, $orderId, $fixos) {
    $cli  = isset($in['customer']) && is_array($in['customer']) ? $in['customer'] : array();
    $prod = isset($in['products'][0]) && is_array($in['products'][0]) ? $in['products'][0] : array();
    $trk  = isset($in['trackingParameters']) && is_array($in['trackingParameters']) ? $in['trackingParameters'] : array();
    $com  = isset($in['commission']) && is_array($in['commission']) ? $in['commission'] : array();

    $cents = isset($prod['priceInCents']) ? intval(round(floatval($prod['priceInCents']))) : 0;
    if ($cents <= 0 && isset($com['totalPriceInCents'])) {
        $cents = intval(round(floatval($com['totalPriceInCents'])));
    }

    $total = isset($com['totalPriceInCents']) ? intval(round(floatval($com['totalPriceInCents']))) : $cents;
    if ($total <= 0) $total = $cents;

    // A doc é explícita: userCommissionInCents só pode ser 0 se o vendedor não
    // recebeu nada. Sem o dado real da taxa, espelhamos o valor total.
    $comissao = isset($com['userCommissionInCents']) ? intval(round(floatval($com['userCommissionInCents']))) : 0;
    if ($comissao <= 0) $comissao = $total;

    $nomeProduto = utmify_str(isset($prod['name']) ? $prod['name'] : null);
    if ($nomeProduto === null) $nomeProduto = 'Produto padrao';

    // Sem id explícito, deriva do nome — evita que etapas diferentes do funil
    // cheguem à Utmify com o mesmo identificador.
    $idProduto = utmify_str(isset($prod['id']) ? $prod['id'] : null);
    if ($idProduto === null) $idProduto = utmify_slug($nomeProduto);

    $payload = array(
        'orderId'       => $orderId,
        'platform'      => utmify_platform($orderId),
        'paymentMethod' => 'pix',
        'status'        => $fixos['status'],
        'createdAt'     => $fixos['createdAt'],
        'approvedDate'  => isset($fixos['approvedDate']) ? $fixos['approvedDate'] : null,
        'refundedAt'    => isset($fixos['refundedAt'])   ? $fixos['refundedAt']   : null,
        'customer'      => array(
            'name'     => utmify_str(isset($cli['name']) ? $cli['name'] : null) ?: 'Cliente',
            'email'    => utmify_str(isset($cli['email']) ? $cli['email'] : null) ?: 'cliente@email.com',
            'phone'    => utmify_str(utmify_digitos(isset($cli['phone']) ? $cli['phone'] : '')),
            'document' => utmify_str(utmify_digitos(isset($cli['document']) ? $cli['document'] : '')),
            'country'  => 'BR',
        ),
        'products' => array(array(
            'id'           => $idProduto,
            'name'         => $nomeProduto,
            'planId'       => null,
            'planName'     => null,
            'quantity'     => isset($prod['quantity']) ? max(1, intval($prod['quantity'])) : 1,
            'priceInCents' => $cents,
        )),
        'trackingParameters' => array(
            'src'          => utmify_str(isset($trk['src'])          ? $trk['src']          : null),
            'sck'          => utmify_str(isset($trk['sck'])          ? $trk['sck']          : null),
            'utm_source'   => utmify_str(isset($trk['utm_source'])   ? $trk['utm_source']   : null),
            'utm_medium'   => utmify_str(isset($trk['utm_medium'])   ? $trk['utm_medium']   : null),
            'utm_campaign' => utmify_str(isset($trk['utm_campaign']) ? $trk['utm_campaign'] : null),
            'utm_term'     => utmify_str(isset($trk['utm_term'])     ? $trk['utm_term']     : null),
            'utm_content'  => utmify_str(isset($trk['utm_content'])  ? $trk['utm_content']  : null),
            'gclid'        => utmify_str(isset($trk['gclid'])        ? $trk['gclid']        : null),
            'fbclid'       => utmify_str(isset($trk['fbclid'])       ? $trk['fbclid']       : null),
            'ttclid'       => utmify_str(isset($trk['ttclid'])       ? $trk['ttclid']       : null),
            'keyword'      => utmify_str(isset($trk['keyword'])      ? $trk['keyword']      : null),
        ),
        'commission' => array(
            'totalPriceInCents'     => $total,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $comissao,
            'currency'              => 'BRL',
        ),
    );

    $ip = function_exists('genesys_client_ip') ? genesys_client_ip() : '';
    if ($ip !== '') $payload['customer']['ip'] = $ip;

    $leadId = utmify_str(isset($trk['leadId']) ? $trk['leadId'] : null);
    if ($leadId !== null) $payload['leadId'] = $leadId;

    // isTest=true: a Utmify valida o corpo e não grava o pedido. Serve para
    // conferir a integração sem sujar o dashboard.
    if (!empty($in['isTest'])) $payload['isTest'] = true;

    return $payload;
}

/** Retorna array('httpCode','body','error'). */
function utmify_enviar($payload) {
    $ch = curl_init(UTMIFY_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'x-api-token: ' . UTMIFY_TOKEN,
        ),
        CURLOPT_TIMEOUT => 10,
    ));
    $body  = curl_exec($ch);
    $code  = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);

    return array('httpCode' => $code, 'body' => $body === false ? '' : $body, 'error' => $error);
}

function utmify_log($origem, $orderId, $res, $payload = null) {
    $dir = function_exists('genesys_data_dir') ? genesys_data_dir() : __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $linha = date('c') . ' | ' . $origem
        . ' | tx=' . $orderId
        . ' | http=' . $res['httpCode']
        . ' | resp=' . $res['body']
        . ' | err=' . $res['error'];

    // Só loga o corpo enviado quando a Utmify recusa, para o log não explodir.
    if ($payload !== null && ($res['httpCode'] < 200 || $res['httpCode'] >= 300)) {
        $linha .= ' | req=' . json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    @file_put_contents($dir . '/utmify_log.txt', $linha . "\n", FILE_APPEND | LOCK_EX);
}
