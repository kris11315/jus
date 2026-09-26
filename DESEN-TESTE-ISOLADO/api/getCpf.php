<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? $_POST['cpf'] ?? '');
if ($cpf === '' || strlen($cpf) !== 11) {
    echo json_encode(['success' => false, 'message' => 'CPF inválido. Deve conter 11 dígitos.']);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['success' => false, 'message' => 'Extensão cURL não disponível no servidor']);
    exit;
}

function cpf_normaliza_sexo(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    if (preg_match('/(masculino|feminino)/i', $raw, $m)) {
        return ucfirst(strtolower($m[1]));
    }
    $s = strtoupper($raw);
    if (in_array($s, ['M', 'MASCULINO', 'MALE'], true)) {
        return 'Masculino';
    }
    if (in_array($s, ['F', 'FEMININO', 'FEMALE'], true)) {
        return 'Feminino';
    }
    return $raw;
}

function cpf_fetch(string $url, array $extraHeaders = []): array
{
    $headers = array_merge(
        [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        ],
        $extraHeaders
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    return [$body, $code, $err];
}

$magmaHeaders = [];

$magmaTokens = [
    ''
];

$networkFail = 0;

foreach ($magmaTokens as $i => $token) {
    [$body, $httpCode, $curlErr] = cpf_fetch(
        'https://base2.sistemafullativo.online:80/api/cad?CPF=' . urlencode($cpf),
        $magmaHeaders
    );

    if ($curlErr !== '') {
        $networkFail++;
        continue;
    }

    $j = json_decode((string) $body, true);
    if ($httpCode !== 200 || !is_array($j) || empty($j['nome'])) {
        continue;
    }

    $nasc = trim((string) ($j['dataNascimento'] ?? ''));

    echo json_encode([
        'success'    => true,
        'nome'       => trim((string) $j['nome']),
        'cpf'        => preg_replace('/\D/', '', (string) ($j['cpf'] ?? $cpf)) ?: $cpf,
        'nascimento' => $nasc,
        'mae'        => trim((string) ($j['nomeMae'] ?? '')) ?: null,
        'sexo'       => cpf_normaliza_sexo(trim((string) ($j['sexo'] ?? ''))) ?: null,
        'data'       => $j,
        '_provider'  => 'base2',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = $networkFail === count($magmaTokens)
    ? 'Serviço de consulta temporariamente indisponível. Tente novamente em instantes.'
    : 'CPF não encontrado nas bases consultadas.';

echo json_encode([
    'success' => false,
    'message' => $message,
], JSON_UNESCAPED_UNICODE);