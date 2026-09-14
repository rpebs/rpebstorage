<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Archive,
    ArrowRight,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    Cloud,
    Database,
    Download,
    File as FileIcon,
    FileText,
    Film,
    Folder,
    FolderOpen,
    HardDrive,
    Image,
    Plus,
    RefreshCw,
    Send,
    Upload,
} from '@lucide/vue';
import CapacityBar from '@/components/CapacityBar.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatBytes } from '@/lib/format';
import { dashboard } from '@/routes';

interface DashboardProps {
    total: {
        quota: number;
        used: number;
        free?: number;
        has_unlimited?: boolean;
        account_count: number;
        total_account_count?: number;
        expired_account_count?: number;
        file_count: number;
        folder_count?: number;
        active_jobs_count?: number;
        last_synced_at_human?: string | null;
    };
    providers?: Array<{
        provider_id: number;
        name: string;
        label: string;
        account_count: number;
        quota_total: number | null;
        quota_used: number;
        is_unlimited: boolean;
        percent: number | null;
        files_count: number;
    }>;
    accounts: Array<{
        id: number;
        alias: string;
        provider?: string;
        provider_label: string;
        status?: string;
        is_active?: boolean;
        is_unlimited?: boolean;
        quota_total: number | null;
        quota_used: number;
        remaining_quota?: number | null;
        used_percent?: number | null;
        nearly_full: boolean;
        file_count?: number;
        quota_synced_at_human?: string | null;
    }>;
    recent_files?: Array<{
        id: number;
        name: string;
        size: number;
        mime_type: string | null;
        folder_id: number | null;
        folder_name: string;
        account_alias: string;
        account_status: string;
        provider_name: string;
        provider_label: string;
        is_accessible: boolean;
        created_at_human: string;
        created_at: string;
    }>;
}

const props = withDefaults(defineProps<DashboardProps>(), {
    providers: () => [],
    recent_files: () => [],
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const totalAccountCount = computed(() => {
    return props.total.total_account_count ?? props.total.account_count;
});

const expiredCount = computed(() => {
    return props.total.expired_account_count ?? 0;
});

const activeJobsCount = computed(() => {
    return props.total.active_jobs_count ?? 0;
});

const hasUnlimited = computed(() => {
    return props.total.has_unlimited ?? false;
});

const freeSpace = computed(() => {
    return (
        props.total.free ?? Math.max(0, props.total.quota - props.total.used)
    );
});

const folderCount = computed(() => {
    return props.total.folder_count ?? 0;
});

const aggregatePercent = computed(() => {
    if (!props.total.quota || props.total.quota <= 0) {
        return null;
    }
    return Math.min(
        100,
        Math.round((props.total.used / props.total.quota) * 100),
    );
});

function getProviderIcon(provider?: string) {
    switch (provider) {
        case 'telegram':
            return Send;
        case 'mega':
            return Database;
        case 'google_drive':
            return HardDrive;
        case 'dropbox':
            return Archive;
        case 'onedrive':
            return Cloud;
        default:
            return Cloud;
    }
}

function getFileIcon(mimeType: string | null, name: string) {
    if (!mimeType) {
        const ext = name.split('.').pop()?.toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'].includes(ext ?? ''))
            return Image;
        if (['mp4', 'mkv', 'mov', 'avi', 'webm'].includes(ext ?? ''))
            return Film;
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext ?? ''))
            return Archive;
        if (
            ['pdf', 'doc', 'docx', 'txt', 'md', 'xlsx', 'csv'].includes(
                ext ?? '',
            )
        )
            return FileText;
        return FileIcon;
    }
    if (mimeType.startsWith('image/')) return Image;
    if (mimeType.startsWith('video/')) return Film;
    if (
        mimeType.includes('zip') ||
        mimeType.includes('tar') ||
        mimeType.includes('compressed') ||
        mimeType.includes('archive')
    )
        return Archive;
    if (
        mimeType.startsWith('text/') ||
        mimeType.includes('pdf') ||
        mimeType.includes('document')
    )
        return FileText;
    return FileIcon;
}

function statusBadgeVariant(status?: string) {
    switch (status) {
        case 'active':
            return 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950/60 dark:text-teal-300 dark:border-teal-800';
        case 'expired':
            return 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800';
        default:
            return 'bg-neutral-100 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:border-neutral-700';
    }
}

function statusText(status?: string) {
    switch (status) {
        case 'active':
            return 'Aktif';
        case 'expired':
            return 'Kedaluwarsa';
        default:
            return 'Nonaktif';
    }
}
</script>

<template>
    <Head title="Dashboard Storage" />

    <div class="mx-auto w-full max-w-7xl space-y-8 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Header & Top Action Bar -->
        <header
            class="border-border flex flex-col gap-4 border-b pb-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1
                    class="text-foreground text-2xl font-semibold tracking-tight"
                >
                    Dashboard Storage
                </h1>
                <p class="text-muted-foreground mt-1 text-sm">
                    Ringkasan kapasitas agregat, distribusi provider, dan
                    aktivitas berkas terkini.
                </p>
                <div
                    v-if="total.last_synced_at_human"
                    class="text-muted-foreground mt-2 flex items-center gap-1.5 text-xs"
                >
                    <Clock class="size-3.5" />
                    <span
                        >Sinkronisasi kuota terakhir:
                        {{ total.last_synced_at_human }}</span
                    >
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button variant="outline" size="sm" as-child>
                    <Link href="/accounts">
                        <Plus class="mr-1.5 size-4" />
                        <span>Kelola Akun</span>
                    </Link>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link href="/logs">
                        <Activity class="mr-1.5 size-4" />
                        <span>Log Antrean</span>
                    </Link>
                </Button>
                <Button
                    size="sm"
                    class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-neutral-950 dark:hover:bg-teal-600"
                    as-child
                >
                    <Link href="/files">
                        <Upload class="mr-1.5 size-4" />
                        <span>Buka File Manager</span>
                    </Link>
                </Button>
            </div>
        </header>

        <!-- Onboarding Empty State if totalAccountCount === 0 -->
        <div
            v-if="totalAccountCount === 0"
            class="border-border bg-card rounded-xl border border-dashed p-12 text-center"
        >
            <div
                class="mx-auto flex size-12 items-center justify-center rounded-full bg-teal-50 text-teal-600 dark:bg-teal-950 dark:text-teal-400"
            >
                <HardDrive class="size-6" />
            </div>
            <h2 class="text-foreground mt-4 text-base font-semibold">
                Kapasitas gabungan belum aktif
            </h2>
            <p class="text-muted-foreground mx-auto mt-1.5 max-w-md text-sm">
                Hubungkan akun Google Drive, Telegram, Dropbox, atau OneDrive
                untuk mulai menyatukan penyimpanan cloud Anda ke dalam satu
                virtual filesystem.
            </p>
            <div class="mt-6 flex justify-center gap-3">
                <Button
                    class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-neutral-950 dark:hover:bg-teal-600"
                    as-child
                >
                    <Link href="/accounts">
                        <Plus class="mr-1.5 size-4" />
                        <span>Hubungkan Akun Sekarang</span>
                    </Link>
                </Button>
            </div>
        </div>

        <template v-else>
            <!-- System Notifications / Warning Banners -->
            <section
                v-if="
                    accounts.some((a) => a.nearly_full) ||
                    expiredCount > 0 ||
                    activeJobsCount > 0
                "
                aria-label="Notifikasi sistem"
                class="space-y-3"
            >
                <!-- Warning: Nearly Full -->
                <div
                    v-for="account in accounts.filter((a) => a.nearly_full)"
                    :key="`warn-${account.id}`"
                    class="flex flex-col justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200"
                    role="alert"
                >
                    <div class="flex items-center gap-2.5">
                        <AlertTriangle
                            class="size-4 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <span>
                            <strong class="font-medium">{{
                                account.alias
                            }}</strong>
                            ({{ account.provider_label }}) hampir penuh:
                            {{ formatBytes(account.quota_used) }} terpakai.
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 text-xs">
                        <Link
                            href="/files"
                            class="font-medium underline hover:text-amber-950 dark:hover:text-white"
                        >
                            Bersihkan berkas
                        </Link>
                        <span>·</span>
                        <Link
                            href="/accounts"
                            class="font-medium underline hover:text-amber-950 dark:hover:text-white"
                        >
                            Tambah akun baru
                        </Link>
                    </div>
                </div>

                <!-- Warning: Expired Tokens -->
                <div
                    v-if="expiredCount > 0"
                    class="flex flex-col justify-between gap-2 rounded-lg border border-rose-200 bg-rose-50/80 px-4 py-3 text-sm text-rose-900 sm:flex-row sm:items-center dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-200"
                    role="alert"
                >
                    <div class="flex items-center gap-2.5">
                        <AlertCircle
                            class="size-4 shrink-0 text-rose-600 dark:text-rose-400"
                        />
                        <span>
                            Ada <strong>{{ expiredCount }} akun</strong> yang
                            kedaluwarsa. Token otorisasi perlu diperbarui agar
                            berkas dapat diakses kembali.
                        </span>
                    </div>
                    <Link
                        href="/accounts"
                        class="shrink-0 text-xs font-medium underline hover:text-rose-950 dark:hover:text-white"
                    >
                        Perbarui di Akun Storage &rarr;
                    </Link>
                </div>

                <!-- Info: Active Background Jobs -->
                <div
                    v-if="activeJobsCount > 0"
                    class="flex items-center justify-between gap-2 rounded-lg border border-teal-200 bg-teal-50/70 px-4 py-3 text-sm text-teal-900 dark:border-teal-900/60 dark:bg-teal-950/40 dark:text-teal-200"
                    role="status"
                >
                    <div class="flex items-center gap-2.5">
                        <RefreshCw
                            class="size-4 shrink-0 animate-spin text-teal-600 dark:text-teal-400"
                        />
                        <span>
                            Terdapat
                            <strong>{{ activeJobsCount }} antrean</strong>
                            berkas yang sedang diproses di latar belakang.
                        </span>
                    </div>
                    <Link
                        href="/logs"
                        class="shrink-0 text-xs font-medium underline hover:text-teal-950 dark:hover:text-white"
                    >
                        Cek progress di Log &rarr;
                    </Link>
                </div>
            </section>

            <!-- 4 KPI Summary Cards -->
            <section
                aria-label="Ringkasan metrik utama"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
            >
                <!-- Card 1: Kapasitas Terpakai -->
                <Card class="border-border">
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <span class="text-muted-foreground text-xs font-medium"
                            >Kapasitas Terpakai</span
                        >
                        <div
                            class="bg-muted text-muted-foreground rounded-md p-1.5"
                        >
                            <HardDrive class="size-4" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-foreground text-2xl font-bold tracking-tight tabular-nums"
                        >
                            {{ formatBytes(total.used) }}
                        </div>
                        <p
                            class="text-muted-foreground mt-1 text-xs tabular-nums"
                        >
                            <template v-if="aggregatePercent !== null">
                                {{ aggregatePercent }}% dari total kuota
                                terdaftar
                            </template>
                            <template v-else>
                                Total terpakai di seluruh provider
                            </template>
                        </p>
                    </CardContent>
                </Card>

                <!-- Card 2: Sisa Ruang Bebas -->
                <Card class="border-border">
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <span class="text-muted-foreground text-xs font-medium"
                            >Sisa Ruang Bebas</span
                        >
                        <div
                            class="bg-muted text-muted-foreground rounded-md p-1.5"
                        >
                            <Cloud class="size-4" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-foreground text-2xl font-bold tracking-tight tabular-nums"
                        >
                            {{
                                hasUnlimited
                                    ? 'Tanpa Batas'
                                    : formatBytes(freeSpace)
                            }}
                        </div>
                        <p
                            class="text-muted-foreground mt-1 text-xs tabular-nums"
                        >
                            {{
                                hasUnlimited
                                    ? 'Didukung backend Telegram'
                                    : total.quota > 0
                                      ? `${formatBytes(total.quota)} batas kuota`
                                      : 'Belum ada kuota'
                            }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Card 3: Akun Terhubung -->
                <Card class="border-border">
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <span class="text-muted-foreground text-xs font-medium"
                            >Akun Terhubung</span
                        >
                        <div
                            class="bg-muted text-muted-foreground rounded-md p-1.5"
                        >
                            <CheckCircle2
                                class="size-4 text-teal-600 dark:text-teal-400"
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-foreground text-2xl font-bold tracking-tight tabular-nums"
                        >
                            {{ total.account_count }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                                >akun aktif</span
                            >
                        </div>
                        <p class="text-muted-foreground mt-1 text-xs">
                            {{
                                expiredCount > 0
                                    ? `${expiredCount} akun butuh verifikasi`
                                    : 'Semua akun berstatus normal'
                            }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Card 4: Berkas & Folder -->
                <Card class="border-border">
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-2"
                    >
                        <span class="text-muted-foreground text-xs font-medium"
                            >Berkas Terkelola</span
                        >
                        <div
                            class="bg-muted text-muted-foreground rounded-md p-1.5"
                        >
                            <FolderOpen class="size-4" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            class="text-foreground text-2xl font-bold tracking-tight tabular-nums"
                        >
                            {{ total.file_count }}
                            <span
                                class="text-muted-foreground text-sm font-normal"
                                >file</span
                            >
                        </div>
                        <p
                            class="text-muted-foreground mt-1 text-xs tabular-nums"
                        >
                            Terbagi dalam {{ folderCount }} folder virtual
                        </p>
                    </CardContent>
                </Card>
            </section>

            <!-- Middle Section: Aggregate Storage & Distribution (Left) + Quick Actions & System Info (Right) -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left: Capacity Overview & Provider Distribution (2 cols) -->
                <Card class="border-border lg:col-span-2">
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div>
                                <CardTitle
                                    class="text-foreground text-base font-semibold"
                                >
                                    Alokasi & Distribusi Kapasitas
                                </CardTitle>
                                <CardDescription
                                    class="text-muted-foreground mt-0.5 text-xs"
                                >
                                    Pantau penggunaan kapasitas agregat dan
                                    kontribusi tiap provider cloud
                                </CardDescription>
                            </div>
                            <span
                                v-if="aggregatePercent !== null"
                                class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700 tabular-nums dark:border-teal-800 dark:bg-teal-950 dark:text-teal-300"
                            >
                                {{ aggregatePercent }}% Terpakai
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Aggregate bar box -->
                        <div
                            class="border-border bg-muted/30 space-y-3 rounded-lg border p-4"
                        >
                            <div
                                class="flex flex-col justify-between gap-1 sm:flex-row sm:items-center"
                            >
                                <span
                                    class="text-muted-foreground text-xs font-medium tracking-wider uppercase"
                                >
                                    Total Kapasitas Gabungan
                                </span>
                                <span
                                    class="text-foreground text-sm font-semibold tabular-nums"
                                >
                                    {{ formatBytes(total.used) }}
                                    <span
                                        class="text-muted-foreground font-normal"
                                    >
                                        /
                                        {{
                                            total.quota > 0
                                                ? formatBytes(total.quota)
                                                : 'Tanpa Batas'
                                        }}
                                    </span>
                                </span>
                            </div>

                            <!-- Progress Bar -->
                            <div
                                class="bg-muted h-2.5 w-full overflow-hidden rounded-full"
                            >
                                <div
                                    class="h-full rounded-full transition-all duration-500"
                                    :class="
                                        aggregatePercent !== null &&
                                        aggregatePercent >= 90
                                            ? 'bg-amber-500'
                                            : 'bg-teal-600 dark:bg-teal-400'
                                    "
                                    :style="{
                                        width:
                                            aggregatePercent !== null
                                                ? `${aggregatePercent}%`
                                                : '20%',
                                    }"
                                    role="progressbar"
                                    :aria-valuenow="aggregatePercent ?? 0"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                />
                            </div>

                            <!-- Breakdown Legend -->
                            <div
                                class="text-muted-foreground grid grid-cols-2 gap-2 pt-1 text-xs sm:grid-cols-3"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="size-2 shrink-0 rounded-full bg-teal-600 dark:bg-teal-400"
                                    />
                                    <span
                                        >Terpakai:
                                        <strong
                                            class="text-foreground tabular-nums"
                                            >{{
                                                formatBytes(total.used)
                                            }}</strong
                                        ></span
                                    >
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="bg-muted-foreground/40 size-2 shrink-0 rounded-full"
                                    />
                                    <span
                                        >Sisa:
                                        <strong
                                            class="text-foreground tabular-nums"
                                            >{{
                                                hasUnlimited
                                                    ? 'Bebas'
                                                    : formatBytes(freeSpace)
                                            }}</strong
                                        ></span
                                    >
                                </div>
                                <div
                                    v-if="hasUnlimited"
                                    class="col-span-2 flex items-center gap-1.5 sm:col-span-1"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-full bg-sky-500"
                                    />
                                    <span class="text-foreground"
                                        >Telegram Unlimited</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Provider Distribution Breakdown -->
                        <div class="space-y-3">
                            <h3
                                class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                            >
                                Kontribusi per Provider
                            </h3>

                            <div
                                v-if="providers.length === 0"
                                class="text-muted-foreground py-2 text-xs"
                            >
                                Belum ada provider aktif.
                            </div>

                            <div
                                v-else
                                class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                            >
                                <div
                                    v-for="prov in providers"
                                    :key="prov.provider_id"
                                    class="border-border bg-card/60 space-y-2 rounded-lg border p-3"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div
                                            class="flex min-w-0 items-center gap-2"
                                        >
                                            <component
                                                :is="getProviderIcon(prov.name)"
                                                class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                                            />
                                            <span
                                                class="text-foreground truncate text-sm font-medium"
                                                >{{ prov.label }}</span
                                            >
                                        </div>
                                        <span
                                            class="text-muted-foreground shrink-0 text-xs tabular-nums"
                                        >
                                            {{ prov.account_count }} akun
                                        </span>
                                    </div>

                                    <CapacityBar
                                        :used="prov.quota_used"
                                        :total="prov.quota_total"
                                        :hide-label="true"
                                    />

                                    <div
                                        class="text-muted-foreground flex items-center justify-between text-xs tabular-nums"
                                    >
                                        <span>{{
                                            formatBytes(prov.quota_used)
                                        }}</span>
                                        <span>{{
                                            prov.quota_total
                                                ? formatBytes(prov.quota_total)
                                                : 'Tanpa batas'
                                        }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Right: Quick Actions & Status (1 col) -->
                <Card class="border-border flex flex-col justify-between">
                    <div>
                        <CardHeader>
                            <CardTitle
                                class="text-foreground text-base font-semibold"
                            >
                                Pintasan Aksi
                            </CardTitle>
                            <CardDescription
                                class="text-muted-foreground mt-0.5 text-xs"
                            >
                                Operasi cepat untuk kebutuhan penyimpanan harian
                            </CardDescription>
                        </CardHeader>

                        <CardContent class="space-y-2.5">
                            <Button
                                variant="outline"
                                class="h-auto w-full justify-start px-3.5 py-3 text-left hover:border-teal-500/50"
                                as-child
                            >
                                <Link
                                    href="/files"
                                    class="flex items-center gap-3"
                                >
                                    <div
                                        class="shrink-0 rounded-md bg-teal-50 p-2 text-teal-600 dark:bg-teal-950 dark:text-teal-400"
                                    >
                                        <Upload class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="text-foreground text-sm font-medium"
                                        >
                                            Unggah & Jelajah File
                                        </div>
                                        <div
                                            class="text-muted-foreground truncate text-xs"
                                        >
                                            Pilih akun tujuan atau gunakan
                                            auto-balancing
                                        </div>
                                    </div>
                                    <ArrowUpRight
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                </Link>
                            </Button>

                            <Button
                                variant="outline"
                                class="h-auto w-full justify-start px-3.5 py-3 text-left hover:border-teal-500/50"
                                as-child
                            >
                                <Link
                                    href="/accounts"
                                    class="flex items-center gap-3"
                                >
                                    <div
                                        class="shrink-0 rounded-md bg-teal-50 p-2 text-teal-600 dark:bg-teal-950 dark:text-teal-400"
                                    >
                                        <Plus class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="text-foreground text-sm font-medium"
                                        >
                                            Hubungkan Akun Baru
                                        </div>
                                        <div
                                            class="text-muted-foreground truncate text-xs"
                                        >
                                            Tambah Google Drive, Telegram,
                                            OneDrive, Dropbox
                                        </div>
                                    </div>
                                    <ArrowUpRight
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                </Link>
                            </Button>

                            <Button
                                variant="outline"
                                class="h-auto w-full justify-start px-3.5 py-3 text-left hover:border-teal-500/50"
                                as-child
                            >
                                <Link
                                    href="/logs"
                                    class="flex items-center gap-3"
                                >
                                    <div
                                        class="shrink-0 rounded-md bg-teal-50 p-2 text-teal-600 dark:bg-teal-950 dark:text-teal-400"
                                    >
                                        <Activity class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="text-foreground text-sm font-medium"
                                        >
                                            Riwayat & Log Antrean
                                        </div>
                                        <div
                                            class="text-muted-foreground truncate text-xs"
                                        >
                                            Pantau transfer chunk Telegram dan
                                            sinkronisasi
                                        </div>
                                    </div>
                                    <ArrowUpRight
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                </Link>
                            </Button>
                        </CardContent>
                    </div>

                    <CardFooter class="border-border border-t pt-4 pb-4">
                        <div
                            class="text-muted-foreground w-full space-y-1.5 text-xs"
                        >
                            <div class="flex items-center justify-between">
                                <span>Status Sinkronisasi:</span>
                                <span
                                    class="font-medium text-teal-700 dark:text-teal-300"
                                    >Otomatis Tiap Jam</span
                                >
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Load Balancing:</span>
                                <span class="text-foreground font-medium"
                                    >Sisa Kuota Terbanyak</span
                                >
                            </div>
                        </div>
                    </CardFooter>
                </Card>
            </div>

            <!-- Third Section: Accounts Grid -->
            <section aria-label="Status per akun storage" class="space-y-4">
                <div
                    class="flex flex-col justify-between gap-1 sm:flex-row sm:items-center"
                >
                    <div>
                        <h2 class="text-foreground text-base font-semibold">
                            Akun Storage Terhubung
                        </h2>
                        <p class="text-muted-foreground text-xs">
                            Status kuota, kapasitas tersisa, dan berkas
                            tersimpan pada setiap akun
                        </p>
                    </div>
                    <Link
                        href="/accounts"
                        class="flex items-center gap-1 text-xs font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300"
                    >
                        <span>Kelola semua akun ({{ accounts.length }})</span>
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>

                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="account in accounts"
                        :key="account.id"
                        class="group bg-card space-y-3 rounded-xl border p-4 transition-all duration-200"
                        :class="
                            account.nearly_full
                                ? 'border-amber-300 bg-amber-50/20 dark:border-amber-900/60 dark:bg-amber-950/20'
                                : 'border-border hover:border-border/80'
                        "
                    >
                        <!-- Account Card Top -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div
                                    class="bg-muted text-foreground shrink-0 rounded-lg p-2"
                                >
                                    <component
                                        :is="getProviderIcon(account.provider)"
                                        class="size-4 text-teal-600 dark:text-teal-400"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="text-foreground truncate text-sm font-medium"
                                        :title="account.alias"
                                    >
                                        {{ account.alias }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        {{ account.provider_label }}
                                    </p>
                                </div>
                            </div>

                            <span
                                :class="[
                                    'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                    statusBadgeVariant(account.status),
                                ]"
                            >
                                {{ statusText(account.status) }}
                            </span>
                        </div>

                        <!-- Capacity Bar -->
                        <div class="pt-1">
                            <CapacityBar
                                :used="account.quota_used"
                                :total="account.quota_total"
                            />
                        </div>

                        <!-- Account Meta -->
                        <div
                            class="border-border/60 text-muted-foreground flex items-center justify-between border-t pt-2.5 text-xs tabular-nums"
                        >
                            <span>
                                Sisa:
                                <strong class="text-foreground font-medium">{{
                                    account.remaining_quota !== null &&
                                    account.remaining_quota !== undefined
                                        ? formatBytes(account.remaining_quota)
                                        : 'Tanpa Batas'
                                }}</strong>
                            </span>
                            <span> {{ account.file_count ?? 0 }} berkas </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Fourth Section: Recent Files -->
            <section aria-label="Berkas terbaru" class="space-y-4">
                <div
                    class="flex flex-col justify-between gap-1 sm:flex-row sm:items-center"
                >
                    <div>
                        <h2 class="text-foreground text-base font-semibold">
                            Berkas Terbaru
                        </h2>
                        <p class="text-muted-foreground text-xs">
                            5 file terakhir yang diunggah ke virtual filesystem
                        </p>
                    </div>
                    <Link
                        href="/files"
                        class="flex items-center gap-1 text-xs font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300"
                    >
                        <span>Buka File Manager</span>
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>

                <!-- Empty files state -->
                <div
                    v-if="recent_files.length === 0"
                    class="border-border bg-card rounded-xl border border-dashed p-8 text-center"
                >
                    <FolderOpen
                        class="text-muted-foreground/60 mx-auto size-8"
                    />
                    <p class="text-foreground mt-2 text-sm font-medium">
                        Belum ada berkas yang diunggah
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Unggah berkas pertama Anda untuk mulai mengelola cloud
                        storage personal.
                    </p>
                    <Button
                        size="sm"
                        class="mt-4 bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-neutral-950 dark:hover:bg-teal-600"
                        as-child
                    >
                        <Link href="/files">
                            <Upload class="mr-1.5 size-3.5" />
                            <span>Unggah Berkas</span>
                        </Link>
                    </Button>
                </div>

                <!-- Recent files table & responsive cards -->
                <div
                    v-else
                    class="border-border bg-card overflow-hidden rounded-xl border"
                >
                    <!-- Desktop Table (sm and up) -->
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="w-full text-left text-sm">
                            <thead
                                class="border-border bg-muted/40 text-muted-foreground border-b text-xs font-medium tracking-wider uppercase"
                            >
                                <tr>
                                    <th scope="col" class="px-4 py-3">
                                        Nama Berkas
                                    </th>
                                    <th scope="col" class="px-4 py-3">
                                        Folder Virtual
                                    </th>
                                    <th scope="col" class="px-4 py-3">
                                        Akun Storage
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Ukuran
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Waktu Unggah
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-right"
                                    >
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-border divide-y">
                                <tr
                                    v-for="file in recent_files"
                                    :key="file.id"
                                    class="hover:bg-muted/30 transition-colors"
                                >
                                    <td class="px-4 py-3">
                                        <div
                                            class="flex min-w-0 items-center gap-2.5"
                                        >
                                            <component
                                                :is="
                                                    getFileIcon(
                                                        file.mime_type,
                                                        file.name,
                                                    )
                                                "
                                                class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                                            />
                                            <span
                                                class="text-foreground max-w-[220px] truncate font-medium"
                                                :title="file.name"
                                            >
                                                {{ file.name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        class="text-muted-foreground px-4 py-3 text-xs"
                                    >
                                        <Link
                                            :href="
                                                file.folder_id
                                                    ? `/files?folder=${file.folder_id}`
                                                    : '/files'
                                            "
                                            class="hover:text-foreground inline-flex items-center gap-1 transition-colors"
                                        >
                                            <Folder class="size-3" />
                                            <span>{{ file.folder_name }}</span>
                                        </Link>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <span
                                            class="text-muted-foreground inline-flex items-center gap-1"
                                        >
                                            <component
                                                :is="
                                                    getProviderIcon(
                                                        file.provider_name,
                                                    )
                                                "
                                                class="size-3"
                                            />
                                            <span
                                                class="max-w-[140px] truncate"
                                                :title="file.account_alias"
                                                >{{ file.account_alias }}</span
                                            >
                                        </span>
                                    </td>
                                    <td
                                        class="text-muted-foreground px-4 py-3 text-right text-xs tabular-nums"
                                    >
                                        {{ formatBytes(file.size) }}
                                    </td>
                                    <td
                                        class="text-muted-foreground px-4 py-3 text-right text-xs"
                                    >
                                        {{ file.created_at_human }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div
                                            class="flex items-center justify-end gap-1.5"
                                        >
                                            <a
                                                v-if="file.is_accessible"
                                                :href="`/files/${file.id}/download`"
                                                class="border-border text-muted-foreground hover:bg-muted hover:text-foreground inline-flex size-8 items-center justify-center rounded-md border transition-colors"
                                                title="Unduh Berkas"
                                                aria-label="Unduh berkas"
                                            >
                                                <Download class="size-3.5" />
                                            </a>
                                            <Link
                                                :href="
                                                    file.folder_id
                                                        ? `/files?folder=${file.folder_id}`
                                                        : '/files'
                                                "
                                                class="border-border text-muted-foreground hover:bg-muted hover:text-foreground inline-flex size-8 items-center justify-center rounded-md border transition-colors"
                                                title="Buka di File Manager"
                                                aria-label="Buka di File Manager"
                                            >
                                                <ArrowUpRight
                                                    class="size-3.5"
                                                />
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile View: Compact clean card list (under sm) -->
                    <div class="divide-border block divide-y sm:hidden">
                        <div
                            v-for="file in recent_files"
                            :key="`m-${file.id}`"
                            class="space-y-2 p-4"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <component
                                        :is="
                                            getFileIcon(
                                                file.mime_type,
                                                file.name,
                                            )
                                        "
                                        class="size-4 shrink-0 text-teal-600 dark:text-teal-400"
                                    />
                                    <span
                                        class="text-foreground truncate text-sm font-medium"
                                    >
                                        {{ file.name }}
                                    </span>
                                </div>
                                <span
                                    class="text-muted-foreground shrink-0 text-xs tabular-nums"
                                >
                                    {{ formatBytes(file.size) }}
                                </span>
                            </div>

                            <div
                                class="text-muted-foreground flex items-center justify-between text-xs"
                            >
                                <span
                                    >{{ file.folder_name }} ·
                                    {{ file.account_alias }}</span
                                >
                                <span>{{ file.created_at_human }}</span>
                            </div>

                            <div
                                class="flex items-center justify-end gap-2 pt-1"
                            >
                                <a
                                    v-if="file.is_accessible"
                                    :href="`/files/${file.id}/download`"
                                    class="border-border text-foreground hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs font-medium"
                                >
                                    <Download class="size-3" />
                                    <span>Unduh</span>
                                </a>
                                <Link
                                    :href="
                                        file.folder_id
                                            ? `/files?folder=${file.folder_id}`
                                            : '/files'
                                    "
                                    class="border-border text-foreground hover:bg-muted inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-xs font-medium"
                                >
                                    <span>Buka</span>
                                    <ArrowUpRight class="size-3" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
