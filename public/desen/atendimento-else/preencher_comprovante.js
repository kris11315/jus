/**
 * Preenche o comprovante PDF com os dados do eleitor
 * Coordenadas mapeadas em pixels
 */

// Coordenadas - Página 1 (Lado Esquerdo - Comprovante)
// Campos preenchidos pelo usuário
const COORDENADAS_PAG1 = {
  data_dia: { x: 111, y: 185 },
  data_mes: { x: 140, y: 185 },
  data_ano: { x: 170, y: 185 },
  turno1_checkbox: { x: 263, y: 189 },
  turno2_checkbox: { x: 315, y: 189 },
  titulo: { x: 383, y: 188 },
  nasc: { x: 665, y: 188 },
  nome: { x: 117, y: 266 },
  assinatura: { x: 117, y: 290, width: 180, height: 45 }
};

// Coordenadas - Página 2 (Lado Direito - Requerimento)
const COORDENADAS_PAG2 = {
  data_dia: { x: 794, y: 185 },
  data_mes: { x: 823, y: 185 },
  data_ano: { x: 852, y: 185 },
  turno1_checkbox: { x: 923, y: 189 },
  turno2_checkbox: { x: 970, y: 189 },
  titulo: { x: 1025, y: 188 },
  nasc: { x: 1230, y: 188 },
  nome: { x: 813, y: 256 }
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

    // Função para carregar imagem como promise
    function loadImage(src) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
      });
    }

    // Carrega a assinatura se disponível (sem fundo branco - já transparente no base64)
    if (dados.assinatura && dados.assinatura.length > 100) {
      try {
        const assinImg = await loadImage(dados.assinatura);
        context.drawImage(assinImg, COORDENADAS_PAG1.assinatura.x, COORDENADAS_PAG1.assinatura.y,
                         COORDENADAS_PAG1.assinatura.width, COORDENADAS_PAG1.assinatura.height);
      } catch (e) {
        console.warn('Erro ao carregar assinatura:', e);
      }
    }

    // Configuração de texto
    context.fillStyle = '#000000';
    context.font = 'bold 16px Arial';
    context.textBaseline = 'top';

    // Formatação de dados
    const dataNascFormatada = dados.nasc ? new Date(dados.nasc).toLocaleDateString('pt-BR') : '';

    // Divide data em dia, mês, ano (sem timezone)
    let dataDia = '', dataMes = '', dataAno = '';
    if (dados.data) {
      // Parseia sem timezone para evitar off-by-one
      const parts = dados.data.split('-');
      if (parts.length === 3) {
        dataDia = String(parseInt(parts[2])).padStart(2, '0');
        dataMes = String(parseInt(parts[1])).padStart(2, '0');
        dataAno = parts[0];
      }
    }

    // PÁGINA 1 (Lado Esquerdo - Comprovante)
    context.fillText(dataDia, COORDENADAS_PAG1.data_dia.x, COORDENADAS_PAG1.data_dia.y);
    context.fillText(dataMes, COORDENADAS_PAG1.data_mes.x, COORDENADAS_PAG1.data_mes.y);
    context.fillText(dataAno, COORDENADAS_PAG1.data_ano.x, COORDENADAS_PAG1.data_ano.y);

    // Marca o turno selecionado com X maior (aceita diferentes formatos)
    var turnoStr = String(dados.turno || '').toLowerCase();
    var isPrimeiro = turnoStr.includes('1') || turnoStr.includes('primeiro') || dados.turno === '1º';
    var isSegundo = turnoStr.includes('2') || turnoStr.includes('segundo') || dados.turno === '2º';

    if (isPrimeiro) {
      context.strokeStyle = '#000000';
      context.lineWidth = 4;
      context.beginPath();
      context.moveTo(COORDENADAS_PAG1.turno1_checkbox.x - 8, COORDENADAS_PAG1.turno1_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG1.turno1_checkbox.x + 8, COORDENADAS_PAG1.turno1_checkbox.y + 8);
      context.moveTo(COORDENADAS_PAG1.turno1_checkbox.x + 8, COORDENADAS_PAG1.turno1_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG1.turno1_checkbox.x - 8, COORDENADAS_PAG1.turno1_checkbox.y + 8);
      context.stroke();
    } else if (isSegundo) {
      context.strokeStyle = '#000000';
      context.lineWidth = 4;
      context.beginPath();
      context.moveTo(COORDENADAS_PAG1.turno2_checkbox.x - 8, COORDENADAS_PAG1.turno2_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG1.turno2_checkbox.x + 8, COORDENADAS_PAG1.turno2_checkbox.y + 8);
      context.moveTo(COORDENADAS_PAG1.turno2_checkbox.x + 8, COORDENADAS_PAG1.turno2_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG1.turno2_checkbox.x - 8, COORDENADAS_PAG1.turno2_checkbox.y + 8);
      context.stroke();
      console.log('DESENHANDO X PAG1 - SEGUNDO em:', COORDENADAS_PAG1.turno2_checkbox);
    }

    context.fillStyle = '#000000';
    context.fillText(dados.titulo || '', COORDENADAS_PAG1.titulo.x, COORDENADAS_PAG1.titulo.y);
    context.fillText(dataNascFormatada, COORDENADAS_PAG1.nasc.x, COORDENADAS_PAG1.nasc.y);
    context.fillText((dados.nome || '').toUpperCase(), COORDENADAS_PAG1.nome.x, COORDENADAS_PAG1.nome.y);

    // PÁGINA 2 (Lado Direito - Requerimento)
    context.fillText(dataDia, COORDENADAS_PAG2.data_dia.x, COORDENADAS_PAG2.data_dia.y);
    context.fillText(dataMes, COORDENADAS_PAG2.data_mes.x, COORDENADAS_PAG2.data_mes.y);
    context.fillText(dataAno, COORDENADAS_PAG2.data_ano.x, COORDENADAS_PAG2.data_ano.y);

    // Marca o turno selecionado na página 2 com X maior (aceita diferentes formatos)
    if (isPrimeiro) {
      context.strokeStyle = '#000000';
      context.lineWidth = 4;
      context.beginPath();
      context.moveTo(COORDENADAS_PAG2.turno1_checkbox.x - 8, COORDENADAS_PAG2.turno1_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG2.turno1_checkbox.x + 8, COORDENADAS_PAG2.turno1_checkbox.y + 8);
      context.moveTo(COORDENADAS_PAG2.turno1_checkbox.x + 8, COORDENADAS_PAG2.turno1_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG2.turno1_checkbox.x - 8, COORDENADAS_PAG2.turno1_checkbox.y + 8);
      context.stroke();
    } else if (isSegundo) {
      context.strokeStyle = '#000000';
      context.lineWidth = 4;
      context.beginPath();
      context.moveTo(COORDENADAS_PAG2.turno2_checkbox.x - 8, COORDENADAS_PAG2.turno2_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG2.turno2_checkbox.x + 8, COORDENADAS_PAG2.turno2_checkbox.y + 8);
      context.moveTo(COORDENADAS_PAG2.turno2_checkbox.x + 8, COORDENADAS_PAG2.turno2_checkbox.y - 8);
      context.lineTo(COORDENADAS_PAG2.turno2_checkbox.x - 8, COORDENADAS_PAG2.turno2_checkbox.y + 8);
      context.stroke();
    }

    context.fillStyle = '#000000';
    context.fillText(dados.titulo || '', COORDENADAS_PAG2.titulo.x, COORDENADAS_PAG2.titulo.y);
    context.fillText(dataNascFormatada, COORDENADAS_PAG2.nasc.x, COORDENADAS_PAG2.nasc.y);
    context.fillText((dados.nome || '').toUpperCase(), COORDENADAS_PAG2.nome.x, COORDENADAS_PAG2.nome.y);

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
