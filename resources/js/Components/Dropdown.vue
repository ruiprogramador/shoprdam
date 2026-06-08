<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
    align: {
        type: String,
        default: 'right',
        validator: (val) => ['left', 'right', 'center'].includes(val),
    },
    width: {
        type: String,
        default: '48',
    },
    contentClasses: {
        type: String,
        default: 'py-1 bg-white',
    },
})

const open = ref(false)

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false
    }
}

onMounted(() => document.addEventListener('keydown', closeOnEscape))
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape))

const widthClass = computed(() => {
    const map = {
        '32': 'w-32',
        '36': 'w-36',
        '40': 'w-40',
        '48': 'w-48',
        '56': 'w-56',
        '64': 'w-64',
        '72': 'w-72',
        '80': 'w-80',
        '96': 'w-96',
        'full': 'w-full',
    }
    return map[props.width] ?? 'w-48'
})

const alignmentClasses = computed(() => {
    if (props.align === 'left') return 'ltr:origin-top-left rtl:origin-top-right start-0'
    if (props.align === 'center') return 'origin-top left-1/2 -translate-x-1/2'
    return 'ltr:origin-top-right rtl:origin-top-left end-0'
})
</script>

<template>
    <div class="relative">
        <div @click="open = !open" :aria-expanded="open" :aria-haspopup="true">
            <slot name="trigger" />
        </div>

        <!-- Overlay -->
        <div
            v-show="open"
            class="fixed inset-0 z-40"
            @click="open = false"
            aria-hidden="true"
        />

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 mt-2 rounded-md shadow-lg"
                :class="[widthClass, alignmentClasses]"
                role="menu"
                @click="open = false"
            >
                <div
                    class="rounded-md ring-1 ring-black ring-opacity-5"
                    :class="contentClasses"
                >
                    <slot name="content" />
                </div>
            </div>
        </Transition>
    </div>
</template>