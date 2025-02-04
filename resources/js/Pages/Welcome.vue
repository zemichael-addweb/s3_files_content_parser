<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <div v-if="$page.props.auth.user">
        <Head title="Dashboard" />

        <AuthenticatedLayout>
            <template #header>
                <h2
                    class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200"
                >
                    Dashboard
                </h2>
            </template>

            <div class="py-12">
                <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div
                        class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800"
                    >
                        <!-- links -->
                        <div class="p-6 text-gray-900 dark:text-gray-100">
                            <Link
                                :href="route('file-content')"
                                class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                            >
                                File Content
                            </Link>

                            <Link
                                :href="route('media-content')"
                                class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                            >
                                Media Content
                            </Link>

                            <Link
                                :href="route('media-playground')"
                                class="rounded-md px-3 py-2 text-black ring-1 ring-transparent transition hover:text-black/70 focus:outline-none focus-visible:ring-[#FF2D20] dark:text-white dark:hover:text-white/80 dark:focus-visible:ring-white"
                            >
                                Media Playground
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    </div>

    <div v-else>
        <Head title="Dashboard" />

        <div class="flex h-screen items-center justify-center">
            <div class="text-center">
                <h1 class="text-4xl font-bold">Welcome to the Dashboard</h1>
            </div>
        </div>
    </div>
</template>
