<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    File as FileIcon,
    HardDrive,
    History,
    Loader2,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatBytes } from '@/lib/format';

export interface LogItem {
    id: number;
    name: string;
    type: string;
    size: number;
    status: 'pending' | 'processing' | 'done' | 'failed';
    progress: number;
    error: string | null;
    folder_name: string;
    account_label: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    logs: {
        data: LogItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        links: PaginationLink[];
    };
    filters: {
        status: string;
        q: string;
    };
    counts: {
        all: number;
        done: number;
        failed: number;
        active: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Log Aktivitas', href: '/logs' }],
    },
});

const searchQuery = ref(props.filters.q || '');
const activeStatus = ref(props.filters.status || 'all');
const clearOpen = ref(false);
const deletingLog = ref<LogItem | null>(null);

const hasFilterActive = computed(
    () => activeStatus.value !== 'all' || searchQuery.value.trim() !== '',
);

function filterStatus(status: string) {
    activeStatus.value = status;
    applyFilters();
}

function handleSearch() {
    applyFilters();
}

function resetFilters() {
    searchQuery.value = '';
    activeStatus.value = 'all';
    router.get('/logs', {}, { preserveState: true, preserveScroll: true });
}

function applyFilters() {
    const params: Record<string, string> = {};
    if (activeStatus.value !== 'all') {
        params.status = activeStatus.value;
    }
    if (searchQuery.value.trim() !== '') {
        params.q = searchQuery.value.trim();
    }
    router.get('/logs', params, { preserveState: true, preserveScroll: true });
}

function submitClear() {
    router.delete('/logs', {
        preserveScroll: true,
        onSuccess: () => {
            clearOpen.value = false;
        },
    });
}

function submitDelete(log: LogItem) {
    router.delete(`/logs/${log.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingLog.value = null;
        },
    });
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '-';
    return date.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Log Aktivitas" />

    <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6">
        <!-- Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Log Aktivitas
                </h1>
                <p class="text-muted-foreground mt-0.5 text-sm">
                    Riwayat transfer file ke provider storage dan status antrean
                    proses.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Dialog v-model:open="clearOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="counts.done + counts.failed === 0"
                        >
                            <Trash2 class="mr-1.5 h-4 w-4" />
                            Bersihkan Riwayat
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Bersihkan Riwayat Log?</DialogTitle>
                            <DialogDescription>
                                Hanya riwayat log yang sudah berstatus selesai
                                atau gagal yang akan dihapus. Proses antrean
                                yang sedang berjalan tidak akan terpengaruh.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose as-child>
                                <Button type="button" variant="ghost"
                                    >Batal</Button
                                >
                            </DialogClose>
                            <Button
                                type="button"
                                variant="destructive"
                                @click="submitClear"
                            >
                                Bersihkan Sekarang
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <!-- Status Tabs -->
            <div class="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                        activeStatus === 'all'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground'
                    "
                    @click="filterStatus('all')"
                >
                    Semua
                    <span
                        class="rounded px-1 text-[11px]"
                        :class="
                            activeStatus === 'all'
                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                : 'bg-background/80'
                        "
                    >
                        {{ counts.all }}
                    </span>
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                        activeStatus === 'done'
                            ? 'bg-teal-600 text-white dark:bg-teal-500'
                            : 'bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground'
                    "
                    @click="filterStatus('done')"
                >
                    <CheckCircle2 class="h-3 w-3" />
                    Selesai
                    <span
                        class="rounded px-1 text-[11px]"
                        :class="
                            activeStatus === 'done'
                                ? 'bg-white/20 text-white'
                                : 'bg-background/80'
                        "
                    >
                        {{ counts.done }}
                    </span>
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                        activeStatus === 'failed'
                            ? 'bg-red-600 text-white dark:bg-red-500'
                            : 'bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground'
                    "
                    @click="filterStatus('failed')"
                >
                    <AlertCircle class="h-3 w-3" />
                    Gagal
                    <span
                        class="rounded px-1 text-[11px]"
                        :class="
                            activeStatus === 'failed'
                                ? 'bg-white/20 text-white'
                                : 'bg-background/80'
                        "
                    >
                        {{ counts.failed }}
                    </span>
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                        activeStatus === 'active'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted hover:bg-muted/80 text-muted-foreground hover:text-foreground'
                    "
                    @click="filterStatus('active')"
                >
                    <Loader2
                        class="h-3 w-3"
                        :class="{ 'animate-spin': counts.active > 0 }"
                    />
                    Dalam Proses
                    <span
                        class="rounded px-1 text-[11px]"
                        :class="
                            activeStatus === 'active'
                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                : 'bg-background/80'
                        "
                    >
                        {{ counts.active }}
                    </span>
                </button>
            </div>

            <!-- Search box -->
            <form
                class="flex items-center gap-2"
                @submit.prevent="handleSearch"
            >
                <div class="relative w-full sm:w-64">
                    <Search
                        class="text-muted-foreground absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2"
                    />
                    <Input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari nama file..."
                        class="h-8 pl-8 text-xs"
                    />
                </div>
                <Button
                    type="submit"
                    variant="secondary"
                    size="sm"
                    class="h-8 text-xs"
                >
                    Cari
                </Button>
            </form>
        </div>

        <!-- Empty States -->
        <div
            v-if="logs.data.length === 0"
            class="rounded-lg border border-dashed p-10 text-center"
        >
            <History
                class="text-muted-foreground mx-auto mb-3 h-8 w-8 opacity-60"
            />
            <p class="text-sm font-medium">
                {{
                    hasFilterActive
                        ? 'Tidak ada riwayat yang cocok'
                        : 'Belum ada riwayat log transfer'
                }}
            </p>
            <p class="text-muted-foreground mt-1 text-xs">
                {{
                    hasFilterActive
                        ? 'Coba ubah kata kunci pencarian atau ganti status filter.'
                        : 'Setiap berkas yang diunggah akan otomatis tercatat riwayat dan status transfernya di sini.'
                }}
            </p>
            <div class="mt-4">
                <Button
                    v-if="hasFilterActive"
                    variant="outline"
                    size="sm"
                    @click="resetFilters"
                >
                    Reset Filter
                </Button>
                <Button v-else size="sm" as-child>
                    <Link href="/files">Buka Halaman Files</Link>
                </Button>
            </div>
        </div>

        <!-- Log List Table -->
        <div v-else class="overflow-hidden rounded-lg border">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead
                        class="bg-muted/50 text-muted-foreground border-b font-medium"
                    >
                        <tr>
                            <th class="w-28 px-4 py-3">Status</th>
                            <th class="px-4 py-3">Berkas</th>
                            <th class="w-24 px-4 py-3 text-right">Ukuran</th>
                            <th class="w-48 px-4 py-3">Akun Storage</th>
                            <th class="w-36 px-4 py-3">Waktu</th>
                            <th class="w-12 px-2 py-3 text-center">
                                <span class="sr-only">Aksi</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <template v-for="job in logs.data" :key="job.id">
                            <tr class="hover:bg-muted/40 transition-colors">
                                <!-- Status Badge -->
                                <td
                                    class="px-4 py-3 align-top whitespace-nowrap"
                                >
                                    <Badge
                                        v-if="job.status === 'done'"
                                        variant="outline"
                                        class="gap-1 border-teal-300 bg-teal-50 text-[11px] text-teal-700 dark:border-teal-800 dark:bg-teal-950 dark:text-teal-300"
                                    >
                                        <CheckCircle2 class="h-3 w-3" />
                                        Selesai
                                    </Badge>
                                    <Badge
                                        v-else-if="job.status === 'failed'"
                                        variant="destructive"
                                        class="gap-1 text-[11px]"
                                    >
                                        <AlertCircle class="h-3 w-3" />
                                        Gagal
                                    </Badge>
                                    <Badge
                                        v-else-if="job.status === 'processing'"
                                        variant="secondary"
                                        class="gap-1 border-teal-200 bg-teal-50/50 text-[11px] text-teal-700 dark:border-teal-900 dark:text-teal-300"
                                    >
                                        <Loader2 class="h-3 w-3 animate-spin" />
                                        {{ job.progress }}%
                                    </Badge>
                                    <Badge
                                        v-else
                                        variant="secondary"
                                        class="gap-1 text-[11px]"
                                    >
                                        <Clock class="h-3 w-3" />
                                        Antrean
                                    </Badge>
                                </td>

                                <!-- File Name & Location -->
                                <td class="px-4 py-3 align-top">
                                    <div class="flex items-start gap-2">
                                        <FileIcon
                                            class="text-muted-foreground mt-0.5 h-4 w-4 shrink-0"
                                        />
                                        <div class="min-w-0">
                                            <p
                                                class="text-foreground max-w-xs truncate font-medium sm:max-w-md"
                                            >
                                                {{ job.name }}
                                            </p>
                                            <p
                                                class="text-muted-foreground text-[11px]"
                                            >
                                                Folder tujuan:
                                                {{ job.folder_name }}
                                            </p>

                                            <!-- Error detail line if failed -->
                                            <div
                                                v-if="
                                                    job.status === 'failed' &&
                                                    job.error
                                                "
                                                class="mt-1.5 rounded border border-red-200 bg-red-50 p-2 text-[11px] text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300"
                                            >
                                                <span class="font-semibold"
                                                    >Penyebab gagal:</span
                                                >
                                                {{ job.error }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- File Size -->
                                <td
                                    class="text-muted-foreground px-4 py-3 text-right align-top whitespace-nowrap tabular-nums"
                                >
                                    {{ formatBytes(job.size) }}
                                </td>

                                <!-- Storage Account -->
                                <td
                                    class="text-muted-foreground px-4 py-3 align-top"
                                >
                                    <div
                                        v-if="job.account_label"
                                        class="flex max-w-[180px] items-center gap-1.5 truncate"
                                    >
                                        <HardDrive
                                            class="h-3.5 w-3.5 shrink-0 opacity-70"
                                        />
                                        <span class="truncate">{{
                                            job.account_label
                                        }}</span>
                                    </div>
                                    <span
                                        v-else
                                        class="text-muted-foreground/60 italic"
                                        >Otomatis</span
                                    >
                                </td>

                                <!-- Timestamp -->
                                <td
                                    class="text-muted-foreground px-4 py-3 align-top whitespace-nowrap"
                                >
                                    {{ formatDate(job.created_at) }}
                                </td>

                                <!-- Delete Action -->
                                <td class="px-2 py-3 text-center align-top">
                                    <Dialog>
                                        <DialogTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="text-muted-foreground h-7 w-7 hover:text-red-600"
                                                :aria-label="`Hapus log ${job.name}`"
                                                @click="deletingLog = job"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle
                                                    >Hapus Catatan
                                                    Log?</DialogTitle
                                                >
                                                <DialogDescription>
                                                    Catatan transfer untuk
                                                    berkas "{{ job.name }}" akan
                                                    dihapus dari daftar riwayat.
                                                    Berkas asli di storage tidak
                                                    akan terhapus.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <DialogClose as-child>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        >Batal</Button
                                                    >
                                                </DialogClose>
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    @click="submitDelete(job)"
                                                >
                                                    Hapus Log
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div
                v-if="logs.last_page > 1"
                class="bg-muted/30 flex items-center justify-between border-t px-4 py-2.5 text-xs"
            >
                <div class="text-muted-foreground">
                    Menampilkan
                    {{ (logs.current_page - 1) * logs.per_page + 1 }} -
                    {{
                        Math.min(logs.current_page * logs.per_page, logs.total)
                    }}
                    dari {{ logs.total }} catatan
                </div>

                <div class="flex items-center gap-1">
                    <template v-for="(link, index) in logs.links" :key="index">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="inline-flex h-7 min-w-[28px] items-center justify-center rounded px-2 text-xs transition-colors"
                            :class="
                                link.active
                                    ? 'bg-primary text-primary-foreground font-medium'
                                    : 'hover:bg-muted text-muted-foreground hover:text-foreground'
                            "
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="text-muted-foreground/50 inline-flex h-7 min-w-[28px] items-center justify-center px-2 text-xs"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
