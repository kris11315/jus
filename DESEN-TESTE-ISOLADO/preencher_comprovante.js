/**
 * Preenche o comprovante PDF com os dados do eleitor
 * Coordenadas mapeadas em pixels
 */

const COORDENADAS = {
  data: { x: 115, y: 191 },
  turno: { x: 262, y: 189 },
  titulo: { x: 363, y: 188 },
  nasc: { x: 661, y: 187 },
  nome: { x: 102, y: 265 },
  assin: { x: 102, y: 313 },
  uf: { x: 132, y: 390 },
  municipio: { x: 184, y: 393 },
  zona: { x: 604, y: 391 },
  secao: { x: 661, y: 390 }
};

async function preencherComprovante(dados) {
  // Carrega pdf.js se não estiver carregado
  if (!window.pdfjsLib) {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    document.head.appendChild(script);
    await new Promise(resolve => script.onload = resolve);
  }

  try {
    // Carrega o PDF
    const pdfUrl = 'images/comprovante.pdf';
    const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
    const page = await pdf.getPage(1);

    // Renderiza em canvas
    const scale = 1.5;
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    const context = canvas.getContext('2d');
    await page.render({ canvasContext: context, viewport }).promise;

    // Adiciona texto nos campos
    context.fillStyle = '#000000';
    context.font = 'bold 11px Arial';
    context.textBaseline = 'top';

    // Mapeamento dos dados para as coordenadas
    const dataExibicao = dados.data ? new Date(dados.data).toLocaleDateString('pt-BR') : '';
    const anoNasc = dados.nasc ? new Date(dados.nasc).getFullYear() : '';

    // Desenha cada campo
    context.fillText(dataExibicao, COORDENADAS.data.x, COORDENADAS.data.y);
    context.fillText(dados.turno || '', COORDENADAS.turno.x, COORDENADAS.turno.y);
    context.fillText(dados.titulo || '', COORDENADAS.titulo.x, COORDENADAS.titulo.y);
    context.fillText(anoNasc, COORDENADAS.nasc.x, COORDENADAS.nasc.y);
    context.fillText((dados.nome || '').toUpperCase(), COORDENADAS.nome.x, COORDENADAS.nome.y);
    // Assinatura fica em branco (será desenhada pelo canvas do formulário)
    context.fillText(dados.uf || '', COORDENADAS.uf.x, COORDENADAS.uf.y);
    context.fillText(dados.municipio || '', COORDENADAS.municipio.x, COORDENADAS.municipio.y);
    context.fillText(dados.zona || '', COORDENADAS.zona.x, COORDENADAS.zona.y);
    context.fillText(dados.secao || '', COORDENADAS.secao.x, COORDENADAS.secao.y);

    // Converte para imagem
    const imagemBase64 = canvas.toDataURL('image/png');

    return {
      success: true,
      imagem: imagemBase64,
      filename: `Comprovante_${dados.cpf || 'eleitor'}.png`
    };

  } catch (error) {
    console.error('Erro ao preencher comprovante:', error);
    return {
      success: false,
      error: error.message
    };
  }
}

// Exporta para usar em outros arquivos
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { preencherComprovante, COORDENADAS };
}
