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

// pix.php traz genesys.php, paradise.php e meta-capi.php, e é quem decide
// qual gateway gera o PIX.
require_once __DIR__ . '/pix.php';
require_once __DIR__ . '/produtos.php';

$nome     = trim(isset($_POST['nome']) ? $_POST['nome'] : 'Cliente');
$email    = trim(isset($_POST['email']) ? $_POST['email'] : '');
$telefone = preg_replace('/\D/', '', isset($_POST['telefone']) ? $_POST['telefone'] : '');
$cpf      = preg_replace('/\D/', '', isset($_POST['cpf']) ? $_POST['cpf'] : '');
// Igual ao pagamento.php: nome e preço vêm do catálogo, não do front.
$ETAPA    = catalogo_etapa(isset($_POST['etapa']) ? $_POST['etapa'] : '', 'reg');
$valor    = $ETAPA['centavos'];
$produto  = $ETAPA['nome'];

// Nome vazio, telefone/CPF inválidos e e-mail ausente são normalizados dentro
// do pix_criar() — é lá que mora o mínimo que os dois gateways exigem.

$metaObj = array();
foreach (array('utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','gclid','fbclid','ttclid','leadId') as $k) {
    if (!empty($_POST[$k])) $metaObj[$k] = $_POST[$k];
}

$externalRef = 'serasa-up-' . time() . '-' . rand(1000, 9999);

// Genesys primeiro; se ela não entregar um PIX utilizável, o api/pix.php cai
// sozinho para a Paradise. O cache da transação e os sinais do browser para a
// Meta são gravados lá dentro, uma vez só, seja qual for o gateway.
$r = pix_criar(array(
    'nome'        => $nome,
    'email'       => $email,
    'telefone'    => $telefone,
    'cpf'         => $cpf,
    'valor'       => $valor,             // centavos
    'produto'     => $produto,
    'etapa'       => $ETAPA['codigo'],   // o funil progride por isto
    'utm'         => $metaObj,
    'externalRef' => $externalRef,
    'origem'      => 'pagamento-upsell.php',
));

// Só chega aqui quando os DOIS gateways falharam.
if (!$r['ok']) {
    echo json_encode(array(
        'success'   => false,
        'error'     => $r['erro'],
        'http_code' => $r['http_code'],
        'details'   => $r['details'],
    ));
    exit;
}

$txId = $r['token'];

$json = json_encode(array(
    'success'            => true,
    'token'              => $txId !== '' ? $txId : null,
    'valor'              => $valor / 100,
    'pixCode'            => $r['pixCode'],
    'qrcodeImage'        => $r['qrcodeImage'],
    'gateway'            => $r['gateway'],
    'product_title_used' => $produto,
    'customer_name_used' => $r['cliente']['nome'],
    'customer_email_used'=> $r['cliente']['email'],
    'data'               => $r['normalizado'],
));

// Entrega a resposta pro front primeiro (o PIX não pode esperar a Meta) e só
// então manda o InitiateCheckout pela API de Conversões.
ignore_user_abort(true);
header('Connection: close');
header('Content-Length: ' . strlen($json));
echo $json;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}
meta_capi_initiate_checkout($txId);
