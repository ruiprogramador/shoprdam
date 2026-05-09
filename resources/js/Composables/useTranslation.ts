import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

type TextVariant = 'short' | 'text' | 'long'

interface TranslationItem {
    short: string | null
    text: string | null
    long: string | null
}

/**
 * Composable for accessing translations in Vue components.
 *
 * Usage:
 *   const { t, ts, tl } = useTranslation()
 *
 *   t('kyc.submitted')          // returns text variant
 *   ts('kyc.submitted')         // returns text_short variant
 *   tl('kyc.not_found')         // returns text_long variant
 *   t('common.select')          // "Select" or "Selecionar" based on locale
 */
export function useTranslation() {
    const page = usePage()

    /* const translations = computed(() =>
        (page.props.translations as Record<string, Record<string, TranslationItem>>) ?? {}
    ) */

    const translations = computed(() =>
        (page.props.sharedTranslations as Record<string, Record<string, TranslationItem>>) ?? {}
    )

    const locale = computed(() =>
        (page.props.locale as string) ?? 'en'
    )

    const resolve = (key: string, variant: TextVariant, replacements?: Record<string, string>): string => {
        const [group, ...rest] = key.split('.')
        const itemKey = rest.join('.')

        const item = translations.value[group]?.[itemKey]
        console.log(translations)

        if (!item) return key // fallback to key if not found

        const value = item[variant] ?? item.text ?? item.short ?? key

        if (!replacements) return value

        // Replace :placeholder with values
        return Object.entries(replacements).reduce(
            (str, [k, v]) => str.replace(new RegExp(`:${k}`, 'g'), v),
            value
        )
    }

    /** text variant — default for messages, toasts, labels */
    const t = (key: string, replacements?: Record<string, string>) =>
        resolve(key, 'text', replacements)

    /** text_short variant — for badges, pills, table cells */
    const ts = (key: string, replacements?: Record<string, string>) =>
        resolve(key, 'short', replacements)

    /** text_long variant — for descriptions, warnings */
    const tl = (key: string, replacements?: Record<string, string>) =>
        resolve(key, 'long', replacements)

    return { t, ts, tl, locale }
}