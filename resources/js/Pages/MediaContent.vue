<template>
    <div class="mx-auto max-w-4xl p-6">
        <div class="rounded-lg border border-gray-100 bg-white p-6 shadow-lg">
            <h3 class="mb-4 text-xl font-bold text-gray-800">
                Media Processing Dashboard
            </h3>

            <div class="mb-6 grid grid-cols-2 gap-4">
                <div class="rounded-lg bg-gray-50 p-4">
                    <p class="text-sm text-gray-600">Current File</p>
                    <p class="truncate font-medium text-gray-800">
                        {{ currentFile }}
                    </p>
                </div>
                <div class="rounded-lg bg-gray-50 p-4">
                    <p class="text-sm text-gray-600">Progress</p>
                    <p class="font-medium text-gray-800">
                        {{ processedCount }} / {{ totalFiles }}
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
                            :class="getLogClass(log.status)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="truncate font-medium">{{
                                    log.file
                                }}</span>
                                <span
                                    class="ml-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="getStatusClass(log.status)"
                                >
                                    {{ log.status }}
                                </span>
                            </div>
                            <div v-if="log.metadata" class="mt-2 text-xs">
                                <div v-if="log.metadata.duration_formatted">
                                    Duration:
                                    {{ log.metadata.duration_formatted }}
                                </div>
                                <div
                                    v-if="
                                        log.metadata.width &&
                                        log.metadata.height
                                    "
                                >
                                    Resolution: {{ log.metadata.width }}x{{
                                        log.metadata.height
                                    }}
                                </div>
                                <div v-if="log.metadata.codec">
                                    Codec: {{ log.metadata.codec }}
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-center gap-4">
            <button
                @click="startProcessing"
                :disabled="currentStatus === 'Processing'"
                class="flex items-center gap-2 rounded-full bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span v-if="currentStatus === 'Processing'" class="animate-spin"
                    >⚡</span
                >
                {{
                    currentStatus === 'Processing'
                        ? 'Processing...'
                        : 'Start Processing'
                }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const currentFile = ref('Waiting to start');
const processedCount = ref(0);
const totalFiles = ref(0);
const currentStatus = ref('Ready');
const processingLog = ref([]);

const getLogClass = (status) => {
    switch (status) {
        case 'success':
            return 'bg-green-50 text-green-700';
        case 'error':
            return 'bg-red-50 text-red-700';
        case 'already processed':
            return 'bg-gray-50 text-gray-600';
        default:
            return 'bg-blue-50 text-blue-700';
    }
};

const getStatusClass = (status) => {
    switch (status) {
        case 'success':
            return 'bg-green-100 text-green-800';
        case 'error':
            return 'bg-red-100 text-red-800';
        case 'already processed':
            return 'bg-gray-100 text-gray-800';
        default:
            return 'bg-blue-100 text-blue-800';
    }
};

const processFile = async (filePath) => {
    currentFile.value = filePath;

    try {
        const response = await fetch('/api/file-content/process-media', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ file: filePath }),
        });

        const result = await response.json();

        if (result.success) {
            processingLog.value.push({
                file: filePath,
                status: 'success',
                metadata: result.metadata,
            });
            return true;
        } else {
            processingLog.value.push({
                file: filePath,
                status: 'error',
                message: result.error,
            });
            return false;
        }
    } catch (err) {
        processingLog.value.push({
            file: filePath,
            status: 'error',
            message: err.message,
        });
        return false;
    }
};

const startProcessing = async () => {
    currentStatus.value = 'Processing';
    processingLog.value = [];
    processedCount.value = 0;

    try {
        const response = await fetch('/api/pdf-content/get-files');
        const data = await response.json();

        if (data.success) {
            const mediaFiles = data.files.filter((file) => {
                const ext = file.split('.').pop().toLowerCase();
                return ['mp3', 'mp4', 'wav', 'avi', 'mov'].includes(ext);
            });

            totalFiles.value = mediaFiles.length;

            for (const filePath of mediaFiles) {
                const success = await processFile(filePath);
                if (success) processedCount.value++;
            }

            currentStatus.value = 'Complete';
            currentFile.value = 'Processing complete';
        }
    } catch (err) {
        currentStatus.value = 'Error';
        console.error(err);
    }
};
</script>
