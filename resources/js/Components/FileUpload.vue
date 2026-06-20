<script setup>
import { ref, watch, computed } from 'vue'

const props = defineProps({
    src: {
        type: [String, File, Array, null],
        default: null,
    },
    name: {
        type: String,
        default: 'file',
    },
    labelIdle: {
        type: String,
        default: 'Drag & Drop your file or <span class="filepond--label-action">Browse</span>',
    },
    acceptedFileTypes: {
        type: String,
        default: null,
    },
    maxFileSize: {
        type: String,
        default: '10MB',
    },
    allowMultiple: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:src'])
const filesList = ref([])
const isReady = ref(false)
const uploadError = ref(null)

const normalizedSrc = computed(() => {
    if (!props.src) return []
    return Array.isArray(props.src) ? props.src : [props.src]
})

const urlToFile = async (url) => {
    try {
        const response = await fetch(url)
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const blob = await response.blob()
        const filename = url.split('/').pop() || 'file'
        return new File([blob], filename, { type: blob.type })
    } catch (e) {
        uploadError.value = `Não foi possível carregar o ficheiro: ${e.message}`
        return null
    }
}

const initializeFiles = async () => {
    isReady.value = false
    uploadError.value = null

    const items = await Promise.all(
        normalizedSrc.value.map(async (item) => {
            if (typeof item === 'string') return await urlToFile(item)
            return item
        })
    )

    filesList.value = items.filter(Boolean)
    isReady.value = true
}

watch(
    () => props.src,
    (newVal) => {
        if (newVal === null || typeof newVal === 'string') {
            initializeFiles()
        }
    },
    { immediate: true }
)

const handleFilesUpdate = (fileItems) => {
    console.log('handleFilesUpdate fired:', fileItems)
    
    const files = fileItems.map((item) => item.file).filter(Boolean)
    console.log('files after map:', files)
    
    if (!files.length) {
        emit('update:src', null)
        return
    }
    emit('update:src', props.allowMultiple ? files : files[0])
}
</script>

<template>
    <div class="file-upload-wrapper w-full">
        <p v-if="uploadError" class="text-sm text-red-600 mb-2" role="alert">
            {{ uploadError }}
        </p>
        <file-pond
            v-if="isReady"
            :name="name"
            :label-idle="labelIdle"
            v-bind="acceptedFileTypes ? { 'accepted-file-types': acceptedFileTypes } : {}"
            :files="filesList"
            :max-file-size="maxFileSize"
            :allow-multiple="allowMultiple"
            @updatefiles="handleFilesUpdate"
        />
        <div v-else class="flex items-center justify-center p-6 border-2 border-dashed border-gray-300 rounded-md text-sm text-gray-400">
            <svg class="animate-spin h-5 w-5 mr-2 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
            </svg>
            A carregar...
        </div>
    </div>
</template>