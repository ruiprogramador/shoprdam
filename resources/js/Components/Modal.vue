<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    fullscreenMobile: {
        type: Boolean,
        default: true,
    },
})

const emit = defineEmits(['close'])
const dialog = ref()
const showSlot = ref(props.show)

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden'
            showSlot.value = true
            dialog.value?.showModal()
        } else {
            document.body.style.overflow = ''
            setTimeout(() => {
                dialog.value?.close()
                showSlot.value = false
            }, 200)
        }
    }
)

const close = () => {
    if (props.closeable) emit('close')
}

const closeOnEscape = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault()
        if (props.show) close()
    }
}

onMounted(() => document.addEventListener('keydown', closeOnEscape))
onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape)
    document.body.style.overflow = ''
})

const maxWidthClass = computed(() => {
    const map = {
        xs:  'sm:max-w-xs',
        sm:  'sm:max-w-sm',
        md:  'sm:max-w-md',
        lg:  'sm:max-w-lg',
        xl:  'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
        '5xl': 'sm:max-w-5xl',
        full: 'sm:max-w-full',
    }
    return map[props.maxWidth] ?? 'sm:max-w-2xl'
})
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
    >
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center px-0 sm:px-4 py-0 sm:py-6">

            <!-- Backdrop -->
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-all"
                    @click="close"
                    aria-hidden="true"
                />
            </Transition>

            <!-- Panel -->
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div
                    v-show="show"
                    class="relative z-10 w-full bg-white shadow-xl transition-all
                           rounded-t-2xl sm:rounded-lg
                           max-h-[92dvh] sm:max-h-[90vh]
                           flex flex-col
                           pb-[env(safe-area-inset-bottom)]"
                    :class="[
                        maxWidthClass,
                        fullscreenMobile ? 'sm:w-full' : 'mx-4 sm:mx-auto rounded-2xl'
                    ]"
                    role="dialog"
                    aria-modal="true"
                >
                    <!-- Drag handle (mobile) -->
                    <div class="flex justify-center pt-3 pb-1 sm:hidden flex-shrink-0">
                        <div class="w-10 h-1 rounded-full bg-gray-300" />
                    </div>

                    <!-- Scrollable content -->
                    <div class="overflow-y-auto flex-1 overscroll-contain">
                        <slot v-if="showSlot" />
                    </div>
                </div>
            </Transition>
        </div>
    </dialog>
</template>