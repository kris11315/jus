<?php
// ============================================================
//  Meta — API de Conversões (Purchase server-side)
//
//  Por que existe: o Purchase que a Utmify manda pra Meta em pedidos via API
//  chega sem fbc/fbp/IP/user agent (qualidade 3.2/10) e não atribui a campanha.
//  Aqui a gente manda o Purchase com tudo que foi capturado na criação do PIX
//  (cookies _fbp/_fbc, IP, user agent, URL) mais CPF/nome com hash, e usa
//  event_id = id da transação. O browser (desen/js/meta-pixel.js) manda o
//  mesmo evento com o mesmo eventID, e a Meta deduplica.
//
//  Configuração: token da API de Conversões gerado no Gerenciador de Eventos
//  (Fontes de dados > pixel > Configurações > API de Conversões > Gerar token).
//  Preencher META_CAPI_TOKEN abaixo ou exportar META_CAPI_TOKEN no ambiente.
//  Sem token, o envio é pulado e fica registrado no log.
// ============================================================

if (!defined('META_PIXEL_ID')) {
    define('META_PIXEL_ID', getenv('META_PIXEL_ID') ? getenv('META_PIXEL_ID') : '1022079780841693');
}
if (!defined('META_CAPI_TOKEN')) {
    define('META_CAPI_TOKEN', getenv('META_CAPI_TOKEN') ? getenv('META_CAPI_TOKEN') : 'EAAPb19DNLAQBSYBw2k52VCOIsj0si2l7elAEjInpd7cZAuNJCKgzP1Chk2sHbKvlpp0lZBqxRsJZC7CrjljL55d2KNpKclC3gr56H2rhVGLct4yoNVJwsJQgg1GqdZBBZCe8QR9yr89DuKGLobDxLQLvrliZAY3p8zafRnuHPttDwLskZBRW2co8aXij153wsu4oAZDZD');
}
// Código de "Testar eventos" do Gerenciador de Eventos (ex.: TEST12345). Só
// para conferir a integração; deixar vazio em produção.
if (!defined('META_TEST_EVENT_CODE')) {
    // Só se aplica a transações de teste (utm_source=teste, ver meta_tx_is_test),
    // então pode ficar preenchido sem desviar venda real da otimização.
    define('META_TEST_EVENT_CODE', getenv('META_TEST_EVENT_CODE') ? getenv('META_TEST_EVENT_CODE') : '');
}
if (!defined('META_GRAPH_VERSION')) {
    define('META_GRAPH_VERSION', 'v21.0');
}

/**
 * Sinais do browser no momento em que o PIX é criado. O front está no mesmo
 * domínio da API, então os cookies _fbp/_fbc que o fbevents.js grava chegam
 * aqui sozinhos. Gravar isso no cache da transação é o que permite mandar o
 * Purchase depois, quando o webhook da Genesys confirmar o pagamento.
 */
function meta_sinais_browser() {
    $fbc = isset($_COOKIE['_fbc']) ? trim($_COOKIE['_fbc']) : '';
    if ($fbc === '' && !empty($_POST['fbclid'])) {
        $fbc = 'fb.1.' . intval(microtime(true) * 1000) . '.' . trim($_POST['fbclid']);
    }
    return array(
        'fbp'       => isset($_COOKIE['_fbp']) ? trim($_COOKIE['_fbp']) : '',
        'fbc'       => $fbc,
        'ip'        => function_exists('genesys_client_ip') ? genesys_client_ip() : '',
        'ua'        => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : '',
        'sourceUrl' => isset($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 1024) : '',
    );
}

/** Transação de teste: criada com utm_source=teste na URL. Só essas vão pra aba "Eventos de teste". */
function meta_tx_is_test($tx) {
    $src = isset($tx['utm']['utm_source']) ? strtolower(trim($tx['utm']['utm_source'])) : '';
    return $src === 'teste';
}

function meta_hash($v) {
    $v = trim(strval($v));
    return $v === '' ? null : hash('sha256', $v);
}

function meta_normalizar_nome($nome) {
    $nome = strtolower(trim(strval($nome)));
    $nome = preg_replace('/\s+/', ' ', $nome);
    return $nome;
}

function meta_telefone_placeholder($tel) {
    return in_array($tel, array('', '11999999999', '11988887777'), true);
}

function meta_email_placeholder($email) {
    if (function_exists('genesys_email_placeholder') && genesys_email_placeholder($email)) return true;
    return $email === '' || preg_match('/@(email\.com|regularizacao\.net)$/i', $email) === 1;
}

function meta_slug($texto) {
    if (function_exists('utmify_slug')) return utmify_slug($texto);
    $t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', strval($texto)));
    return trim($t, '-') ?: 'produto';
}

/**
 * Monta o user_data a partir do cache da transação (tx_{id}.json).
 * E-mail e telefone gerados/placeholder ficam de fora: hash de dado falso não
 * casa com ninguém e só polui o evento.
 */
function meta_user_data($tx) {
    $cli = isset($tx['customer']) && is_array($tx['customer']) ? $tx['customer'] : array();
    $meta = isset($tx['meta']) && is_array($tx['meta']) ? $tx['meta'] : array();

    $ud = array();

    $cpf = preg_replace('/\D/', '', isset($cli['document']) ? $cli['document'] : '');
    if (strlen($cpf) === 11 && $cpf !== '52998224725' && $cpf !== '11144477735') {
        $ud['external_id'] = array(meta_hash($cpf));
    }

    $nome = meta_normalizar_nome(isset($cli['name']) ? $cli['name'] : '');
    if ($nome !== '' && $nome !== 'cliente' && $nome !== 'contribuinte') {
        $partes = explode(' ', $nome);
        $ud['fn'] = array(meta_hash($partes[0]));
        if (count($partes) > 1) $ud['ln'] = array(meta_hash($partes[count($partes) - 1]));
    }

    // O funil não pede e-mail: o que está no cache foi inventado a partir do
    // nome (nome.sobrenome@hotmail.com) só pra satisfazer o gateway. Mandar
    // isso com hash pra Meta pode casar a compra com um desconhecido que tenha
    // esse e-mail de verdade. Só entra se não for o e-mail gerado.
    $email = strtolower(trim(isset($cli['email']) ? $cli['email'] : ''));
    $gerado = function_exists('genesys_email_do_nome')
        ? strtolower(genesys_email_do_nome(isset($cli['name']) ? $cli['name'] : ''))
        : '';
    if (!meta_email_placeholder($email) && $email !== $gerado) $ud['em'] = array(meta_hash($email));

    $tel = preg_replace('/\D/', '', isset($cli['phone']) ? $cli['phone'] : '');
    if (!meta_telefone_placeholder($tel)) $ud['ph'] = array(meta_hash('55' . $tel));

    $ud['country'] = array(meta_hash('br'));

    if (!empty($meta['fbp'])) $ud['fbp'] = $meta['fbp'];
    if (!empty($meta['fbc'])) $ud['fbc'] = $meta['fbc'];
    if (!empty($meta['ip']))  $ud['client_ip_address'] = $meta['ip'];
    if (!empty($meta['ua']))  $ud['client_user_agent'] = $meta['ua'];

    return $ud;
}

function meta_log($linha) {
    $dir = function_exists('genesys_data_dir') ? genesys_data_dir() : __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/meta_capi_log.txt', date('c') . ' | ' . $linha . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Trava atômica por transação: webhook da Genesys e polling do verificar.php
 * detectam o pagamento ao mesmo tempo, e só um dos dois pode enviar.
 */
function meta_reservar_envio($txId) {
    $dir = function_exists('genesys_data_dir') ? genesys_data_dir() : __DIR__ . '/data';
    $lock = $dir . '/meta_purchase_' . preg_replace('/[^A-Za-z0-9_\-]/', '', strval($txId)) . '.lock';
    $fp = @fopen($lock, 'x');
    if ($fp === false) return false;
    fwrite($fp, date('c'));
    fclose($fp);
    return true;
}

function meta_liberar_envio($txId) {
    $dir = function_exists('genesys_data_dir') ? genesys_data_dir() : __DIR__ . '/data';
    @unlink($dir . '/meta_purchase_' . preg_replace('/[^A-Za-z0-9_\-]/', '', strval($txId)) . '.lock');
}

/**
 * Envia o Purchase de uma transação paga. Idempotente por transação.
 * Retorna array('sent' => bool, 'reason' => string).
 */
function meta_capi_purchase($txId, $paidAt = null) {
    $txId = strval($txId);
    if ($txId === '') return array('sent' => false, 'reason' => 'tx vazia');

    if (META_CAPI_TOKEN === '') {
        meta_log('tx=' . $txId . ' | PULADO: META_CAPI_TOKEN vazio (configurar em api/meta-capi.php)');
        return array('sent' => false, 'reason' => 'sem token');
    }

    $tx = function_exists('genesys_tx_load') ? genesys_tx_load($txId) : array();
    if (empty($tx)) {
        meta_log('tx=' . $txId . ' | PULADO: cache da transação não encontrado');
        return array('sent' => false, 'reason' => 'sem cache');
    }
    if (!empty($tx['simulado'])) {
        return array('sent' => false, 'reason' => 'pagamento simulado');
    }
    if (!meta_reservar_envio($txId)) {
        return array('sent' => false, 'reason' => 'já enviado');
    }

    $cents = intval(isset($tx['amount']) ? $tx['amount'] : 0);
    $produto = isset($tx['product']) ? strval($tx['product']) : 'Produto';
    $meta = isset($tx['meta']) && is_array($tx['meta']) ? $tx['meta'] : array();

    $eventTime = time();
    if ($paidAt) {
        $ts = is_numeric($paidAt) ? intval($paidAt) : strtotime($paidAt);
        // A Meta aceita até 7 dias no passado; nunca no futuro.
        if ($ts && $ts <= time() && $ts >= time() - 7 * 86400) $eventTime = $ts;
    }

    $evento = array(
        'event_name'    => 'Purchase',
        'event_time'    => $eventTime,
        'event_id'      => $txId,
        'action_source' => 'website',
        'user_data'     => meta_user_data($tx),
        'custom_data'   => array(
            'value'        => round($cents / 100, 2),
            'currency'     => 'BRL',
            'content_name' => $produto,
            'content_ids'  => array(meta_slug($produto)),
            'content_type' => 'product',
            'num_items'    => 1,
            'order_id'     => $txId,
        ),
    );
    // Só o caminho da página. A query string do funil carrega cpf, nome e
    // nascimento em texto puro, e isso não pode ir pra Meta sem hash.
    if (!empty($meta['sourceUrl'])) $evento['event_source_url'] = strtok($meta['sourceUrl'], '?');

    $body = array('data' => array($evento));
    if (META_TEST_EVENT_CODE !== '' && meta_tx_is_test($tx)) $body['test_event_code'] = META_TEST_EVENT_CODE;

    $url = 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/' . META_PIXEL_ID . '/events?access_token=' . rawurlencode(META_CAPI_TOKEN);

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT        => 10,
    ));
    $resp = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $err  = curl_error($ch);
    if (PHP_VERSION_ID < 80000) curl_close($ch);

    $ok = $code >= 200 && $code < 300;

    $sinais = array();
    foreach (array('fbp', 'fbc', 'client_ip_address', 'client_user_agent', 'external_id', 'fn', 'em', 'ph') as $k) {
        if (!empty($evento['user_data'][$k])) $sinais[] = $k;
    }
    meta_log('tx=' . $txId . ' | http=' . $code . ' | sinais=' . implode(',', $sinais)
        . ' | value=' . $evento['custom_data']['value']
        . ' | resp=' . ($resp === false ? '' : $resp) . ($err !== '' ? ' | err=' . $err : ''));

    if ($ok) {
        if (function_exists('genesys_tx_save')) {
            genesys_tx_save($txId, array('meta_purchase_sent_at' => date('c')));
        }
    } else {
        // Falha de rede/token: libera para o próximo detector tentar de novo.
        meta_liberar_envio($txId);
    }

    return array('sent' => $ok, 'reason' => $ok ? 'ok' : ('http ' . $code));
}


/**
 * InitiateCheckout pela API de Conversões, no momento em que o PIX é criado.
 * event_id = id da transação + ':ic', o mesmo eventID que o browser usa em
 * desen/js/meta-pixel.js, então a Meta deduplica com o evento do pixel.
 *
 * Por quê: o Gerenciador de Eventos cobrava cobertura de IC pela API
 * ("seu servidor está enviando 85 eventos a menos que o pixel"). Sem isso a
 * Meta só tem o IC do browser, que some com bloqueador ou iOS.
 *
 * Chamar DEPOIS de responder o front (ver pagamento.php), com timeout curto,
 * pra não atrasar a entrega do PIX.
 */
function meta_capi_initiate_checkout($txId) {
    $txId = strval($txId);
    if ($txId === '' || META_CAPI_TOKEN === '') return array('sent' => false, 'reason' => 'sem tx ou token');

    $tx = function_exists('genesys_tx_load') ? genesys_tx_load($txId) : array();
    if (empty($tx)) return array('sent' => false, 'reason' => 'sem cache');

    $cents   = intval(isset($tx['amount']) ? $tx['amount'] : 0);
    $produto = isset($tx['product']) ? strval($tx['product']) : 'Produto';
    $meta    = isset($tx['meta']) && is_array($tx['meta']) ? $tx['meta'] : array();

    $evento = array(
        'event_name'    => 'InitiateCheckout',
        'event_time'    => time(),
        'event_id'      => $txId . ':ic',
        'action_source' => 'website',
        'user_data'     => meta_user_data($tx),
        'custom_data'   => array(
            'value'        => round($cents / 100, 2),
            'currency'     => 'BRL',
            'content_name' => $produto,
            'content_ids'  => array(meta_slug($produto)),
            'content_type' => 'product',
            'num_items'    => 1,
        ),
    );
    if (!empty($meta['sourceUrl'])) $evento['event_source_url'] = strtok($meta['sourceUrl'], '?');

    $body = array('data' => array($evento));
    if (META_TEST_EVENT_CODE !== '' && meta_tx_is_test($tx)) $body['test_event_code'] = META_TEST_EVENT_CODE;

    $url = 'https://graph.facebook.com/' . META_GRAPH_VERSION . '/' . META_PIXEL_ID . '/events?access_token=' . rawurlencode(META_CAPI_TOKEN);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ));
    $resp = curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $err  = curl_error($ch);
    if (PHP_VERSION_ID < 80000) curl_close($ch);

    $ok = $code >= 200 && $code < 300;
    $sinais = array();
    foreach (array('fbp', 'fbc', 'client_ip_address', 'client_user_agent', 'external_id', 'fn') as $k) {
        if (!empty($evento['user_data'][$k])) $sinais[] = $k;
    }
    meta_log('IC tx=' . $txId . ' | http=' . $code . ' | sinais=' . implode(',', $sinais)
        . ' | value=' . $evento['custom_data']['value']
        . ' | resp=' . ($resp === false ? '' : substr($resp, 0, 200)) . ($err !== '' ? ' | err=' . $err : ''));

    return array('sent' => $ok, 'reason' => $ok ? 'ok' : ('http ' . $code));
}
