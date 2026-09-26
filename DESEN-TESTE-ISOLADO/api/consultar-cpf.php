<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
    exit;
}

$cpf = preg_replace('/\D/', '', isset($_GET['cpf']) ? $_GET['cpf'] : '');
if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'cpf_invalido'));
    exit;
}

$token = 'd9ad2b68-3f28-44f8-9962-c1c476ff44e0';
$url = 'https://api.amnesiatecnologia.lat/?token=' . urlencode($token) . '&cpf=' . urlencode($cpf);

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => array('Accept: application/json'),
));
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno || $raw === false) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'api_unreachable'));
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'api_invalid_json', 'http' => $http));
    exit;
}

$dados = null;
if (isset($data['DADOS']) && is_array($data['DADOS'])) {
    $dados = $data['DADOS'];
} elseif (isset($data['dados']) && is_array($data['dados'])) {
    $dados = $data['dados'];
} else {
    $dados = $data;
}

if (!is_array($dados)) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'cpf_nao_encontrado'));
    exit;
}

function cpf_pick($src, $keys) {
    foreach ($keys as $k) {
        if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) {
            return $src[$k];
        }
    }
    return '';
}

$nome = cpf_pick($dados, array('nome', 'NOME', 'name', 'Nome'));
$cpfResp = preg_replace('/\D/', '', (string) cpf_pick($dados, array('cpf', 'CPF', 'documento', 'DOCUMENTO')));
if ($cpfResp === '') { $cpfResp = $cpf; }
$nomeMae = cpf_pick($dados, array('nome_mae', 'NOME_MAE', 'mae', 'nomeMae', 'MAE'));
$nomePai = cpf_pick($dados, array('nome_pai', 'NOME_PAI', 'pai', 'nomePai', 'PAI'));
$dataNasc = cpf_pick($dados, array('data_nascimento', 'DATA_NASCIMENTO', 'nasc', 'nascimento', 'NASC', 'dt_nascimento'));
$municipio = cpf_pick($dados, array('municipio', 'MUNICIPIO', 'cidade', 'CIDADE', 'naturalidade', 'NATURALIDADE'));
$sexo = cpf_pick($dados, array('sexo', 'SEXO', 'sex', 'gender'));

if ($nome === '') {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'cpf_nao_encontrado', 'raw_keys' => array_keys($dados)));
    exit;
}

echo json_encode(array(
    'ok' => true,
    'cpf' => $cpfResp,
    'nome' => $nome,
    'nome_mae' => $nomeMae,
    'nome_pai' => $nomePai,
    'data_nascimento' => $dataNasc,
    'municipio' => $municipio,
    'sexo' => $sexo,
    'DADOS' => array(
        'cpf' => $cpfResp,
        'nome' => $nome,
        'nome_mae' => $nomeMae,
        'nome_pai' => $nomePai,
        'data_nascimento' => $dataNasc,
        'municipio' => $municipio,
        'sexo' => $sexo,
    ),
), JSON_UNESCAPED_UNICODE);