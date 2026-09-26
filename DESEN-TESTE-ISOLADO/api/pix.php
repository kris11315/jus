<?php
// ============================================================
//  PIX — criacao com fallback de gateway e consulta roteada
//
//  Um so lugar decide QUAL gateway gera o PIX e QUAL gateway responde pelo
//  status. Os endpoints (pagamento.php, pagamento-upsell.php, verificar.php)
//  nao falam mais com gateway nenhum direto.
//
//  Ordem: definida por PIX_GATEWAY_PRIMARIO (logo abaixo). O segundo gateway so
//  entra quando o primeiro nao entregou um PIX utilizavel — que sao exatamente
//  as condicoes em que, antes disto existir, o cliente via "erro ao gerar PIX"
//  e a venda morria ali.
//
//  COMO O ROTEAMENTO FUNCIONA
//  A transacao da Paradise vai para o front com o id prefixado (pdx_238). Com
//  isso o verificar.php descobre para quem perguntar so de olhar o id, sem
//  depender de arquivo nenhum. O cache tx_{id}.json guarda 'gateway' como
//  segunda fonte. Id sem prefixo = Genesys, entao toda transacao criada antes
//  desta mudanca continua funcionando sem migracao.
// ============================================================

require_once __DIR__ . '/genesys.php';
require_once __DIR__ . '/paradise.php';
require_once __DIR__ . '/meta-capi.php';

// ------------------------------------------------------------
//  QUEM GERA O PIX PRIMEIRO
//
//  'paradise' = Paradise principal, Genesys como fallback
//  'genesys'  = Genesys principal, Paradise como fallback
//
//  Trocar aqui muda TODAS as paginas de uma vez (atendimento, back, os quatro
//  upsells e a regularizacao), porque nenhuma delas escolhe gateway: elas so
//  mandam o codigo da etapa e recebem o PIX de quem tiver atendido.
//
//  Transacao ja criada nao muda de gateway: o id dela e que manda na hora de
//  consultar o status (ver pix_gateway_do_token), entao inverter isto nao
//  quebra nenhum PIX que ja esteja na mao de um cliente.
// ------------------------------------------------------------
if (!defined('PIX_GATEWAY_PRIMARIO')) {
    define('PIX_GATEWAY_PRIMARIO', getenv('PIX_GATEWAY_PRIMARIO') ? getenv('PIX_GATEWAY_PRIMARIO') : 'paradise');
}

/** Ordem de tentativa: o primario e, depois dele, o outro. */
function pix_ordem_gateways() {
    return strtolower(trim(PIX_GATEWAY_PRIMARIO)) === 'genesys'
        ? array('genesys', 'paradise')
        : array('paradise', 'genesys');
}

/** Gateway responsavel por um id de transacao. */
function pix_gateway_do_token($token) {
    if (paradise_is_token($token)) return 'paradise';
    $tx = genesys_tx_load($token);
    if (!empty($tx['gateway'])) return strval($tx['gateway']);
    return 'genesys';
}

/**
 * Cria o PIX. Tenta a Genesys e, se ela nao entregar, cai para a Paradise.
 *
 * $d aceita: nome, email, telefone, cpf, valor (CENTAVOS), produto,
 *            etapa (codigo do catalogo), utm (array), externalRef, origem.
 *
 * Sucesso: array('ok'=>true, 'gateway', 'token', 'pixCode', 'qrcodeImage', 'normalizado')
 * Falha  : array('ok'=>false, 'erro', 'http_code', 'details', 'tentativas')
 */
function pix_criar($d) {
    $nome     = isset($d['nome']) ? $d['nome'] : 'Cliente';
    $email    = isset($d['email']) ? $d['email'] : '';
    $telefone = isset($d['telefone']) ? $d['telefone'] : '';
    $cpf      = isset($d['cpf']) ? $d['cpf'] : '';
    $valor    = intval(isset($d['valor']) ? $d['valor'] : 0);   // centavos
    $produto  = isset($d['produto']) ? $d['produto'] : 'Produto';
    $etapa    = isset($d['etapa']) ? $d['etapa'] : '';
    $utm      = isset($d['utm']) && is_array($d['utm']) ? $d['utm'] : array();
    $ref      = isset($d['externalRef']) ? $d['externalRef'] : ('pix-' . time() . '-' . rand(1000, 9999));
    $origem   = isset($d['origem']) ? $d['origem'] : 'pix.php';

    // Minimo que os DOIS gateways exigem, garantido aqui e nao em cada endpoint.
    // A Paradise e mais estrita que a Genesys: cliente sem e-mail ela recusa com
    // 400. Sem esta normalizacao o fallback morreria justamente na hora em que
    // precisava salvar a venda, e so se descobriria pelo faturamento.
    $nome     = trim($nome) !== '' ? trim($nome) : 'Cliente';
    $telefone = preg_replace('/\D/', '', $telefone);
    if ($telefone === '') $telefone = '11999999999';
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) $cpf = '52998224725';
    $email = trim($email);
    if ($email === '' || genesys_email_placeholder($email)) $email = genesys_email_do_nome($nome);

    // Capturado uma vez so: os cookies _fbp/_fbc, IP e user agent valem para
    // qualquer gateway, e sao o que permite a Meta atribuir a campanha depois.
    $sinais = meta_sinais_browser();

    $tentativas = array();
    $ultimo     = array('erro' => 'Nenhum gateway configurado', 'http_code' => 0, 'details' => null);

    foreach (pix_ordem_gateways() as $posicao => $gateway) {
        $r = $gateway === 'paradise'
            ? pix_criar_paradise($nome, $email, $telefone, $cpf, $valor, $produto, $ref, $utm, $origem)
            : pix_criar_genesys($nome, $email, $telefone, $cpf, $valor, $produto, $ref, $utm, $origem);

        if ($r['ok']) {
            pix_gravar_cache($r['token'], $gateway, $r, $ref, $valor, $produto, $etapa, $nome, $email, $telefone, $cpf, $utm, $sinais);
            $r['normalizado'] = pix_normalizar($r);
            $r['cliente']     = array('nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'cpf' => $cpf);
            return $r;
        }

        $tentativas[] = array('gateway' => $gateway, 'erro' => $r['erro'], 'http_code' => $r['http_code']);

        // Só a queda do PRIMARIO vira log: e o evento que, sem isto, ninguem
        // veria — o cliente recebe o PIX do outro gateway e segue a vida.
        if ($posicao === 0) pix_log_fallback($origem, $ref, $gateway, $r['erro'], $r['http_code']);

        $ultimo = $r;
    }

    // Todos cairam: devolve o erro do ultimo, que e o mais recente.
    return array(
        'ok'         => false,
        'erro'       => $ultimo['erro'],
        'http_code'  => $ultimo['http_code'],
        'details'    => isset($ultimo['details']) ? $ultimo['details'] : null,
        'tentativas' => $tentativas,
    );
}

/** POST /v1/transactions — valores em REAIS. */
function pix_criar_genesys($nome, $email, $telefone, $cpf, $valor, $produto, $ref, $utm, $origem) {
    $valorBRL = genesys_cents_to_brl($valor);

    $payload = array(
        'external_id'    => $ref,
        'total_amount'   => $valorBRL,
        'payment_method' => 'PIX',
        'webhook_url'    => genesys_webhook_url(),
        'items'          => array(array(
            'id'          => $ref,
            'title'       => $produto,
            'description' => $produto,
            'price'       => $valorBRL,
            'quantity'    => 1,
            'is_physical' => false,
        )),
        'customer' => genesys_customer($nome, $email, $telefone, $cpf, $utm),
    );

    $ip = genesys_client_ip();
    if ($ip !== '') $payload['ip'] = $ip;

    $res = genesys_request('POST', '/v1/transactions', $payload);
    genesys_log_api($origem, $res, $payload);

    if (genesys_failed($res)) {
        return array(
            'ok'        => false,
            'erro'      => genesys_error_message($res),
            'http_code' => $res['httpCode'],
            'details'   => $res['body'],
        );
    }

    $g       = $res['data'];
    $pixCode = genesys_pix_code($g);

    // 200 sem copia-e-cola tambem e falha: o cliente nao tem como pagar.
    if ($pixCode === '') {
        return array(
            'ok'        => false,
            'erro'      => 'PIX gerado sem código copia e cola',
            'http_code' => $res['httpCode'],
            'details'   => $res['body'],
        );
    }

    $token = isset($g['id']) ? strval($g['id']) : '';

    return array(
        'ok'          => true,
        'gateway'     => 'genesys',
        'token'       => $token,
        'pixCode'     => $pixCode,
        'qrcodeImage' => genesys_qrcode_image($g),
        'bruto'       => $g,
        'status'      => isset($g['status']) ? strtolower($g['status']) : 'pending',
    );
}

/** POST /api/v1/transaction.php — valores em CENTAVOS. */
function pix_criar_paradise($nome, $email, $telefone, $cpf, $valor, $produto, $ref, $utm, $origem) {
    $payload = array(
        'amount'       => $valor,           // ja em centavos: sem conversao
        'description'  => $produto,
        'reference'    => $ref,
        'postback_url' => paradise_webhook_url(),
        'offer_link'   => paradise_offer_link(),
        'customer'     => paradise_customer($nome, $email, $telefone, $cpf),
    );

    // Produto nao cadastrado na plataforma: source=api_externa dispensa o hash.
    if (PARADISE_PRODUCT_HASH !== '') {
        $payload['productHash'] = PARADISE_PRODUCT_HASH;
    } else {
        $payload['source'] = 'api_externa';
    }

    $tracking = paradise_tracking($utm);
    if (!empty($tracking)) $payload['tracking'] = $tracking;

    $res = paradise_request('POST', '/api/v1/transaction.php', $payload);
    paradise_log_api($origem, $res, $payload);

    if (paradise_failed($res)) {
        return array(
            'ok'        => false,
            'erro'      => paradise_error_message($res),
            'http_code' => $res['httpCode'],
            'details'   => $res['body'],
        );
    }

    $p       = $res['data'];
    $pixCode = paradise_pix_code($p);

    if ($pixCode === '') {
        return array(
            'ok'        => false,
            'erro'      => 'PIX gerado sem código copia e cola',
            'http_code' => $res['httpCode'],
            'details'   => $res['body'],
        );
    }

    $interno = isset($p['transaction_id']) ? strval($p['transaction_id']) : '';

    return array(
        'ok'          => true,
        'gateway'     => 'paradise',
        'token'       => paradise_token($interno),
        'paradise_id' => $interno,
        'pixCode'     => $pixCode,
        'qrcodeImage' => paradise_qrcode_image($p),
        'bruto'       => $p,
        // ATENCAO: o "status" da resposta de criacao e da REQUISICAO ("success"),
        // nao da transacao. PIX recem-criado esta sempre pendente — gravar
        // "success" aqui envenenaria o cache que o polling le depois.
        'status'      => 'pending',
    );
}

/**
 * Transacao no formato que o front e o funil ja consomem, venha de onde vier.
 * O bloco 'pix' e o que o front le (data.pix.qrcode / data.pix.code).
 */
function pix_normalizar($r) {
    $local = genesys_tx_load($r['token']);
    $n = $r['gateway'] === 'paradise'
        ? paradise_normalize($r['bruto'], $local)
        : genesys_normalize($r['bruto'], $local);

    $n['gateway'] = $r['gateway'];
    $n['pix'] = array(
        'payload'     => $r['pixCode'],
        'qrcode'      => $r['pixCode'],
        'code'        => $r['pixCode'],
        'qrcodeImage' => $r['qrcodeImage'],
    );
    return $n;
}

/**
 * Cache da transacao. Nenhum dos dois webhooks devolve cliente, produto ou
 * UTMs, entao o que grava aqui e o que alimenta o funil, a Utmify e a Meta
 * depois. O campo 'gateway' e o que permite rotear a consulta.
 */
function pix_gravar_cache($token, $gateway, $r, $ref, $valor, $produto, $etapa, $nome, $email, $telefone, $cpf, $utm, $sinais) {
    if ($token === '') return;

    $dados = array(
        'id'          => $token,
        'gateway'     => $gateway,
        'external_id' => $ref,
        'status'      => isset($r['status']) ? $r['status'] : 'pending',
        'amount'      => $valor,            // centavos
        'product'     => $produto,
        'etapa'       => $etapa,            // o funil progride por isto
        'customer'    => array('name' => $nome, 'email' => $email, 'phone' => $telefone, 'document' => $cpf),
        'utm'         => $utm,
        // Cookies _fbp/_fbc, IP, user agent e URL: o Purchase server-side
        // (api/meta-capi.php) precisa disso pra Meta atribuir a campanha.
        'meta'        => $sinais,
        'createdAt'   => date('c'),
    );
    if (!empty($r['paradise_id'])) $dados['paradise_id'] = $r['paradise_id'];

    genesys_tx_save($token, $dados);
}

/**
 * Consulta o status na origem certa.
 * Retorna array('ok', 'gateway', 'paid', 'status', 'data', 'http_code').
 */
function pix_consultar($token) {
    $token   = strval($token);
    $gateway = pix_gateway_do_token($token);
    $local   = genesys_tx_load($token);

    if ($gateway === 'paradise') {
        $interno = paradise_tx_id($token);
        if ($interno === '' && !empty($local['paradise_id'])) $interno = strval($local['paradise_id']);

        $res = paradise_request('GET', '/api/v1/query.php?action=get_transaction&id=' . rawurlencode($interno), null, 20);
        if (!empty($res['error'])) {
            return array('ok' => false, 'gateway' => 'paradise', 'erro' => $res['error'], 'http_code' => $res['httpCode']);
        }

        $p    = $res['data'];
        $data = paradise_normalize($p, $local);

        return array(
            'ok'        => true,
            'gateway'   => 'paradise',
            'paid'      => paradise_is_paid(isset($p['status']) ? $p['status'] : ''),
            'status'    => isset($p['status']) ? strtoupper($p['status']) : 'PENDING',
            'data'      => $data,
            'http_code' => $res['httpCode'],
        );
    }

    $res = genesys_request('GET', '/v1/transactions/' . rawurlencode($token), null, 20);
    if (!empty($res['error'])) {
        return array('ok' => false, 'gateway' => 'genesys', 'erro' => $res['error'], 'http_code' => $res['httpCode']);
    }

    $g    = $res['data'];
    $data = genesys_normalize($g, $local);

    return array(
        'ok'        => true,
        'gateway'   => 'genesys',
        'paid'      => genesys_is_paid(isset($g['status']) ? $g['status'] : ''),
        'status'    => isset($g['status']) ? strtoupper($g['status']) : 'PENDING',
        'data'      => $data,
        'http_code' => $res['httpCode'],
    );
}

/**
 * Registra toda vez que o gateway primario falhou e o fallback entrou. Sem
 * isto o outro assumiria em silencio e so se descobriria pelo extrato.
 */
function pix_log_fallback($origem, $ref, $gatewayQueCaiu, $erro, $httpCode) {
    $linha = date('c') . ' | FALLBACK | origem=' . $origem . ' | ref=' . $ref
        . ' | caiu=' . $gatewayQueCaiu
        . ' | http=' . $httpCode
        . ' | erro=' . str_replace("\n", ' ', strval($erro)) . "\n";
    @file_put_contents(genesys_data_dir() . '/fallback_log.txt', $linha, FILE_APPEND | LOCK_EX);
}
