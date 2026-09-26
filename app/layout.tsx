import type { Metadata } from 'next'
import type { ReactNode } from 'react'

export const metadata: Metadata = {
  title: 'Jus — Portal de atendimento',
  description: 'Portal de atendimento e regularização.',
  robots: { index: false, follow: false },
}

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="pt-BR" className="bg-white">
      <body>{children}</body>
    </html>
  )
}
