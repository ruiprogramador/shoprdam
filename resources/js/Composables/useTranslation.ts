import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

type TextVariant = 'short' | 'text' | 'long' | 'html'

interface TranslationItem {
    short:  string | null
    text:   string | null
    long:   string | null
    html:   string | null
    status: 'missing' | 'auto' | 'done'
}

export function useTranslation() {
    const page = usePage()

    const translations = computed<Record<string, TranslationItem>>(
        () => (page.props.translations as Record<string, TranslationItem>) ?? {}
    )

    const locale = computed<string>(
        () => (page.props.locale as string) ?? 'en'
    )

    const resolve = (
        key: string,
        variant: TextVariant,
        replacements?: Record<string, string>
    ): string => {
        const item = translations.value[key]

        if (!item || item.status === 'missing') return key

        const value = item[variant] ?? item.text ?? item.short ?? key

        if (!value) return key

        if (!replacements) return value

        return Object.entries(replacements).reduce(
            (str, [k, v]) => str.replace(new RegExp(`:${k}`, 'g'), v),
            value
        )
    }

    const t  = (key: string, replacements?: Record<string, string>) => resolve(key, 'text',  replacements)
    const ts = (key: string, replacements?: Record<string, string>) => resolve(key, 'short', replacements)
    const tl = (key: string, replacements?: Record<string, string>) => resolve(key, 'long',  replacements)
    const th = (key: string, replacements?: Record<string, string>) => resolve(key, 'html',  replacements)

    return { t, ts, tl, th, locale }
}