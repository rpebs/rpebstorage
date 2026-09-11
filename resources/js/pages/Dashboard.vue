<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import CapacityBar from '@/components/CapacityBar.vue';
import { formatBytes } from '@/lib/format';

defineProps<{
    total: {
        quota: number;
        used: number;
        account_count: number;
        file_count: number;
    };
    accounts: Array<{
        id: number;
        alias: string;
        provider_label: string;
        quota_total: number | null;
        quota_used: number;
        nearly_full: boolean;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
        <div v-if="total.account_count === 0" class="rounded-lg border border-dashed p-10 text-center">
            <p class="font-medium">Kapasitas gabungan masih nol</p>
            <p class="text-muted-foreground mt-1 text-sm">
                Hubungkan akun Google Drive, Dropbox, OneDrive, atau Telegram untuk mulai.
            </p>
            <a
                href="/accounts"
                class="mt-4 inline-block rounded-md bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 dark:bg-teal-500 dark:hover:bg-teal-600"
            >
                Hubungkan akun
            </a>
        </div>

        <template v-else>
            <section aria-label="Peringatan kuota" class="space-y-2">
                <div
                    v-for="account in accounts.filter((a) => a.nearly_full)"
                    :key="`warn-${account.id}`"
                    class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
                    role="status"
                >
                    <span class="font-medium">{{ account.alias }}</span>
                    hampir penuh ({{ formatBytes(account.quota_used) }} terpakai).
                    <a href="/files" class="underline">Bersihkan file</a>
                    atau tambah akun baru.
                </div>
            </section>

            <section aria-label="Kapasitas gabungan" class="rounded-lg border p-6">
                <p class="text-muted-foreground text-sm">Total kapasitas gabungan</p>
                <p class="mt-2 text-3xl font-semibold tracking-tight">
                    {{ formatBytes(total.used) }}
                    <span class="text-muted-foreground text-lg font-normal">
                        / {{ total.quota > 0 ? formatBytes(total.quota) : 'unlimited' }}
                    </span>
                </p>
                <div class="mt-4">
                    <CapacityBar :used="total.used" :total="total.quota" />
                </div>
                <p class="text-muted-foreground mt-3 text-xs">
                    {{ total.account_count }} akun · {{ total.file_count }} file terkelola
                </p>
            </section>

            <section aria-label="Kuota per akun" class="space-y-3">
                <h2 class="text-sm font-medium">Per akun</h2>
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="flex flex-col gap-2 rounded-lg border p-4 sm:flex-row sm:items-center sm:gap-6"
                >
                    <div class="min-w-0 sm:w-56">
                        <p class="truncate text-sm font-medium">{{ account.alias }}</p>
                        <p class="text-muted-foreground text-xs">{{ account.provider_label }}</p>
                    </div>
                    <div class="flex-1">
                        <CapacityBar :used="account.quota_used" :total="account.quota_total" />
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
