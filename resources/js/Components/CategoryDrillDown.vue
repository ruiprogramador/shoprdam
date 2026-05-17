<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

// ─── Props ────────────────────────────────────────────────────
const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    // Estrutura esperada do backend:
    // [
    //   {
    //     id: 1, slug: 'sports', name: 'Sports',
    //     children: [
    //       { id: 10, slug: 'padel', name: 'Padel',
    //         children: [
    //           { id: 100, slug: 'padel-rackets', name: 'Rackets', children: [] }
    //         ]
    //       }
    //     ]
    //   }
    // ]
    categories: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'All Categories' },
})

const emit = defineEmits(['update:modelValue'])

// ─── Mock data (substituir por props.categories do backend) ───
const mockCategories = [
    {
        id: 1, slug: 'sports', name: 'Sports', children: [
            {
                id: 10, slug: 'padel', name: 'Padel', children: [
                    { id: 100, slug: 'padel-rackets',  name: 'Rackets',   children: [] },
                    { id: 101, slug: 'padel-balls',    name: 'Balls',     children: [] },
                    { id: 102, slug: 'padel-bags',     name: 'Bags',      children: [] },
                ]
            },
            {
                id: 11, slug: 'football', name: 'Football', children: [
                    { id: 110, slug: 'football-boots', name: 'Boots',     children: [] },
                    { id: 111, slug: 'football-balls', name: 'Balls',     children: [] },
                ]
            },
            {
                id: 12, slug: 'tennis', name: 'Tennis', children: [
                    { id: 120, slug: 'tennis-rackets', name: 'Rackets',   children: [] },
                    { id: 121, slug: 'tennis-balls',   name: 'Balls',     children: [] },
                ]
            },
        ]
    },
    {
        id: 2, slug: 'electronics', name: 'Electronics', children: [
            {
                id: 20, slug: 'phones', name: 'Phones', children: [
                    { id: 200, slug: 'smartphones',    name: 'Smartphones', children: [] },
                    { id: 201, slug: 'accessories',    name: 'Accessories', children: [] },
                ]
            },
            { id: 21, slug: 'laptops',  name: 'Laptops',  children: [] },
            { id: 22, slug: 'tablets',  name: 'Tablets',  children: [] },
        ]
    },
    {
        id: 3, slug: 'fashion', name: 'Fashion', children: [
            { id: 30, slug: 'men',   name: 'Men',   children: [] },
            { id: 31, slug: 'women', name: 'Women', children: [] },
            { id: 32, slug: 'kids',  name: 'Kids',  children: [] },
        ]
    },
    { id: 4, slug: 'home',   name: 'Home & Garden', children: [] },
    { id: 5, slug: 'beauty', name: 'Beauty',        children: [] },
]

// ─── State ────────────────────────────────────────────────────
const source        = computed(() => props.categories.length ? props.categories : mockCategories)
const isOpen        = ref(false)
const searchQuery   = ref('')
const trail         = ref([])   // breadcrumb de navegação
const selectedLabel = ref(props.placeholder)
const root          = ref(null)

// ─── Computed ─────────────────────────────────────────────────

// Nó atual baseado no trail
const currentNode = computed(() => {
    if (!trail.value.length) return null
    let node = source.value.find(c => c.id === trail.value[0])
    for (let i = 1; i < trail.value.length; i++) {
        node = node?.children?.find(c => c.id === trail.value[i])
    }
    return node
})

// Items a mostrar (nível atual ou raiz)
const currentItems = computed(() =>
    currentNode.value ? currentNode.value.children : source.value
)

// Flatten recursivo para pesquisa
const flatAll = (items, ancestors = []) => {
    return items.flatMap(item => {
        const path = [...ancestors, item.name]
        return [
            { ...item, path },
            ...flatAll(item.children ?? [], path)
        ]
    })
}
const allFlat = computed(() => flatAll(source.value))

// Resultados de pesquisa
const searchResults = computed(() => {
    if (!searchQuery.value.trim()) return []
    const q = searchQuery.value.toLowerCase()
    return allFlat.value.filter(item =>
        item.name.toLowerCase().includes(q) ||
        item.path.some(p => p.toLowerCase().includes(q))
    ).slice(0, 12)
})

const isSearching = computed(() => searchQuery.value.trim().length > 0)

// ─── Methods ──────────────────────────────────────────────────
const open = () => {
    isOpen.value   = true
    searchQuery.value = ''
    trail.value    = []
}

const close = () => {
    isOpen.value   = false
    searchQuery.value = ''
}

const drillInto = (item) => {
    if (item.children?.length) {
        trail.value = [...trail.value, item.id]
    } else {
        select(item)
    }
}

const drillBack = () => {
    trail.value = trail.value.slice(0, -1)
}

const select = (item) => {
    selectedLabel.value = item.path ? item.path.join(' › ') : item.name
    emit('update:modelValue', item.slug)
    close()
}

const clearSelection = () => {
    selectedLabel.value = props.placeholder
    emit('update:modelValue', null)
}

// Breadcrumb label
const breadcrumb = computed(() => {
    const parts = []
    let items = source.value
    for (const id of trail.value) {
        const found = items.find(c => c.id === id)
        if (found) { parts.push(found.name); items = found.children ?? [] }
    }
    return parts
})

// Click outside
const onClickOutside = (e) => {
    if (root.value && !root.value.contains(e.target)) close()
}
onMounted(()       => document.addEventListener('click', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))
</script>

<template>
<div ref="root" class="cd-root">

    <!-- Trigger -->
    <button
        type="button"
        class="cd-trigger"
        :class="{ 'cd-trigger--open': isOpen }"
        @click="isOpen ? close() : open()"
    >
        <span class="cd-trigger__label">{{ selectedLabel }}</span>
        <span v-if="modelValue" class="cd-trigger__clear" @click.stop="clearSelection">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
                <path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </span>
        <svg class="cd-trigger__chevron" :class="{ 'cd-trigger__chevron--open': isOpen }"
             width="16" height="16" viewBox="0 0 16 16" fill="none">
            <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
    </button>

    <!-- Panel -->
    <Transition name="cd-panel">
        <div v-if="isOpen" class="cd-panel" @click.stop>

            <!-- Search -->
            <div class="cd-search">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" class="cd-search__icon">
                    <circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M9.5 9.5L13 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <input
                    v-model="searchQuery"
                    type="text"
                    class="cd-search__input"
                    placeholder="Search categories..."
                    @click.stop
                />
                <button v-if="searchQuery" type="button" class="cd-search__clear" @click="searchQuery = ''">×</button>
            </div>

            <!-- Search results -->
            <template v-if="isSearching">
                <div class="cd-list">
                    <button
                        v-for="item in searchResults"
                        :key="item.id"
                        type="button"
                        class="cd-item cd-item--result"
                        @click="select(item)"
                    >
                        <span class="cd-item__name">{{ item.name }}</span>
                        <span class="cd-item__path">{{ item.path.slice(0, -1).join(' › ') }}</span>
                    </button>
                    <div v-if="!searchResults.length" class="cd-empty">
                        No categories found for "{{ searchQuery }}"
                    </div>
                </div>
            </template>

            <!-- Drill-down navigation -->
            <template v-else>
                <!-- Breadcrumb -->
                <div v-if="trail.length" class="cd-breadcrumb">
                    <button type="button" class="cd-breadcrumb__back" @click="drillBack">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                            <path d="M9 2L3 7l6 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <span class="cd-breadcrumb__trail">
                        <span
                            v-for="(crumb, i) in breadcrumb"
                            :key="i"
                        >
                            <span v-if="i > 0" class="cd-breadcrumb__sep">›</span>
                            {{ crumb }}
                        </span>
                    </span>
                </div>

                <!-- "All in X" option when drilled in -->
                <button
                    v-if="currentNode"
                    type="button"
                    class="cd-item cd-item--all"
                    @click="select({ ...currentNode, path: breadcrumb })"
                >
                    All in {{ currentNode.name }}
                </button>

                <!-- All Categories at root -->
                <button
                    v-if="!trail.length"
                    type="button"
                    class="cd-item cd-item--all"
                    @click="clearSelection(); close()"
                >
                    All Categories
                </button>

                <!-- Items -->
                <div class="cd-list">
                    <button
                        v-for="item in currentItems"
                        :key="item.id"
                        type="button"
                        class="cd-item"
                        :class="{ 'cd-item--has-children': item.children?.length }"
                        @click="drillInto(item)"
                    >
                        <span class="cd-item__name">{{ item.name }}</span>
                        <svg
                            v-if="item.children?.length"
                            class="cd-item__arrow"
                            width="14" height="14" viewBox="0 0 14 14" fill="none"
                        >
                            <path d="M5 2l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </template>

        </div>
    </Transition>
</div>
</template>