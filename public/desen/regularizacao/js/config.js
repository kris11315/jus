// ==========================================
// CONFIGURAÇÃO GLOBAL DO SISTEMA - gov.BR
// ==========================================
//
// Preço e nome dos produtos NÃO ficam mais aqui. A fonte única é a tabela do
// servidor em api/produtos.php, consultada por /api/catalogo.php. Manter uma
// segunda cópia neste arquivo só criava divergência entre o que a página
// mostrava e o que o gateway cobrava.
//
// Para mudar o valor da regularização, edite a etapa "reg" em api/produtos.php.

const GLOBAL_CONFIG = {
    activeGateway: 'bynet'
};
