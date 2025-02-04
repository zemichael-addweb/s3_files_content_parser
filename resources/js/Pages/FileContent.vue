<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const currentFile = ref('Waiting to start');
const processedCount = ref(0);
const totalFiles = ref(0);
const currentStatus = ref('Ready');
const error = ref('');
const processingLog = ref([]);
const filesList = ref({});
const processedFiles = ref(new Set());
const failedFiles = ref(new Set());
const successCount = ref(0);
let isProcessing = false;
const isPaused = ref(false);

// Computed property to check if we can resume
const canResume = computed(() => {
    const storedFiles = localStorage.getItem('filesList');
    const storedProcessed = localStorage.getItem('processedFiles');
    if (!storedFiles) return false;

    const remainingCount =
        storedProcessed && storedProcessed.length > 0
            ? Object.keys(JSON.parse(storedFiles)).length -
              JSON.parse(storedProcessed).length
            : Object.keys(JSON.parse(storedFiles)).length;
    return remainingCount > 0;
});

// Computed property for remaining files count
const remainingFiles = computed(() => {
    if (!canResume.value) return 0;
    const storedFiles = JSON.parse(localStorage.getItem('filesList'));
    const storedProcessed = JSON.parse(localStorage.getItem('processedFiles'));
    return storedProcessed && storedProcessed.length > 0
        ? Object.keys(storedFiles).length - storedProcessed.length
        : Object.keys(storedFiles).length;
});

// Load stored data on mount
onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);

    const storedFiles = localStorage.getItem('filesList');
    const storedProcessed = localStorage.getItem('processedFiles');
    const storedFailed = localStorage.getItem('failedFiles');

    if (storedFiles) {
        filesList.value = JSON.parse(storedFiles);
    }
    if (storedProcessed) {
        processedFiles.value = new Set(JSON.parse(storedProcessed));
    }
    if (storedFailed) {
        failedFiles.value = new Set(JSON.parse(storedFailed));
    }
    successCount.value = processedFiles.value.size;
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
});

const handleBeforeUnload = (e) => {
    if (isProcessing) {
        e.preventDefault();
        e.returnValue = '';
        return '';
    }
};

const saveProgress = () => {
    localStorage.setItem('filesList', JSON.stringify(filesList.value));
    localStorage.setItem(
        'processedFiles',
        JSON.stringify([...processedFiles.value]),
    );
    localStorage.setItem('failedFiles', JSON.stringify([...failedFiles.value]));
};

const processFile = async (filePath) => {
    if (isPaused.value) {
        currentStatus.value = 'Paused';
        return false;
    }
    if (processedFiles.value.has(filePath)) {
        processingLog.value.push({
            file: filePath,
            status: 'already processed',
            message: 'File was already processed',
        });
        return true;
    }

    try {
        // Group file types by endpoint
        const FILE_TYPE_GROUPS = {
            text: {
                extensions: ['txt'],
                endpoint: '/api/file-content/process-txt',
            },
            document: {
                extensions: ['doc', 'docx'],
                endpoint: '/api/file-content/process-doc',
            },
            pdf: {
                extensions: ['pdf'],
                endpoint: '/api/file-content/process-pdf',
            },
            media: {
                extensions: [
                    'mp4',
                    'mov',
                    'avi',
                    'mkv',
                    'webm',
                    'm4v',
                    'wmv',
                    'flv',
                    'mp3',
                    'wav',
                    'm4a',
                    'ogg',
                    'aac',
                    'wma',
                    'm4b',
                    'm4p',
                    'm4r',
                ],
                endpoint: '/api/file-content/process-media',
            },
        };

        const extension = filePath.split('.').pop().toLowerCase();

        // Find matching file type group
        const matchingGroup = Object.values(FILE_TYPE_GROUPS).find((group) =>
            group.extensions.includes(extension),
        );

        if (!matchingGroup) {
            throw new Error('Unsupported file type');
        }

        const endpoint = matchingGroup.endpoint;

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ s3_file_path: filePath }),
        });
        const result = await response.json();

        if (result.success) {
            processedFiles.value.add(filePath);
            failedFiles.value.delete(filePath);
            successCount.value++;
            saveProgress();
            processingLog.value.push({
                file: filePath,
                status: 'success',
                message: 'Successfully processed',
            });
            return true;
        } else {
            failedFiles.value.add(filePath);
            saveProgress();
            processingLog.value.push({
                file: filePath,
                status: 'error',
                message: result.error || 'Failed to process',
            });
            return false;
        }
    } catch (err) {
        failedFiles.value.add(filePath);
        saveProgress();
        processingLog.value.push({
            file: filePath,
            status: 'error',
            message: err.message,
        });
        return false;
    }
};

const startProcessing = async () => {
    if (isProcessing) return;

    // Clear stored data
    localStorage.removeItem('filesList');
    localStorage.removeItem('processedFiles');
    localStorage.removeItem('failedFiles');
    filesList.value = {};
    processedFiles.value = new Set();
    failedFiles.value = new Set();
    successCount.value = 0;

    isProcessing = true;
    currentStatus.value = 'Processing';
    error.value = '';
    processingLog.value = [];
    processedCount.value = 0;
    totalFiles.value = 0;

    try {
        const response = await fetch('/api/file-content/get-files');
        const data = await response.json();

        if (data.success) {
            filesList.value = data.files;
            localStorage.setItem('filesList', JSON.stringify(data.files));

            totalFiles.value = Object.keys(data.files).length;

            for (const [id, filePath] of Object.entries(data.files)) {
                console.info(`Processing file [${id}] at "${filepath}"`);
                currentFile.value = filePath;
                const success = await processFile(filePath);
                if (success) processedCount.value++;
            }

            currentStatus.value = 'Complete';
            currentFile.value = 'Processing complete';
        } else {
            error.value = data.message;
            currentStatus.value = 'Error';
        }
    } catch (err) {
        error.value = err.message;
        currentStatus.value = 'Error';
    } finally {
        isProcessing = false;
    }
};

const resumeProcessing = async () => {
    if (isProcessing) return;

    isProcessing = true;
    currentStatus.value = 'Processing';
    error.value = '';

    try {
        totalFiles.value = Object.keys(filesList.value).length;
        processedCount.value = processedFiles.value.size;

        for (const [id, filePath] of Object.entries(filesList.value)) {
            console.info(
                `Resuming processing of file [${id}] at "${filePath}"`,
            );
            if (processedFiles.value.has(filePath)) continue;

            currentFile.value = filePath;
            const success = await processFile(filePath);
            if (success) processedCount.value++;
        }

        currentStatus.value = 'Complete';
        currentFile.value = 'Processing complete';
    } catch (err) {
        error.value = err.message;
        currentStatus.value = 'Error';
    } finally {
        isProcessing = false;
    }
};

const retryFailedFiles = async () => {
    if (isProcessing) return;

    isProcessing = true;
    currentStatus.value = 'Processing';
    error.value = '';

    try {
        const failedFilePaths = [...failedFiles.value];
        totalFiles.value = failedFilePaths.length;
        processedCount.value = 0;

        for (const filePath of failedFilePaths) {
            currentFile.value = filePath;
            const success = await processFile(filePath);
            if (success) processedCount.value++;
        }

        currentStatus.value = 'Complete';
        currentFile.value = 'Retry complete';
    } catch (err) {
        error.value = err.message;
        currentStatus.value = 'Error';
    } finally {
        isProcessing = false;
    }
};

const togglePause = () => {
    isPaused.value = !isPaused.value;
    currentStatus.value = isPaused.value ? 'Paused' : 'Processing';
};

const stopProcessing = () => {
    isProcessing = false;
    isPaused.value = false;
    currentStatus.value = 'Stopped';
    currentFile.value = 'Processing stopped';
};
</script>

<template>
    <Head title="File Processing" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
            >
                File Processing
            </h2>
        </template>

        <div class="mx-auto max-w-4xl p-6">
            <div
                class="rounded-lg border border-gray-100 bg-white p-6 shadow-lg"
            >
                <h3 class="mb-4 text-xl font-bold text-gray-800">
                    File Processing Dashboard
                </h3>

                <div class="mb-6 grid grid-cols-2 gap-4">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">Current File</p>
                        <p class="truncate font-medium text-gray-800 text-wrap font-mono">
                            {{ currentFile }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">Progress</p>
                        <p class="font-medium text-gray-800">
                            {{ processedCount }} / {{ totalFiles }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">Status</p>
                        <p
                            class="font-medium"
                            :class="{
                                'text-blue-600': currentStatus === 'Processing',
                                'text-green-600': currentStatus === 'Complete',
                                'text-red-600': currentStatus === 'Error',
                                'text-gray-800': currentStatus === 'Ready',
                            }"
                        >
                            {{ currentStatus }}
                        </p>
                    </div>
                    <div v-if="error" class="rounded-lg bg-red-50 p-4">
                        <p class="text-sm text-red-600">Error</p>
                        <p class="font-medium text-red-700">{{ error }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">
                            Successfully Processed
                        </p>
                        <p class="font-medium text-green-600">
                            {{ successCount }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">Failed Files</p>
                        <p class="font-medium text-red-600">
                            {{ failedFiles.size }}
                        </p>
                    </div>
                </div>

                <div class="mb-6">
                    <div
                        class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100"
                    >
                        <div
                            class="h-2.5 rounded-full transition-all duration-300"
                            :class="{
                                'bg-blue-600': currentStatus === 'Processing',
                                'bg-green-600': currentStatus === 'Complete',
                                'bg-red-600': currentStatus === 'Error',
                            }"
                            :style="{
                                width: totalFiles
                                    ? `${(processedCount / totalFiles) * 100}%`
                                    : '0%',
                            }"
                        ></div>
                    </div>
                    <p class="mt-2 text-right text-sm text-gray-600">
                        {{
                            Math.round((processedCount / totalFiles) * 100) ||
                            0
                        }}% Complete
                    </p>
                </div>

                <div class="mt-6">
                    <h4 class="mb-3 font-semibold text-gray-800">
                        Processing Log:
                    </h4>
                    <div
                        class="max-h-[400px] overflow-y-auto rounded-lg border border-gray-100"
                    >
                        <ul class="divide-y divide-gray-100">
                            <li
                                v-for="(log, index) in processingLog"
                                :key="index"
                                class="p-3 text-sm"
                                :class="[
                                    log.status === 'success'
                                        ? 'bg-green-50 text-green-700'
                                        : log.status === 'error'
                                          ? 'bg-red-50 text-red-700'
                                          : log.status === 'already processed'
                                            ? 'bg-gray-50 text-gray-600'
                                            : 'bg-blue-50 text-blue-700',
                                ]"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="truncate font-medium">{{
                                        log.file
                                    }}</span>
                                    <span
                                        class="ml-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-green-100 text-green-800':
                                                log.status === 'success',
                                            'bg-red-100 text-red-800':
                                                log.status === 'error',
                                            'bg-gray-100 text-gray-800':
                                                log.status ===
                                                'already processed',
                                            'bg-blue-100 text-blue-800':
                                                log.status === 'processing',
                                        }"
                                    >
                                        {{ log.status }}
                                    </span>
                                </div>
                                <p
                                    v-if="log.message"
                                    class="mt-1 text-xs opacity-75"
                                >
                                    {{ log.message }}
                                </p>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-center gap-4">
                <button
                    v-if="canResume"
                    @click="resumeProcessing"
                    :disabled="currentStatus === 'Processing'"
                    class="flex items-center gap-2 rounded-full bg-green-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span
                        v-if="currentStatus === 'Processing'"
                        class="animate-spin"
                        >⚡</span
                    >
                    Resume Processing ({{ remainingFiles }} files)
                </button>

                <button
                    @click="startProcessing"
                    :disabled="currentStatus === 'Processing'"
                    class="flex items-center gap-2 rounded-full bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span
                        v-if="currentStatus === 'Processing'"
                        class="animate-spin"
                        >⚡</span
                    >
                    {{
                        currentStatus === 'Processing'
                            ? 'Processing...'
                            : 'Start New Processing'
                    }}
                </button>

                <button
                    v-if="failedFiles.size > 0"
                    @click="retryFailedFiles"
                    :disabled="currentStatus === 'Processing'"
                    class="flex items-center gap-2 rounded-full bg-yellow-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-yellow-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span
                        v-if="currentStatus === 'Processing'"
                        class="animate-spin"
                        >⚡</span
                    >
                    Retry Failed Files ({{ failedFiles.size }})
                </button>

                <button
                    v-if="
                        currentStatus === 'Processing' ||
                        currentStatus === 'Paused'
                    "
                    @click="togglePause"
                    class="flex items-center gap-2 rounded-full bg-orange-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-orange-700"
                >
                    {{ isPaused ? 'Resume' : 'Pause' }}
                </button>

                <button
                    v-if="
                        currentStatus === 'Processing' ||
                        currentStatus === 'Paused'
                    "
                    @click="stopProcessing"
                    class="flex items-center gap-2 rounded-full bg-red-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-red-700"
                >
                    Stop
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
