export function useFormatDate() {
    const formatDate = (date: string | null | undefined): string => {
        if (!date) return '—'
        return new Date(date).toLocaleDateString('pt-PT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        })
    }

    const formatDateTime = (date: string | null | undefined): string => {
        if (!date) return '—'
        return new Date(date).toLocaleString('pt-PT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    return { formatDate, formatDateTime }
}