<?php
// ============================================================
//  Genesys Finance — PIX (Cash In)
//  Docs: https://docs.genesys.finance/docs/introducao
//
//  Base URL ......: https://api.genesys.finance
//  Autenticacao ..: header  api-secret: <API_SECRET>
//  Criar PIX .....: POST /v1/transactions
//  Consultar .....: GET  /v1/transactions/{id}
//  Webhook .......: campo webhook_url no POST (payload flat, responder 200)
//
//  ATENCAO: a Genesys trabalha com valores em REAIS (ex: 68.92).
//  Todo o restante do projeto trabalha em CENTAVOS, entao as conversoes
//  acontecem sempre nas bordas (genesys_cents_to_brl / genesys_brl_to_cents).
// ============================================================

if (!defined('GENESYS_SECRET')) {
    // Painel Genesys > Integracoes > API de Pagamentos > API Secret
    define('GENESYS_SECRET', getenv('GENESYS_API_SECRET') ? getenv('GENESYS_API_SECRET') : 'sk_0bfe7ac89dd41e618d4941f8758304c64b6ffbf15b05a624e893b2c889f4270318f1650b616b9ce183b9e01de83f3b898bbd961b999cf256d64f72779bb5741a');
}
if (!defined('GENESYS_URL')) {
    define('GENESYS_URL', 'https://api.genesys.finance');
}
if (!defined('GENESYS_WEBHOOK_URL')) {
    // Vazio = o webhook_url e montado sozinho a partir do host da requisicao,
    // entao NAO e preciso fixar dominio nenhum aqui. Preencha (ou use a env
    // GENESYS_WEBHOOK_URL) so se o postback tiver que cair em outro dominio.
    define('GENESYS_WEBHOOK_URL', getenv('GENESYS_WEBHOOK_URL') ? getenv('GENESYS_WEBHOOK_URL') : '');
}
if (!defined('GENESYS_WEBHOOK_PATH')) {
    // Caminho do receptor dentro da instalacao (relativo a raiz do site).
    define('GENESYS_WEBHOOK_PATH', '/desen/webhook/index.php');
}

// ---------- valores ----------

function genesys_cents_to_brl($cents) {
    return round(intval($cents) / 100, 2);
}

function genesys_brl_to_cents($brl) {
    return (int) round(floatval($brl) * 100);
}

// ---------- HTTP ----------

/**
 * Chamada autenticada na Genesys.
 * Retorna array('httpCode','raw','data','error') — 'data' ja vem "desembrulhado"
 * (a doc ora responde o objeto direto, ora dentro de {"success":true,"data":{...}}).
 */
function genesys_request($method, $path, $body = null, $timeout = 30) {
    $ch = curl_init(GENESYS_URL . $path);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Accept: application/json',
            'api-secret: ' . GENESYS_SECRET,
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
        'data'     => genesys_unwrap($decoded),
        'error'    => $curlErr,
    );
}

/** Tira o envelope {"success":true,"data":{...}} quando existir. */
function genesys_unwrap($body) {
    if (isset($body['data']) && is_array($body['data'])) return $body['data'];
    return is_array($body) ? $body : array();
}

/** true quando a resposta representa falha (HTTP, hasError ou success:false). */
function genesys_failed($res) {
    if (!empty($res['error'])) return true;
    if ($res['httpCode'] < 200 || $res['httpCode'] >= 300) return true;
    if (isset($res['body']['hasError']) && $res['body']['hasError']) return true;
    if (isset($res['body']['success']) && $res['body']['success'] === false) return true;
    return false;
}

/** Mensagem de erro legivel vinda de qualquer um dos formatos documentados. */
function genesys_error_message($res, $fallback = 'Erro ao gerar PIX') {
    if (!empty($res['error'])) return 'Erro de conexao: ' . $res['error'];
    foreach (array(
        isset($res['body']['error']['message']) ? $res['body']['error']['message'] : null,
        isset($res['body']['message'])          ? $res['body']['message']          : null,
        isset($res['body']['error'])  && is_string($res['body']['error'])  ? $res['body']['error']  : null,
        isset($res['body']['detail']) && is_string($res['body']['detail']) ? $res['body']['detail'] : null,
    ) as $m) {
        if (!empty($m)) return $m;
    }
    return $fallback;
}

// ---------- status ----------

/** AUTHORIZED = "Pago e Autorizado" na Genesys. */
function genesys_is_paid($status) {
    return in_array(strtolower(strval($status)), array('authorized', 'paid', 'approved'), true);
}

// ---------- PIX ----------

/** Extrai o copia-e-cola da resposta (documentado em pix.payload). */
function genesys_pix_code($data) {
    $candidatos = array(
        isset($data['pix']['payload'])   ? $data['pix']['payload']   : null,
        isset($data['pix']['qrcode'])    ? $data['pix']['qrcode']    : null,
        isset($data['pix']['qr_code'])   ? $data['pix']['qr_code']   : null,
        isset($data['pix']['code'])      ? $data['pix']['code']      : null,
        isset($data['pix_payload'])      ? $data['pix_payload']      : null,
        isset($data['qr_code'])          ? $data['qr_code']          : null,
        isset($data['payload'])          ? $data['payload']          : null,
    );
    foreach ($candidatos as $c) {
        if (is_string($c) && $c !== '') return $c;
    }
    return '';
}

/** A Genesys nao devolve imagem do QR — o front gera a partir do copia-e-cola. */
function genesys_qrcode_image($data) {
    $candidatos = array(
        isset($data['pix']['qrcodeImage']) ? $data['pix']['qrcodeImage'] : null,
        isset($data['pix']['qr_code_image']) ? $data['pix']['qr_code_image'] : null,
        isset($data['pix']['image']) ? $data['pix']['image'] : null,
    );
    foreach ($candidatos as $img) {
        if (is_string($img) && $img !== '') {
            if (strpos($img, 'data:image') !== 0 && strpos($img, 'http') !== 0) {
                $img = 'data:image/png;base64,' . $img;
            }
            return $img;
        }
    }
    return '';
}

// ---------- cache local da transacao ----------
// O webhook da Genesys e enxuto (id, external_id, total_amount, status,
// payment_method): nao traz cliente, itens nem UTMs. Por isso gravamos esses
// dados no ato da criacao e reidratamos depois (funil + Utmify).

function genesys_data_dir() {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function genesys_tx_file($id) {
    return genesys_data_dir() . '/tx_' . preg_replace('/[^A-Za-z0-9_\-]/', '', strval($id)) . '.json';
}

function genesys_ext_file($externalId) {
    return genesys_data_dir() . '/ext_' . preg_replace('/[^A-Za-z0-9_\-]/', '', strval($externalId)) . '.json';
}

/**
 * Grava mesclando com o que ja existe.
 *
 * A trava cobre LEITURA e ESCRITA no mesmo flock. Antes o LOCK_EX protegia so
 * o file_put_contents, entao dois processos liam a mesma versao e o segundo
 * apagava o campo do primeiro. Isso acontecia de verdade no momento da
 * confirmacao: o polling do verificar.php e o webhook da Genesys escrevem no
 * mesmo registro ao mesmo tempo.
 */
function genesys_tx_save($id, $dados) {
    if ($id === '' || $id === null) return;
    $file = genesys_tx_file($id);

    $fp = @fopen($file, 'c+');
    if ($fp === false) return;

    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }

    $conteudo = '';
    while (!feof($fp)) {
        $pedaco = fread($fp, 8192);
        if ($pedaco === false) break;
        $conteudo .= $pedaco;
    }

    $atual = $conteudo !== '' ? json_decode($conteudo, true) : array();
    if (!is_array($atual)) $atual = array();

    $novo = array_merge($atual, $dados);
    $novo['updatedAt'] = date('c');

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($novo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!empty($dados['external_id'])) {
        @file_put_contents(genesys_ext_file($dados['external_id']), json_encode(array('id' => $id)), LOCK_EX);
    }
}

function genesys_tx_load($id, $externalId = '') {
    $file = genesys_tx_file($id);
    if (file_exists($file)) {
        $d = json_decode(file_get_contents($file), true);
        if (is_array($d)) return $d;
    }
    if ($externalId !== '') {
        $ext = genesys_ext_file($externalId);
        if (file_exists($ext)) {
            $ref = json_decode(file_get_contents($ext), true);
            if (!empty($ref['id'])) {
                $file = genesys_tx_file($ref['id']);
                if (file_exists($file)) {
                    $d = json_decode(file_get_contents($file), true);
                    if (is_array($d)) return $d;
                }
            }
        }
    }
    return array();
}

// ---------- normalizacao ----------

/**
 * Converte a transacao da Genesys para o formato que o front e o funil ja
 * consomem (amount em centavos, customer.document.number, items[0].title...).
 * $local = cache gravado na criacao, usado para completar o que o webhook omite.
 */
function genesys_normalize($g, $local = array()) {
    if (!is_array($g))     $g = array();
    if (!is_array($local)) $local = array();

    $id     = isset($g['id']) ? strval($g['id']) : (isset($local['id']) ? strval($local['id']) : '');
    $status = strtolower(strval(isset($g['status']) ? $g['status'] : (isset($local['status']) ? $local['status'] : '')));
    $paid   = genesys_is_paid($status);

    // valores da Genesys chegam em reais
    $cents = null;
    foreach (array('total_amount', 'total_value', 'amount', 'value') as $k) {
        if (isset($g[$k]) && is_numeric($g[$k])) { $cents = genesys_brl_to_cents($g[$k]); break; }
    }
    if ($cents === null) $cents = intval(isset($local['amount']) ? $local['amount'] : 0);

    $cli      = isset($g['customer']) && is_array($g['customer']) ? $g['customer'] : array();
    $cliLocal = isset($local['customer']) && is_array($local['customer']) ? $local['customer'] : array();
    $doc = '';
    foreach (array(
        isset($cli['document'])          ? $cli['document']          : null,
        isset($cli['document_number'])   ? $cli['document_number']   : null,
        isset($cliLocal['document'])     ? $cliLocal['document']     : null,
    ) as $d) {
        if (is_string($d) && $d !== '') { $doc = preg_replace('/\D/', '', $d); break; }
    }

    $title = isset($local['product']) ? $local['product'] : '';
    if ($title === '' && !empty($g['items'][0]['title'])) $title = $g['items'][0]['title'];

    $utm = isset($local['utm']) && is_array($local['utm']) ? $local['utm'] : array();

    return array(
        'id'            => $id,
        'external_id'   => isset($g['external_id']) ? $g['external_id'] : (isset($local['external_id']) ? $local['external_id'] : null),
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
        'createdAt' => isset($g['created_at']) ? $g['created_at'] : (isset($local['createdAt']) ? $local['createdAt'] : date('c')),
        'paidAt'    => $paid ? (isset($g['paid_at']) ? $g['paid_at'] : (isset($g['updated_at']) ? $g['updated_at'] : date('c'))) : null,
        // a Genesys nao expoe taxa no retorno da transacao
        'fee'       => array('fixedAmount' => 0),
        // webhook.php espera metadata como string JSON (compat.)
        'metadata'  => !empty($utm) ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
        'genesys'   => $g,
    );
}

/**
 * Monta o bloco customer da Genesys (dados + UTMs, que substituem o antigo
 * "metadata": a Genesys aceita utm_* / click_id / click_type dentro do cliente).
 */
function genesys_customer($nome, $email, $telefone, $cpf, $utm = array()) {
    $customer = array(
        'name'          => $nome,
        'email'         => $email,
        'phone'         => $telefone,
        'document_type' => 'CPF',
        'document'      => $cpf,
    );
    foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term') as $k) {
        if (!empty($utm[$k])) $customer[$k] = strval($utm[$k]);
    }
    foreach (array('ttclid', 'fbclid', 'gclid') as $k) {
        if (!empty($utm[$k])) {
            $customer['click_id']   = strval($utm[$k]);
            $customer['click_type'] = $k;
            break;
        }
    }
    return $customer;
}

/**
 * URL publica desta instalacao. Detecta HTTPS tambem atras de proxy/Cloudflare
 * (com $_SERVER['HTTPS'] vazio o postback sairia como http:// e a Genesys exige
 * que o webhook_url responda em HTTPS).
 */
function genesys_public_base_url() {
    $https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') $https = true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) $https = true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') $https = true;
    if (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) $https = true;
    if (!empty($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) $https = true;

    $host = '';
    foreach (array('HTTP_X_FORWARDED_HOST', 'HTTP_HOST', 'SERVER_NAME') as $k) {
        if (!empty($_SERVER[$k])) { $host = trim(explode(',', $_SERVER[$k])[0]); break; }
    }
    if ($host === '') $host = 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host;
}

/** webhook_url mandado para a Genesys no POST /v1/transactions. */
function genesys_webhook_url() {
    if (GENESYS_WEBHOOK_URL !== '') return GENESYS_WEBHOOK_URL;
    return genesys_public_base_url() . GENESYS_WEBHOOK_PATH;
}

// ---------- e-mail do lead ----------

/**
 * Remove acentos de forma deterministica. Nao usa iconv//TRANSLIT porque o
 * resultado dele muda conforme a libc do servidor (no macOS "c" cedilha vira "c,").
 */
function genesys_sem_acentos($texto) {
    // Tabela explicita em vez de iconv//TRANSLIT: o resultado do iconv muda
    // conforme a libc do servidor, entao o e-mail sairia diferente por maquina.
    $mapa = array(
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i',
        'î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o',
        'ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        'ñ'=>'n','ý'=>'y','Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a',
        'Ä'=>'a','Å'=>'a','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i','Ó'=>'o','Ò'=>'o',
        'Õ'=>'o','Ô'=>'o','Ö'=>'o','Ú'=>'u','Ù'=>'u','Û'=>'u',
        'Ü'=>'u','Ç'=>'c','Ñ'=>'n','Ý'=>'y',
    );
    return strtr(strval($texto), $mapa);
}

/**
 * Monta o e-mail do lead a partir do nome: primeiro nome + ultimo sobrenome.
 * Ex.: "Joao da Silva Santos" -> "joao.santos@hotmail.com"
 *      "Maria"                -> "maria@hotmail.com"
 *      ""                     -> "cliente@hotmail.com"
 */
function genesys_email_do_nome($nome, $dominio = 'hotmail.com') {
    $t = strtolower(genesys_sem_acentos($nome));
    $t = str_replace(array("'", "\u{2019}", '-'), '', $t);  // O'Brien / Ana-Maria juntam
    $t = preg_replace('/[^a-z ]/', ' ', $t);
    $partes = preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY);

    if (empty($partes)) return 'cliente@' . $dominio;

    $primeiro = $partes[0];
    $ultimo   = count($partes) > 1 ? $partes[count($partes) - 1] : '';
    $local    = $ultimo !== '' ? $primeiro . '.' . $ultimo : $primeiro;

    if (strlen($local) > 60) $local = substr($local, 0, 60);
    $local = trim($local, '.');

    return ($local !== '' ? $local : 'cliente') . '@' . $dominio;
}

/** E-mails de placeholder que ja circularam no front e devem ser descartados. */
function genesys_email_placeholder($email) {
    $conhecidos = array(
        'cliente@email.com',
        'cliente@emailvalido.com',
        'cliente@cliente.com',
        'contribuinte@regularizacao.net',
    );
    return in_array(strtolower(trim(strval($email))), $conhecidos, true);
}

/** IP do comprador (campo opcional 'ip' da Genesys). */
function genesys_client_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

/** Log das chamadas (mesmo arquivo usado antes). */
function genesys_log_api($endpoint, $res, $payload) {
    $logDir = __DIR__ . '/../webhook/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/api_pagamento_log.json';
    $apiLogs = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: array()) : array();
    $apiLogs[] = array(
        'time'      => date('c'),
        'gateway'   => 'genesys',
        'endpoint'  => $endpoint,
        'httpCode'  => $res['httpCode'],
        'curlError' => $res['error'],
        'payload'   => $payload,
        'response'  => $res['body'],
    );
    if (count($apiLogs) > 100) $apiLogs = array_slice($apiLogs, -100);
    @file_put_contents($logFile, json_encode($apiLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
