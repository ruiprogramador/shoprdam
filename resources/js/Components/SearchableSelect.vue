<script setup>
import { ref, computed, watch } from 'vue'
import { onClickOutside } from '@vueuse/core'

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: ''
    },
    options: {
        type: Array,
        required: true,
        // Expected format: [{ value: 'id', label: 'Name' }]
    },
    placeholder: {
        type: String,
        default: 'Select an option...'
    },
    searchPlaceholder: {
        type: String,
        default: 'Search...'
    }
})

const emit = defineEmits(['update:modelValue'])

const isOpen = ref(false)
const searchQuery = ref('')
const dropdownRef = ref(null)

// Close dropdown when clicking outside
onClickOutside(dropdownRef, () => {
    isOpen.value = false
    searchQuery.value = ''
})

// Filter options based on search
const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options

    const query = searchQuery.value.toLowerCase()
    return props.options.filter(option =>
        option.label.toLowerCase().includes(query)
    )
})

// Get selected option label
const selectedLabel = computed(() => {
    const selected = props.options.find(opt => opt.value === props.modelValue)
    return selected?.label || props.placeholder
})

// Check if has selection
const hasSelection = computed(() => {
    return props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined
})

const selectOption = (value) => {
    emit('update:modelValue', value)
    isOpen.value = false
    searchQuery.value = ''
}

const clearSelection = () => {
    emit('update:modelValue', '')
    isOpen.value = false
    searchQuery.value = ''
}

const toggleDropdown = () => {
    isOpen.value = !isOpen.value
    if (isOpen.value) {
        searchQuery.value = ''
        // Focus search input after dropdown opens
        setTimeout(() => {
            dropdownRef.value?.querySelector('input')?.focus()
        }, 50)
    }
}

// Watch for value changes to close dropdown
watch(() => props.modelValue, () => {
    if (isOpen.value) {
        isOpen.value = false
        searchQuery.value = ''
    }
})
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <!-- Trigger Button -->
        <button
            type="button"
            @click="toggleDropdown"
            class="relative w-full bg-white border-2 border-gray-300 rounded-lg shadow-sm pl-4 pr-10 py-3 text-left cursor-pointer focus:outline-none focus:ring-4 focus:ring-blue-500/20 focus:border-blue-500 transition-all duration-200 hover:border-gray-400"
            :class="{ 'border-blue-500 ring-4 ring-blue-500/20': isOpen }"
        >
            <span
                class="block truncate text-sm"
                :class="hasSelection ? 'text-gray-900 font-medium' : 'text-gray-500'"
            >
                {{ selectedLabel }}
            </span>
            <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                <i
                    :class="isOpen ? 'fa fa-chevron-up' : 'fa fa-chevron-down'"
                    class="text-gray-400 text-xs transition-transform duration-200"
                ></i>
            </span>
        </button>

        <!-- Dropdown Panel -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <div
                v-show="isOpen"
                class="absolute z-50 mt-2 w-full bg-white shadow-xl rounded-lg border-2 border-gray-200 overflow-hidden"
            >
                <!-- Search Input -->
                <div class="p-3 border-b-2 border-gray-100 bg-gray-50">
                    <div class="relative">
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="searchPlaceholder"
                            class="w-full pl-9 pr-3 py-2 border-2 border-gray-300 rounded-lg text-sm focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200"
                            @click.stop
                        />
                        <i class="fa fa-search absolute left-3 top-3 text-gray-400 text-xs"></i>
                    </div>
                </div>

                <!-- Options List -->
                <ul class="max-h-60 overflow-y-auto py-1">
                    <li
                        v-for="option in filteredOptions"
                        :key="option.value"
                        @click="selectOption(option.value)"
                        class="relative cursor-pointer select-none py-3 pl-4 pr-10 text-sm hover:bg-blue-50 transition-colors"
                        :class="{
                            'bg-blue-50 text-blue-900 font-semibold': option.value === modelValue,
                            'text-gray-900': option.value !== modelValue
                        }"
                    >
                        <span class="block truncate">{{ option.label }}</span>
                        <span
                            v-if="option.value === modelValue"
                            class="absolute inset-y-0 right-0 flex items-center pr-4 text-blue-600"
                        >
                            <i class="fa fa-check text-sm"></i>
                        </span>
                    </li>

                    <!-- No Results -->
                    <li
                        v-if="filteredOptions.length === 0"
                        class="relative cursor-default select-none py-6 px-4 text-sm text-gray-500 text-center"
                    >
                        <i class="fa fa-search text-gray-300 text-2xl mb-2"></i>
                        <p class="font-medium">No results found</p>
                        <p class="text-xs mt-1">Try adjusting your search</p>
                    </li>
                </ul>

                <!-- Clear Button (if has selection) -->
                <div
                    v-if="hasSelection && options[0]?.value === ''"
                    class="border-t-2 border-gray-100 p-2 bg-gray-50"
                >
                    <button
                        @click.stop="clearSelection"
                        class="w-full px-3 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-white hover:bg-gray-100 border-2 border-gray-300 rounded-lg transition-colors"
                    >
                        <i class="fa fa-times mr-2"></i>
                        Clear Selection
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>