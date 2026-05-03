export function useKycStatus() {
    const statusColor = (slug: string | undefined): string => {
        const colors: Record<string, string> = {
            pending:  'bg-yellow-100 text-yellow-800',
            approved: 'bg-green-100 text-green-800',
            rejected: 'bg-red-100 text-red-800',
            expired:  'bg-gray-100 text-gray-800',
        }
        return colors[slug ?? ''] ?? 'bg-gray-100 text-gray-800'
    }

    const statusLabel = (slug: string | undefined): string => {
        const labels: Record<string, string> = {
            pending:  'Pendente',
            approved: 'Aprovado',
            rejected: 'Rejeitado',
            expired:  'Expirado',
        }
        return labels[slug ?? ''] ?? slug ?? '—'
    }

    return { statusColor, statusLabel }
}