<?php
// ============================================================
//  Catálogo interno das etapas do funil.
//
//  Nome e preço de cada etapa moram AQUI e em nenhum outro lugar.
//  O front manda apenas o código da etapa (etapa=up1); nada de nome
//  ou valor pela URL, que eram editáveis por quem montasse o link.
//
//  Para mudar preço ou nome de uma oferta, mexa só nesta tabela.
//  Nenhum banco de dados: é um arquivo, viaja no zip do site.
// ============================================================

// ------------------------------------------------------------
//  MODO TESTE
//
//  true  = TODAS as etapas passam a custar CATALOGO_TESTE_CENTAVOS.
//          Os nomes continuam os de produção, para dar pra conferir
//          nomenclatura, redirecionamento e funil na Genesys pagando pouco.
//  false = volta aos preços reais logo abaixo, que ficam intactos.
//
//  DESLIGAR ANTES DE SUBIR TRÁFEGO. É esta linha e mais nada.
// ------------------------------------------------------------
if (!defined('CATALOGO_TESTE'))          define('CATALOGO_TESTE', false);
if (!defined('CATALOGO_TESTE_CENTAVOS')) define('CATALOGO_TESTE_CENTAVOS', 1000); // R$ 10,00

function catalogo() {
    // 'step' = etapa do funil que este pagamento libera (0 = não avança).
    // É por ele que o funil progride, NÃO pelo nome nem pelo valor. Assim dá
    // para renomear ou reprecificar qualquer oferta sem quebrar a navegação.
    $tabela = array(
        'front' => array('nome' => 'Pilates em Casa',              'centavos' => 6892, 'step' => 2),
        // Backredirect: quem gera o PIX do front, não paga e tenta sair. Mesmo
        // produto com 50% de desconto; pagar libera o mesmo step do front.
        'back'  => array('nome' => 'Pilates em Casa (50% OFF)',    'centavos' => 3446, 'step' => 2),
        'up1'   => array('nome' => 'Guia de Receitas Saudáveis',   'centavos' => 4392, 'step' => 4),
        'up2'   => array('nome' => 'Ebook Doces Fit',              'centavos' => 3840, 'step' => 6),
        'up3'   => array('nome' => 'Metódo Resultados Acelerados', 'centavos' => 4560, 'step' => 8),
        'up4'   => array('nome' => 'Grupo VIP',                    'centavos' => 6743, 'step' => 10),
        'reg'   => array('nome' => '10 Exercicios diários',        'centavos' => 7825, 'step' => 0),
    );

    if (CATALOGO_TESTE) {
        foreach ($tabela as $codigo => $item) {
            $tabela[$codigo]['centavos'] = CATALOGO_TESTE_CENTAVOS;
        }
    }

    return $tabela;
}

/**
 * Resolve uma etapa. Código desconhecido ou ausente cai no padrão do endpoint
 * e fica registrado, para o checkout nunca parar por um código errado.
 * Retorna array('codigo','nome','centavos').
 */
function catalogo_etapa($codigo, $padrao = 'front') {
    $tabela = catalogo();
    $codigo = strtolower(trim(strval($codigo)));

    if ($codigo === '' || !isset($tabela[$codigo])) {
        if ($codigo !== '') catalogo_log_desconhecido($codigo, $padrao);
        $codigo = isset($tabela[$padrao]) ? $padrao : 'front';
    }

    $item = $tabela[$codigo];
    return array(
        'codigo'   => $codigo,
        'nome'     => $item['nome'],
        'centavos' => intval($item['centavos']),
        'step'     => intval($item['step']),
    );
}

/**
 * Etapa do funil liberada por um código de etapa. Devolve 0 quando o código é
 * desconhecido, para quem chama poder cair na heurística antiga.
 */
function catalogo_step($codigo) {
    $tabela = catalogo();
    $codigo = strtolower(trim(strval($codigo)));
    return isset($tabela[$codigo]) ? intval($tabela[$codigo]['step']) : 0;
}

/** "1092" -> "10,92" (para exibição no front). */
function catalogo_valor_brl($centavos) {
    return number_format(intval($centavos) / 100, 2, ',', '.');
}

function catalogo_log_desconhecido($codigo, $padrao) {
    $dir = function_exists('genesys_data_dir') ? genesys_data_dir() : __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = date('c') . ' | etapa desconhecida="' . $codigo . '" | usando padrao="' . $padrao . '"' . "\n";
    @file_put_contents($dir . '/catalogo_log.txt', $linha, FILE_APPEND | LOCK_EX);
}
