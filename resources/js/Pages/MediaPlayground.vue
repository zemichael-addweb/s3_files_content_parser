<template>
    <div class="mx-auto max-w-4xl p-6">
        <div class="rounded-lg border border-gray-100 bg-white p-6 shadow-lg">
            <h3 class="mb-4 text-xl font-bold text-gray-800">
                Media Processing Dashboard
            </h3>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700"
                    >Select Media File</label
                >
                <input
                    type="file"
                    @change="handleFileSelect"
                    accept=".mp3,.mp4,.wav,.avi,.mov"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />
            </div>

            <div v-if="selectedFile" class="mb-6 rounded-lg bg-gray-50 p-4">
                <p class="text-sm text-gray-600">Selected File</p>
                <p class="truncate font-medium text-gray-800">
                    {{ selectedFile.name }}
                </p>
            </div>

            <div class="mt-6" v-if="processingLog.length">
                <h4 class="mb-3 font-semibold text-gray-800">
                    Processing Result:
                </h4>
                <div class="rounded-lg border border-gray-100">
                    <div
                        class="p-3 text-sm"
                        :class="getLogClass(processingLog[0].status)"
                    >
                        <div class="flex items-center justify-between">
                            <span class="truncate font-medium">{{
                                processingLog[0].file
                            }}</span>
                            <span
                                class="ml-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="getStatusClass(processingLog[0].status)"
                            >
                                {{ processingLog[0].status }}
                            </span>
                        </div>
                        <div
                            v-if="processingLog[0].metadata"
                            class="mt-2 text-xs"
                        >
                            <div
                                v-if="
                                    processingLog[0].metadata.duration_formatted
                                "
                            >
                                Duration:
                                {{
                                    processingLog[0].metadata.duration_formatted
                                }}
                            </div>
                            <div
                                v-if="
                                    processingLog[0].metadata.width &&
                                    processingLog[0].metadata.height
                                "
                            >
                                Resolution:
                                {{ processingLog[0].metadata.width }}x{{
                                    processingLog[0].metadata.height
                                }}
                            </div>
                            <div v-if="processingLog[0].metadata.codec">
                                Codec: {{ processingLog[0].metadata.codec }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Display metadata -->
            <div v-if="processingLog.length" class="mt-6 space-y-4">
                <div
                    v-for="(value, key) in processingLog[0].metadata"
                    :key="key"
                    class="rounded-lg border border-gray-200 p-4"
                >
                    <h3 class="mb-2 text-lg font-semibold text-gray-800">
                        {{ key }}
                    </h3>
                    <div class="pl-4">
                        <template
                            v-if="typeof value === 'object' && value !== null"
                        >
                            <div
                                v-for="(subValue, subKey) in value"
                                :key="subKey"
                                class="py-1"
                            >
                                <template
                                    v-if="
                                        typeof subValue !== 'object' ||
                                        subValue === null
                                    "
                                >
                                    <span class="font-medium text-gray-700"
                                        >{{ subKey }}:</span
                                    >
                                    <span class="ml-2 text-gray-600">{{
                                        subValue
                                    }}</span>
                                </template>
                                <template v-else>
                                    <div class="mb-1 font-medium text-gray-700">
                                        {{ subKey }}:
                                    </div>
                                    <div
                                        class="border-l-2 border-gray-200 pl-4"
                                    >
                                        <div
                                            v-for="(
                                                deepValue, deepKey
                                            ) in subValue"
                                            :key="deepKey"
                                            class="py-1"
                                        >
                                            <span
                                                class="font-medium text-gray-700"
                                                >{{ deepKey }}:</span
                                            >
                                            <span class="ml-2 text-gray-600">{{
                                                deepValue
                                            }}</span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template v-else>
                            <span class="text-gray-600">{{ value }}</span>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-center gap-4">
            <button
                @click="processFile"
                :disabled="!selectedFile || currentStatus === 'Processing'"
                class="flex items-center gap-2 rounded-full bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white transition-all duration-200 hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span v-if="currentStatus === 'Processing'" class="animate-spin"
                    >⚡</span
                >
                {{
                    currentStatus === 'Processing'
                        ? 'Processing...'
                        : 'Process File'
                }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const selectedFile = ref(null);
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

const handleFileSelect = (event) => {
    selectedFile.value = event.target.files[0];
    processingLog.value = [];
};

const processFile = async () => {
    if (!selectedFile.value) return;

    currentStatus.value = 'Processing';
    processingLog.value = [];

    try {
        const formData = new FormData();
        formData.append('file', selectedFile.value);

        const response = await fetch('/api/process-media-locally', {
            method: 'POST',
            body: formData,
        });

        const result = await response.json();

        if (result.success) {
            processingLog.value.push({
                file: selectedFile.value.name,
                status: 'success',
                metadata: result.metadata,
            });
        } else {
            processingLog.value.push({
                file: selectedFile.value.name,
                status: 'error',
                message: result.error,
            });
        }
    } catch (err) {
        processingLog.value.push({
            file: selectedFile.value.name,
            status: 'error',
            message: err.message,
        });
    } finally {
        currentStatus.value = 'Ready';
    }
};
</script>

<style scoped>
.border-l-2 {
    margin-left: 0.5rem;
}
</style>
