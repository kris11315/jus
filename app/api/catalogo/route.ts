import { NextRequest, NextResponse } from 'next/server'

const catalogo = {
  front: { nome: 'Pilates em Casa', centavos: 6892, step: 2 },
  back: { nome: 'Pilates em Casa (50% OFF)', centavos: 3446, step: 2 },
  up1: { nome: 'Guia de Receitas Saudáveis', centavos: 4392, step: 4 },
  up2: { nome: 'Ebook Doces Fit', centavos: 3840, step: 6 },
  up3: { nome: 'Metódo Resultados Acelerados', centavos: 4560, step: 8 },
  up4: { nome: 'Grupo VIP', centavos: 6743, step: 10 },
  reg: { nome: '10 Exercicios diários', centavos: 7825, step: 0 },
} as const

export function GET(request: NextRequest) {
  const etapa = request.nextUrl.searchParams.get('etapa')?.trim().toLowerCase()
  const item = etapa ? catalogo[etapa as keyof typeof catalogo] : undefined

  if (!item || !etapa) {
    return NextResponse.json(
      { success: false, error: etapa ? 'Etapa desconhecida' : 'Etapa não informada' },
      { status: 400, headers: { 'Cache-Control': 'no-store' } },
    )
  }

  return NextResponse.json(
    {
      success: true,
      etapa,
      nome: item.nome,
      centavos: item.centavos,
      valor: (item.centavos / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
      teste: false,
    },
    { headers: { 'Cache-Control': 'no-store' } },
  )
}
