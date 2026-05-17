<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'

interface Option {
    [key: string]: any
}

interface Props {
    modelValue: string | number | null | (string | number)[]
    options?: Option[]
    label: string
    value: string
    placeholder?: string
    disabled?: boolean
    multiple?: boolean
    clearable?: boolean
    loading?: boolean
    noResultsText?: string
    searchFn?: (query: string) => Promise<Option[]>
    maxHeight?: string
    id?: string
}

const props = withDefaults(defineProps<Props>(), {
    options: () => [],
    placeholder: 'Pesquisar...',
    disabled: false,
    multiple: false,
    clearable: false,
    loading: false,
    noResultsText: 'Sem resultados',
    maxHeight: '15rem',
    id: '',
})

const emit = defineEmits(['update:modelValue', 'search', 'open', 'close'])

const instanceId = typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID().slice(0, 8)
    : Math.random().toString(36).slice(2, 8)

const root = ref<HTMLElement | null>(null)
const input = ref<HTMLInputElement | null>(null)
const listboxId = `listbox-${instanceId}`

const isOpen = ref(false)
const query = ref('')
const activeIndex = ref(-1)
const isTyping = ref(false)
const asyncOptions = ref<Option[]>([])
const asyncLoading = ref(false)

const isMultiple = computed(() => props.multiple === true)

const selectedValues = computed<(string | number)[]>(() => {
    if (isMultiple.value) {
        return Array.isArray(props.modelValue) ? props.modelValue : []
    }
    return props.modelValue !== null && props.modelValue !== undefined && props.modelValue !== ''
        ? [props.modelValue as string | number]
        : []
})

const safeOptions = computed<Option[]>(() =>
    Array.isArray(props.options) ? props.options : []
)

const selected = computed<Option[]>(() =>
    safeOptions.value.filter(o => selectedValues.value.includes(o[props.value]))
)

const isSelected = (option: Option) =>
    selectedValues.value.includes(option[props.value])

const displayValue = computed(() => {
    if (isTyping.value) return query.value
    if (isMultiple.value) return ''
    return selected.value[0]?.[props.label] ?? ''
})

const computedPlaceholder = computed(() => {
    if (isMultiple.value && selectedValues.value.length > 0) return ''
    return props.placeholder
})

const sourceOptions = computed<Option[]>(() =>
    props.searchFn ? asyncOptions.value : safeOptions.value
)

const filteredOptions = computed<Option[]>(() => {
    const opts = sourceOptions.value ?? []
    if (!isTyping.value || !query.value) return opts
    return opts.filter(o =>
        String(o[props.label] ?? '').toLowerCase().includes(query.value.toLowerCase())
    )
})

let searchTimeout: ReturnType<typeof setTimeout>

watch(query, async (val) => {
    if (!props.searchFn) return
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(async () => {
        asyncLoading.value = true
        try {
            asyncOptions.value = await props.searchFn!(val)
        } finally {
            asyncLoading.value = false
        }
    }, 300)
})

const open = async () => {
    if (props.disabled) return
    isOpen.value = true
    isTyping.value = false

    if (props.searchFn && !asyncOptions.value.length) {
        asyncLoading.value = true
        asyncOptions.value = await props.searchFn('')
        asyncLoading.value = false
    }

    await nextTick()

    const index = filteredOptions.value.findIndex(o =>
        selectedValues.value.includes(o[props.value])
    )
    activeIndex.value = index >= 0 ? index : 0
    scrollToActive()
    emit('open')
}

const close = () => {
    isOpen.value = false
    isTyping.value = false
    query.value = ''
    emit('close')
}

const selectOption = (option: Option) => {
    if (isMultiple.value) {
        const current = [...selectedValues.value]
        const idx = current.indexOf(option[props.value])
        if (idx >= 0) {
            current.splice(idx, 1)
        } else {
            current.push(option[props.value])
        }
        emit('update:modelValue', current)
        query.value = ''
        input.value?.focus()
    } else {
        emit('update:modelValue', option[props.value])
        close()
    }
}

const removeTag = (val: string | number) => {
    if (!isMultiple.value) return
    emit('update:modelValue', selectedValues.value.filter(v => v !== val))
}

const clear = (e: Event) => {
    e.stopPropagation()
    emit('update:modelValue', isMultiple.value ? [] : null)
    query.value = ''
}

const onInput = (e: Event) => {
    query.value = (e.target as HTMLInputElement).value
    isTyping.value = true
    isOpen.value = true
    activeIndex.value = 0
    emit('search', query.value)
}

const onKeydown = (e: KeyboardEvent) => {
    if (!isOpen.value && (e.key === 'ArrowDown' || e.key === 'Enter')) {
        open(); return
    }
    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault()
            activeIndex.value = Math.min(activeIndex.value + 1, filteredOptions.value.length - 1)
            scrollToActive()
            break
        case 'ArrowUp':
            e.preventDefault()
            activeIndex.value = Math.max(activeIndex.value - 1, 0)
            scrollToActive()
            break
        case 'Enter':
            e.preventDefault()
            if (filteredOptions.value[activeIndex.value]) {
                selectOption(filteredOptions.value[activeIndex.value])
            }
            break
        case 'Escape':
            close()
            break
        case 'Backspace':
            if (isMultiple.value && !query.value && selectedValues.value.length) {
                removeTag(selectedValues.value[selectedValues.value.length - 1])
            }
            break
    }
}

const scrollToActive = () => {
    nextTick(() => {
        document.getElementById(`opt-${instanceId}-${activeIndex.value}`)
            ?.scrollIntoView({ block: 'nearest' })
    })
}

const handleClickOutside = (e: MouseEvent) => {
    if (root.value && !root.value.contains(e.target as Node)) close()
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside)
    clearTimeout(searchTimeout)
})
</script>

<template>
<div ref="root" class="relative w-full">

    <!-- Trigger box -->
    <div
        class="min-h-[2.5rem] w-full flex flex-wrap items-center gap-1
               border rounded-md px-2 py-1 bg-white cursor-text
               focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-indigo-500
               transition"
        :class="[
            disabled ? 'opacity-60 cursor-not-allowed bg-gray-50' : '',
            isOpen ? 'border-indigo-500 ring-2 ring-indigo-500' : 'border-gray-300',
        ]"
        :aria-expanded="isOpen"
        :aria-haspopup="'listbox'"
        :aria-controls="listboxId"
        @click="() => { if (!disabled) { open(); nextTick(() => input?.focus()) } }"
    >
        <!-- Tags (multiple) -->
        <template v-if="isMultiple">
            <span
                v-for="sel in selected"
                :key="sel[value]"
                class="inline-flex items-center gap-1 bg-indigo-100 text-indigo-800
                       text-xs font-medium px-2 py-0.5 rounded-full max-w-[120px] sm:max-w-none"
            >
                <slot name="tag" :option="sel">
                    <span class="truncate">{{ sel[label] }}</span>
                </slot>
                <button
                    type="button"
                    @click.stop="removeTag(sel[value])"
                    class="hover:text-indigo-600 leading-none flex-shrink-0"
                    :disabled="disabled"
                    :aria-label="`Remover ${sel[label]}`"
                >×</button>
            </span>
        </template>

        <!-- Input -->
        <input
            ref="input"
            :id="id"
            type="text"
            :value="displayValue"
            :placeholder="computedPlaceholder"
            :disabled="disabled"
            :aria-autocomplete="'list'"
            :aria-activedescendant="activeIndex >= 0 ? `opt-${instanceId}-${activeIndex}` : undefined"
            class="flex-1 min-w-[4rem] outline-none text-sm bg-transparent py-0.5"
            @focus="open"
            @input="onInput"
            @keydown="onKeydown"
        />

        <!-- Loading spinner -->
        <svg
            v-if="loading || asyncLoading"
            class="animate-spin h-4 w-4 text-gray-400 shrink-0"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>

        <!-- Clear button -->
        <button
            v-else-if="clearable && selectedValues.length"
            type="button"
            @click="clear"
            class="text-gray-400 hover:text-gray-600 shrink-0 text-lg leading-none"
            aria-label="Limpar seleção"
        >×</button>

        <!-- Chevron -->
        <svg
            v-else
            class="h-4 w-4 text-gray-400 shrink-0 transition-transform"
            :class="isOpen ? 'rotate-180' : ''"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
        </svg>
    </div>

    <!-- Dropdown list -->
    <ul
        v-if="isOpen"
        :id="listboxId"
        class="absolute z-50 w-full bg-white border border-gray-200 mt-1 rounded-md shadow-lg overflow-auto"
        :style="{ maxHeight: maxHeight }"
        role="listbox"
        :aria-multiselectable="isMultiple"
    >
        <li
            v-for="(option, index) in filteredOptions"
            :key="option[value]"
            :id="`opt-${instanceId}-${index}`"
            @click="selectOption(option)"
            class="px-3 py-2 cursor-pointer flex items-center gap-2 text-sm transition-colors"
            :class="[
                index === activeIndex ? 'bg-indigo-50' : 'hover:bg-gray-50',
                isSelected(option) ? 'font-semibold text-indigo-700' : 'text-gray-700',
            ]"
            role="option"
            :aria-selected="isSelected(option)"
        >
            <span v-if="isMultiple" class="w-4 shrink-0">
                <svg v-if="isSelected(option)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-indigo-600" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/>
                </svg>
            </span>

            <slot name="option" :option="option">
                {{ option[label] }}
            </slot>
        </li>

        <li
            v-if="!filteredOptions.length && !asyncLoading"
            class="px-3 py-4 text-gray-400 text-sm text-center"
            role="option"
            aria-disabled="true"
        >
            {{ noResultsText }}
        </li>

        <li v-if="asyncLoading" class="px-3 py-4 text-gray-400 text-sm text-center" role="option" aria-disabled="true">
            <span class="inline-flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                A carregar...
            </span>
        </li>
    </ul>

</div>
</template>