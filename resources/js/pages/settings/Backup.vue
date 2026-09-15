<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowRight,
    CheckCircle2,
    Database,
    Download,
    ExternalLink,
    FileText,
    Folder,
    KeyRound,
    MessageSquare,
    RefreshCw,
    Shield,
    UploadCloud,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { index as backupIndex } from '@/routes/backup';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Backup & Restore',
                href: backupIndex(),
            },
        ],
    },
});

interface RestoreSummary {
    success: boolean;
    manifest?: {
        version?: string;
        created_at?: string;
        app_version?: string;
        source?: string;
    };
    restored_table_counts?: Record<string, number>;
    restored_sessions_count?: number;
    restored_env_keys?: string[];
}

const props = defineProps<{
    stats: {
        virtual_files_count: number;
        virtual_folders_count: number;
        storage_accounts_count: number;
        storage_providers_count: number;
        telegram_sessions_count: number;
        app_key_configured: boolean;
    };
    restoreSummary?: RestoreSummary | null;
    status?: string | null;
}>();

// Export state
const exportPassword = ref('');
const exportPasswordConfirmation = ref('');
const exportError = ref('');
const isExporting = ref(false);

const getXsrfToken = (): string => {
    const match = document.cookie.match(
        new RegExp('(^|;\\s*)XSRF-TOKEN=([^;]+)'),
    );
    return match ? decodeURIComponent(match[2]) : '';
};

const handleExport = async () => {
    exportError.value = '';

    if (!exportPassword.value) {
        exportError.value = 'Master password wajib diisi.';
        return;
    }

    if (exportPassword.value.length < 8) {
        exportError.value = 'Master password minimal 8 karakter.';
        return;
    }

    if (exportPassword.value !== exportPasswordConfirmation.value) {
        exportError.value = 'Konfirmasi password tidak cocok.';
        return;
    }

    isExporting.value = true;

    try {
        const response = await fetch('/settings/backup/export', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
                Accept: 'application/json, application/octet-stream',
            },
            body: JSON.stringify({
                password: exportPassword.value,
                password_confirmation: exportPasswordConfirmation.value,
            }),
        });

        if (!response.ok) {
            let errorMsg = 'Gagal mengekspor berkas backup.';
            try {
                const data = await response.json();
                if (data.message) {
                    errorMsg = data.message;
                }
            } catch {
                // fallback to default errorMsg
            }
            exportError.value = errorMsg;
            return;
        }

        const blob = await response.blob();

        if (blob.size === 0) {
            exportError.value =
                'Gagal: Berkas backup yang diunduh dari server berukuran 0 bytes.';
            return;
        }

        const disposition = response.headers.get('content-disposition');
        let filename =
            'rpebstorage-backup-' +
            new Date().toISOString().slice(0, 10) +
            '.zip';

        if (disposition && disposition.includes('filename=')) {
            const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(
                disposition,
            );
            if (matches && matches[1]) {
                filename = matches[1].replace(/['"]/g, '');
            }
        }

        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();

        // Delay revocation so Chrome/Chromium can complete reading the blob stream
        setTimeout(() => {
            link.remove();
            window.URL.revokeObjectURL(downloadUrl);
        }, 60000);

        exportPassword.value = '';
        exportPasswordConfirmation.value = '';
    } catch (err: unknown) {
        exportError.value =
            err instanceof Error
                ? err.message
                : 'Terjadi gangguan jaringan saat mengunduh backup.';
    } finally {
        isExporting.value = false;
    }
};

// Restore state
const showConfirmModal = ref(false);
const showResultModal = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);
const restoreForm = useForm({
    backup_file: null as File | null,
    password: '',
});

watch(
    () => props.restoreSummary,
    (summary) => {
        if (summary?.success) {
            showResultModal.value = true;
        }
    },
    { immediate: true },
);

const reloadPage = () => {
    window.location.reload();
};

const goToFiles = () => {
    window.location.href = '/files';
};

const onFileSelected = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        const file = target.files[0];
        if (file.size === 0) {
            restoreForm.setError(
                'backup_file',
                'Berkas backup yang dipilih kosong (0 bytes). Pastikan berkas terunduh sempurna.',
            );
            restoreForm.backup_file = null;
            return;
        }
        restoreForm.clearErrors('backup_file');
        restoreForm.backup_file = file;
    } else {
        restoreForm.backup_file = null;
    }
};

const handleRestoreSubmit = () => {
    restoreForm.clearErrors();

    if (!restoreForm.backup_file) {
        restoreForm.setError(
            'backup_file',
            'Pilih berkas arsip backup (.zip) terlebih dahulu.',
        );
        return;
    }

    if (!restoreForm.password) {
        restoreForm.setError(
            'password',
            'Master password arsip backup wajib diisi.',
        );
        return;
    }

    showConfirmModal.value = true;
};

const executeRestore = () => {
    showConfirmModal.value = false;

    restoreForm.post('/settings/backup/restore', {
        preserveScroll: true,
        onSuccess: () => {
            restoreForm.reset('password');
            if (fileInputRef.value) {
                fileInputRef.value.value = '';
            }
        },
        onError: () => {
            // Error captured in restoreForm.errors
        },
    });
};
</script>

<template>
    <Head title="Backup & Restore" />

    <h1 class="sr-only">Backup & Restore</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Backup & Restore Sistem"
            description="Ekspor dan pulihkan seluruh metadata virtual file, akun penyimpanan, kredensial provider, dan sesi Telegram dalam satu berkas arsip terenkripsi."
        />

        <!-- Ringkasan Data -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Card class="p-3">
                <div class="flex items-center gap-2">
                    <FileText class="size-4 text-teal-600 dark:text-teal-400" />
                    <span class="text-muted-foreground text-xs"
                        >Berkas Virtual</span
                    >
                </div>
                <div class="mt-2 text-xl font-semibold tabular-nums">
                    {{ props.stats.virtual_files_count }}
                </div>
            </Card>

            <Card class="p-3">
                <div class="flex items-center gap-2">
                    <Folder class="size-4 text-teal-600 dark:text-teal-400" />
                    <span class="text-muted-foreground text-xs"
                        >Folder Virtual</span
                    >
                </div>
                <div class="mt-2 text-xl font-semibold tabular-nums">
                    {{ props.stats.virtual_folders_count }}
                </div>
            </Card>

            <Card class="p-3">
                <div class="flex items-center gap-2">
                    <Database class="size-4 text-teal-600 dark:text-teal-400" />
                    <span class="text-muted-foreground text-xs"
                        >Akun Cloud</span
                    >
                </div>
                <div class="mt-2 text-xl font-semibold tabular-nums">
                    {{ props.stats.storage_accounts_count }}
                </div>
            </Card>

            <Card class="p-3">
                <div class="flex items-center gap-2">
                    <MessageSquare
                        class="size-4 text-teal-600 dark:text-teal-400"
                    />
                    <span class="text-muted-foreground text-xs"
                        >Sesi Telegram</span
                    >
                </div>
                <div class="mt-2 text-xl font-semibold tabular-nums">
                    {{ props.stats.telegram_sessions_count }}
                </div>
            </Card>
        </div>

        <!-- Ekspor Backup Section -->
        <Card>
            <CardHeader>
                <div class="flex items-center gap-2">
                    <Archive class="size-5 text-teal-600 dark:text-teal-400" />
                    <CardTitle class="text-base"
                        >Ekspor Arsip Terenkripsi</CardTitle
                    >
                </div>
                <CardDescription>
                    Berkas backup dibungkus dalam format ZIP terenkripsi AES-256
                    yang memuat mapping database, kredensial cloud, dan file
                    sesi Telegram.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="handleExport" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="export_password"
                            >Master Password Pengaman</Label
                        >
                        <PasswordInput
                            id="export_password"
                            v-model="exportPassword"
                            placeholder="Minimal 8 karakter"
                            autocomplete="new-password"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="export_password_confirm"
                            >Konfirmasi Master Password</Label
                        >
                        <PasswordInput
                            id="export_password_confirm"
                            v-model="exportPasswordConfirmation"
                            placeholder="Ketik ulang password"
                            autocomplete="new-password"
                        />
                    </div>

                    <div
                        v-if="exportError"
                        class="text-destructive text-sm font-medium"
                    >
                        {{ exportError }}
                    </div>

                    <div class="pt-2">
                        <Button
                            type="submit"
                            :disabled="isExporting"
                            class="gap-2 bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-600 dark:hover:bg-teal-700"
                        >
                            <Spinner v-if="isExporting" class="size-4" />
                            <Download v-else class="size-4" />
                            <span>{{
                                isExporting
                                    ? 'Membuat Arsip Backup...'
                                    : 'Unduh Arsip Backup'
                            }}</span>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Separator />

        <!-- Restore Backup Section -->
        <Card>
            <CardHeader>
                <div class="flex items-center gap-2">
                    <UploadCloud
                        class="size-5 text-amber-600 dark:text-amber-400"
                    />
                    <CardTitle class="text-base"
                        >Pulihkan dari Berkas Backup</CardTitle
                    >
                </div>
                <CardDescription>
                    Unggah berkas arsip backup (.zip) untuk memulihkan seluruh
                    struktur berkas, akun penyimpanan, dan konfigurasi sistem.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <Alert
                    variant="destructive"
                    class="text-foreground border-amber-500/50 bg-amber-500/10 dark:border-amber-500/40"
                >
                    <AlertTriangle
                        class="size-4 text-amber-600 dark:text-amber-400"
                    />
                    <AlertTitle class="text-amber-700 dark:text-amber-300"
                        >Peringatan Penggantian Data</AlertTitle
                    >
                    <AlertDescription class="text-muted-foreground text-sm">
                        Proses pemulihan akan menimpa seluruh data tabel
                        metadata virtual file, kunci enkripsi APP_KEY, dan file
                        sesi Telegram dengan isi berkas backup.
                    </AlertDescription>
                </Alert>

                <Alert
                    v-if="
                        restoreForm.errors.password ||
                        restoreForm.errors.backup_file
                    "
                    variant="destructive"
                    class="border-destructive/50 text-destructive bg-destructive/10"
                >
                    <AlertTriangle class="size-4" />
                    <AlertTitle>Pemulihan Gagal</AlertTitle>
                    <AlertDescription>
                        {{
                            restoreForm.errors.password ||
                            restoreForm.errors.backup_file
                        }}
                    </AlertDescription>
                </Alert>

                <form @submit.prevent="handleRestoreSubmit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="backup_file"
                            >Pilih Berkas Backup (.zip)</Label
                        >
                        <Input
                            id="backup_file"
                            ref="fileInputRef"
                            type="file"
                            accept=".zip,application/zip,application/x-zip-compressed"
                            @change="onFileSelected"
                        />
                        <InputError :message="restoreForm.errors.backup_file" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="restore_password"
                            >Master Password Arsip</Label
                        >
                        <PasswordInput
                            id="restore_password"
                            v-model="restoreForm.password"
                            placeholder="Password yang disetel saat ekspor"
                            autocomplete="current-password"
                        />
                        <InputError :message="restoreForm.errors.password" />
                    </div>

                    <div class="pt-2">
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="restoreForm.processing"
                            class="gap-2"
                        >
                            <Spinner
                                v-if="restoreForm.processing"
                                class="size-4"
                            />
                            <RefreshCw v-else class="size-4" />
                            <span>{{
                                restoreForm.processing
                                    ? 'Memulihkan Sistem...'
                                    : 'Mulai Pemulihan'
                            }}</span>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>

    <!-- Modal Konfirmasi Restore -->
    <Dialog :open="showConfirmModal" @update:open="showConfirmModal = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="text-destructive flex items-center gap-2">
                    <AlertTriangle class="size-5" />
                    <span>Konfirmasi Pemulihan Sistem</span>
                </DialogTitle>
                <DialogDescription class="space-y-2 pt-2 text-sm">
                    <p>
                        Tindakan ini akan menimpa seluruh basis data, kunci
                        enkripsi aplikasi, dan sesi Telegram saat ini dengan
                        data yang ada di dalam arsip backup.
                    </p>
                    <p class="text-foreground font-medium">
                        Pastikan Anda telah memiliki salinan cadangan jika masih
                        membutuhkan data saat ini.
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Catatan: Jika Anda memulihkan berkas arsip dari database
                        yang sama saat ini, data dan angka statistik akan sama
                        persis. Anda dapat mencoba membuat folder/file baru
                        sebelum restore untuk melihat perubahan secara langsung.
                    </p>
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2 sm:gap-0">
                <DialogClose as-child>
                    <Button variant="outline">Batal</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    @click="executeRestore"
                    :disabled="restoreForm.processing"
                >
                    Ya, Timpa dan Pulihkan
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Modal Hasil Pemulihan Sistem -->
    <Dialog :open="showResultModal" @update:open="showResultModal = $event">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    class="flex items-center gap-2 text-teal-700 dark:text-teal-300"
                >
                    <CheckCircle2
                        class="size-5 text-teal-600 dark:text-teal-400"
                    />
                    <span>Sistem Berhasil Dipulihkan</span>
                </DialogTitle>
                <DialogDescription class="pt-1 text-sm">
                    Seluruh metadata berkas, struktur direktori, kredensial akun
                    cloud, dan konfigurasi telah diselaraskan dari arsip backup.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2 text-sm">
                <!-- Info Arsip -->
                <div
                    v-if="props.restoreSummary?.manifest"
                    class="bg-muted/60 space-y-1 rounded-md p-3 text-xs"
                >
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Waktu Backup:</span>
                        <span class="font-mono font-medium">{{
                            props.restoreSummary.manifest.created_at ||
                            'Tidak tercatat'
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Versi Arsip:</span>
                        <span class="font-mono font-medium">{{
                            props.restoreSummary.manifest.app_version || 'v1.0'
                        }}</span>
                    </div>
                </div>

                <!-- Grid Data yang Dipulihkan -->
                <div class="grid grid-cols-2 gap-2">
                    <div
                        class="flex items-center gap-2 rounded-md border p-2.5"
                    >
                        <FileText
                            class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <div>
                            <div class="text-muted-foreground text-xs">
                                Berkas Virtual
                            </div>
                            <div class="text-base font-semibold tabular-nums">
                                {{
                                    (
                                        props.restoreSummary
                                            ?.restored_table_counts
                                            ?.virtual_files ?? 0
                                    ).toLocaleString()
                                }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 rounded-md border p-2.5"
                    >
                        <Folder
                            class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <div>
                            <div class="text-muted-foreground text-xs">
                                Folder Virtual
                            </div>
                            <div class="text-base font-semibold tabular-nums">
                                {{
                                    (
                                        props.restoreSummary
                                            ?.restored_table_counts
                                            ?.virtual_folders ?? 0
                                    ).toLocaleString()
                                }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 rounded-md border p-2.5"
                    >
                        <Database
                            class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <div>
                            <div class="text-muted-foreground text-xs">
                                Akun Cloud
                            </div>
                            <div class="text-base font-semibold tabular-nums">
                                {{
                                    (
                                        props.restoreSummary
                                            ?.restored_table_counts
                                            ?.storage_accounts ?? 0
                                    ).toLocaleString()
                                }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 rounded-md border p-2.5"
                    >
                        <MessageSquare
                            class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <div>
                            <div class="text-muted-foreground text-xs">
                                Sesi Telegram
                            </div>
                            <div class="text-base font-semibold tabular-nums">
                                {{
                                    (
                                        props.restoreSummary
                                            ?.restored_sessions_count ?? 0
                                    ).toLocaleString()
                                }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kunci Enkripsi & Environment -->
                <div
                    v-if="props.restoreSummary?.restored_env_keys?.length"
                    class="space-y-1.5"
                >
                    <div
                        class="text-muted-foreground flex items-center gap-1.5 text-xs font-medium"
                    >
                        <KeyRound
                            class="size-3.5 text-teal-600 dark:text-teal-400"
                        />
                        <span>Kunci Konfigurasi (.env) yang Diperbarui:</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="key in props.restoreSummary
                                .restored_env_keys"
                            :key="key"
                            class="rounded bg-teal-500/10 px-2 py-0.5 font-mono text-[11px] text-teal-700 dark:text-teal-300"
                        >
                            {{ key }}
                        </span>
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-0">
                <DialogClose as-child>
                    <Button variant="outline" @click="reloadPage">
                        <RefreshCw class="mr-1.5 size-4" />
                        Muat Ulang
                    </Button>
                </DialogClose>
                <Button
                    class="bg-teal-600 text-white hover:bg-teal-700"
                    @click="goToFiles"
                >
                    <span>Buka File Manager</span>
                    <ArrowRight class="ml-1.5 size-4" />
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
