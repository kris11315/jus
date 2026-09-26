<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// Só leitura: o front consulta para EXIBIR nome e preço da etapa, de modo que
// a página e a cobrança saiam sempre da mesma tabela.
require_once __DIR__ . '/produtos.php';

$etapa = isset($_GET['etapa']) ? $_GET['etapa'] : '';
$tabela = catalogo();

if ($etapa === '') {
    echo json_encode(array('success' => false, 'error' => 'Etapa não informada'));
    exit;
}

$chave = strtolower(trim($etapa));
if (!isset($tabela[$chave])) {
    echo json_encode(array('success' => false, 'error' => 'Etapa desconhecida'));
    exit;
}

echo json_encode(array(
    'success'  => true,
    'etapa'    => $chave,
    'nome'     => $tabela[$chave]['nome'],
    'centavos' => intval($tabela[$chave]['centavos']),
    'valor'    => catalogo_valor_brl($tabela[$chave]['centavos']),
    'teste'    => CATALOGO_TESTE ? true : false,
), JSON_UNESCAPED_UNICODE);
