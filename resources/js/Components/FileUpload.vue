<script setup>
import { ref, watch, computed } from 'vue'

const props = defineProps({
    src: {
        type: [String, File, Array, null],
        default: null
    },
    name: {
        type: String,
        default: 'file'  // changed from 'image'
    },
    labelIdle: {
        type: String,
        default: 'Drag & Drop your file or <span class="filepond--label-action">Browse</span>'  // changed from 'image'
    },
    acceptedFileTypes: {
        type: String,
        default: null  // null = accept any file type
    },
    maxFileSize: {
        type: String,
        default: '10MB'  // increased from 5MB for generic files
    },
    allowMultiple: {
        type: Boolean,
        default: false
    }
})

const emit = defineEmits(['update:src'])
const filesList = ref([])
const isReady = ref(false)

const normalizedSrc = computed(() => {
    if (!props.src) return []
    return Array.isArray(props.src) ? props.src : [props.src]
})

const urlToFile = async (url) => {
    try {
        const response = await fetch(url)
        const blob = await response.blob()
        const filename = url.split('/').pop() || 'file'  // changed from 'image.jpg'
        return new File([blob], filename, { type: blob.type })
    } catch (e) {
        console.error('FileUpload: Failed to fetch file URL:', url, e)
        return null
    }
}

const initializeFiles = async () => {
    isReady.value = false

    const items = await Promise.all(
        normalizedSrc.value.map(async (item) => {
            if (typeof item === 'string') {
                return await urlToFile(item)
            }
            return item // already a File object
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
    const files = fileItems.map(item => item.file).filter(Boolean)

    if (!files.length) {
        emit('update:src', null)
        return
    }

    emit('update:src', props.allowMultiple ? files : files[0])
}
</script>

<template>
    <div class="file-upload-wrapper">
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
        <div v-else class="filepond-loading">
            Loading...
        </div>
    </div>
</template>

<style>
.file-upload-wrapper {
    width: 100%;
}
.filepond-loading {
    padding: 1rem;
    text-align: center;
    color: #999;
    border: 2px dashed #ddd;
    border-radius: 4px;
}
</style>
