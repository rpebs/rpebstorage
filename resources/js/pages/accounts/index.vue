<script setup lang="ts">
import { computed, ref } from 'vue';
import { Form, Head, router } from '@inertiajs/vue3';
import { redirect as connectRedirect } from '@/actions/App/Http/Controllers/Auth/ProviderOAuthController';
import {
    destroy,
    index,
    update,
} from '@/actions/App/Http/Controllers/StorageAccountController';
import CapacityBar from '@/components/CapacityBar.vue';
import MegaConnect from '@/components/MegaConnect.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { Badge } from '@/components/ui/badge';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/format';
import {
    Archive,
    Cloud,
    Database,
    FolderInput,
    HardDrive,
    Info,
    Loader2,
    Pencil,
    Plus,
    Send,
    Server,
    Trash2,
} from '@lucide/vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Akun Storage',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    accounts: Array<{
        id: number;
        alias: string;
        provider: string;
        provider_label: string;
        status: string;
        quota_total: number | null;
        quota_used: number;
        file_count: number;
        supports_scan?: boolean;
        is_scanning?: boolean;
    }>;
    providers: Array<{
        name: string;
        label: string;
        connectable: boolean;
        credentials_missing: boolean;
    }>;
}>();

const scanningId = ref<number | null>(null);

function triggerScan(accountId: number) {
    scanningId.value = accountId;
    router.post(
        `/accounts/${accountId}/scan`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                scanningId.value = null;
            },
        },
    );
}

const statusLabel: Record<string, string> = {
    active: 'Aktif',
    expired: 'Kedaluwarsa',
    disconnected: 'Diputus',
};

const statusClass: Record<string, string> = {
    active: 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950/60 dark:text-teal-300 dark:border-teal-800',
    expired:
        'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
    disconnected:
        'bg-neutral-100 text-neutral-500 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:border-neutral-700',
};

function getProviderIcon(providerName: string) {
    switch (providerName) {
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

function getEnvSnippet(providerName: string): string[] {
    switch (providerName) {
        case 'google_drive':
            return ['GOOGLE_CLIENT_ID=...', 'GOOGLE_CLIENT_SECRET=...'];
        case 'dropbox':
            return ['DROPBOX_CLIENT_ID=...', 'DROPBOX_CLIENT_SECRET=...'];
        case 'onedrive':
            return ['MICROSOFT_CLIENT_ID=...', 'MICROSOFT_CLIENT_SECRET=...'];
        default:
            return [];
    }
}

const totalAccountsCount = computed(() => props.accounts.length);

const activeAccountsCount = computed(
    () => props.accounts.filter((a) => a.status === 'active').length,
);

const totalStorageUsed = computed(() =>
    props.accounts.reduce((sum, a) => sum + (a.quota_used || 0), 0),
);

const accountsWithQuota = computed(() =>
    props.accounts.filter((a) => a.quota_total !== null),
);

const totalStorageQuota = computed(() =>
    accountsWithQuota.value.reduce((sum, a) => sum + (a.quota_total || 0), 0),
);

const hasUnlimitedStorage = computed(() =>
    props.accounts.some((a) => a.quota_total === null),
);

const connectedProvidersCount = computed(() => {
    const set = new Set(props.accounts.map((a) => a.provider));
    return set.size;
});

const groupedProviders = computed(() => {
    return props.providers.map((provider) => {
        const providerAccounts = props.accounts.filter(
            (a) => a.provider === provider.name,
        );
        const totalUsed = providerAccounts.reduce(
            (sum, a) => sum + (a.quota_used || 0),
            0,
        );
        const hasUnlimited = providerAccounts.some(
            (a) => a.quota_total === null,
        );
        const totalQuota = hasUnlimited
            ? null
            : providerAccounts.reduce(
                  (sum, a) => sum + (a.quota_total || 0),
                  0,
              );
        const totalFiles = providerAccounts.reduce(
            (sum, a) => sum + (a.file_count || 0),
            0,
        );

        return {
            ...provider,
            accounts: providerAccounts,
            totalUsed,
            totalQuota,
            hasUnlimited,
            totalFiles,
        };
    });
});
</script>

<template>
    <Head title="Akun Storage" />
    <div class="mx-auto w-full max-w-5xl space-y-8 px-4 py-6 sm:px-6">
        <!-- Page Header -->
        <header class="space-y-1">
            <h1 class="text-foreground text-xl font-semibold">Akun Storage</h1>
            <p class="text-muted-foreground text-sm">
                Kelola akun cloud storage dan Telegram yang terhubung. Seluruh
                akun digabungkan ke dalam satu sistem penyimpanan virtual.
            </p>
        </header>

        <!-- Overview Stat Cards -->
        <section
            aria-label="Ringkasan akun storage"
            class="grid gap-4 sm:grid-cols-3"
        >
            <Card class="gap-2 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground text-xs font-medium"
                        >Provider Terhubung</span
                    >
                    <Server class="text-muted-foreground h-4 w-4" />
                </div>
                <div class="space-y-0.5">
                    <p
                        class="text-foreground text-xl font-semibold tabular-nums"
                    >
                        {{ connectedProvidersCount }} / {{ providers.length }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{
                            connectedProvidersCount > 0
                                ? `${connectedProvidersCount} provider aktif`
                                : 'Belum ada provider terhubung'
                        }}
                    </p>
                </div>
            </Card>

            <Card class="gap-2 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground text-xs font-medium"
                        >Akun Terdaftar</span
                    >
                    <HardDrive class="text-muted-foreground h-4 w-4" />
                </div>
                <div class="space-y-0.5">
                    <p
                        class="text-foreground text-xl font-semibold tabular-nums"
                    >
                        {{ totalAccountsCount }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ activeAccountsCount }} akun aktif siap digunakan
                    </p>
                </div>
            </Card>

            <Card class="gap-2 p-4">
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground text-xs font-medium"
                        >Total Kapasitas Terpakai</span
                    >
                    <Database class="text-muted-foreground h-4 w-4" />
                </div>
                <div class="space-y-0.5">
                    <p
                        class="text-foreground text-xl font-semibold tabular-nums"
                    >
                        {{ formatBytes(totalStorageUsed) }}
                    </p>
                    <p
                        class="text-muted-foreground truncate text-xs tabular-nums"
                    >
                        <template v-if="hasUnlimitedStorage">
                            {{
                                totalStorageQuota > 0
                                    ? `dari ${formatBytes(totalStorageQuota)} + Unlimited`
                                    : 'Kapasitas Unlimited (Telegram)'
                            }}
                        </template>
                        <template v-else-if="totalStorageQuota > 0">
                            dari {{ formatBytes(totalStorageQuota) }} kuota
                            total
                        </template>
                        <template v-else> Belum ada kuota teralokasi </template>
                    </p>
                </div>
            </Card>
        </section>

        <!-- Global Empty State Banner when no accounts at all -->
        <div
            v-if="accounts.length === 0"
            class="bg-muted/20 space-y-2 rounded-lg border border-dashed p-6 text-center"
        >
            <div
                class="bg-muted mx-auto flex h-10 w-10 items-center justify-center rounded-full"
            >
                <HardDrive class="text-muted-foreground h-5 w-5" />
            </div>
            <h2 class="text-foreground text-sm font-semibold">
                Belum ada akun storage yang terhubung
            </h2>
            <p class="text-muted-foreground mx-auto max-w-md text-xs">
                Mulai dengan menghubungkan akun Google Drive, Telegram,
                OneDrive, atau Dropbox pada kartu provider di bawah.
            </p>
        </div>

        <!-- Provider Grouping Sections -->
        <section aria-label="Daftar provider storage" class="space-y-6">
            <div
                v-for="provider in groupedProviders"
                :key="provider.name"
                class="bg-card text-card-foreground overflow-hidden rounded-lg border shadow-xs"
            >
                <!-- Provider Header -->
                <div
                    class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
                >
                    <div class="flex min-w-0 items-start gap-3 sm:items-center">
                        <div
                            class="bg-muted text-foreground flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                        >
                            <component
                                :is="getProviderIcon(provider.name)"
                                class="h-5 w-5"
                            />
                        </div>
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2
                                    class="text-foreground truncate text-base font-semibold"
                                >
                                    {{ provider.label }}
                                </h2>
                                <Badge
                                    v-if="provider.accounts.length > 0"
                                    variant="outline"
                                    class="border-teal-200 bg-teal-50 text-xs font-normal text-teal-700 dark:border-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                                >
                                    {{ provider.accounts.length }} Akun
                                    Terhubung
                                </Badge>
                                <Badge
                                    v-else-if="provider.credentials_missing"
                                    variant="outline"
                                    class="border-amber-200 bg-amber-50 text-xs font-normal text-amber-700 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                >
                                    Perlu Kredensial .env
                                </Badge>
                                <Badge
                                    v-else
                                    variant="outline"
                                    class="text-muted-foreground text-xs font-normal"
                                >
                                    Belum Terhubung
                                </Badge>
                            </div>
                            <p class="text-muted-foreground text-xs">
                                <template v-if="provider.accounts.length > 0">
                                    {{ formatBytes(provider.totalUsed) }}
                                    terpakai · {{ provider.totalFiles }} berkas
                                    tersimpan
                                </template>
                                <template
                                    v-else-if="provider.credentials_missing"
                                >
                                    Client ID dan Secret belum dikonfigurasi di
                                    file .env
                                </template>
                                <template v-else>
                                    Siap dihubungkan ke sistem virtual storage
                                </template>
                            </p>
                        </div>
                    </div>

                    <!-- Connect action button in header -->
                    <div
                        class="flex shrink-0 items-center gap-2 self-start sm:self-center"
                    >
                        <template v-if="provider.name === 'telegram'">
                            <TelegramConnect label="Tambah Akun" size="sm">
                                <template #default="{ reset }">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        @click="reset"
                                    >
                                        <Plus class="h-3.5 w-3.5" />
                                        <span>Tambah Akun</span>
                                    </Button>
                                </template>
                            </TelegramConnect>
                        </template>

                        <template v-else-if="provider.name === 'mega'">
                            <MegaConnect label="Tambah Akun" size="sm">
                                <template #default="{ reset }">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        @click="reset"
                                    >
                                        <Plus class="h-3.5 w-3.5" />
                                        <span>Tambah Akun</span>
                                    </Button>
                                </template>
                            </MegaConnect>
                        </template>

                        <template v-else-if="provider.connectable">
                            <Button
                                variant="outline"
                                size="sm"
                                class="gap-1.5"
                                as-child
                            >
                                <a
                                    :href="
                                        connectRedirect({
                                            provider: provider.name,
                                        }).url
                                    "
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                    <span>Tambah Akun</span>
                                </a>
                            </Button>
                        </template>

                        <template v-else>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled
                                class="cursor-not-allowed text-xs opacity-60"
                                title="Atur kredensial provider ini di berkas .env"
                            >
                                Kredensial Belum Diatur
                            </Button>
                        </template>
                    </div>
                </div>

                <!-- Provider Capacity Summary Bar (when accounts exist) -->
                <div
                    v-if="provider.accounts.length > 0"
                    class="bg-muted/20 flex flex-col justify-between gap-3 border-t px-4 py-3 text-xs sm:flex-row sm:items-center sm:px-5"
                >
                    <div class="flex-1 space-y-1 sm:max-w-md">
                        <div
                            class="flex items-center justify-between font-medium"
                        >
                            <span class="text-muted-foreground"
                                >Kapasitas Gabungan Provider</span
                            >
                            <span class="text-foreground tabular-nums">
                                {{ formatBytes(provider.totalUsed) }} /
                                {{
                                    provider.hasUnlimited
                                        ? 'unlimited'
                                        : formatBytes(provider.totalQuota)
                                }}
                            </span>
                        </div>
                        <CapacityBar
                            :used="provider.totalUsed"
                            :total="provider.totalQuota"
                            :hide-label="true"
                        />
                    </div>
                    <div class="text-muted-foreground shrink-0 tabular-nums">
                        Total {{ provider.totalFiles }} berkas di provider ini
                    </div>
                </div>

                <!-- Accounts List for this provider -->
                <div
                    v-if="provider.accounts.length > 0"
                    class="divide-y border-t"
                >
                    <div
                        v-for="account in provider.accounts"
                        :key="account.id"
                        class="hover:bg-muted/10 flex flex-col gap-4 p-4 transition-colors sm:p-5 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <!-- Account Details -->
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="text-foreground max-w-xs truncate text-sm font-medium"
                                >
                                    {{ account.alias }}
                                </span>
                                <Badge
                                    variant="outline"
                                    :class="statusClass[account.status]"
                                    class="text-xs font-normal"
                                >
                                    {{ statusLabel[account.status] }}
                                </Badge>
                            </div>
                            <p
                                class="text-muted-foreground text-xs tabular-nums"
                            >
                                {{ account.file_count }} berkas tersimpan
                            </p>
                        </div>

                        <!-- Capacity Bar -->
                        <div class="w-full shrink-0 sm:max-w-xs lg:w-72">
                            <CapacityBar
                                :used="account.quota_used"
                                :total="account.quota_total"
                            />
                        </div>

                        <!-- Actions -->
                        <div
                            class="flex shrink-0 items-center gap-2 self-end lg:self-center"
                        >
                            <!-- Pindai Berkas Dialog -->
                            <Dialog
                                v-if="
                                    account.status === 'active' &&
                                    account.supports_scan !== false
                                "
                            >
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-muted-foreground hover:text-foreground h-8 gap-1.5 text-xs"
                                        :disabled="
                                            account.is_scanning ||
                                            scanningId === account.id
                                        "
                                    >
                                        <Loader2
                                            v-if="
                                                account.is_scanning ||
                                                scanningId === account.id
                                            "
                                            class="h-3.5 w-3.5 animate-spin text-teal-600 dark:text-teal-400"
                                        />
                                        <FolderInput
                                            v-else
                                            class="h-3.5 w-3.5"
                                        />
                                        <span>{{
                                            account.is_scanning ||
                                            scanningId === account.id
                                                ? 'Memindai...'
                                                : 'Pindai berkas'
                                        }}</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle
                                            >Pindai & impor berkas</DialogTitle
                                        >
                                        <DialogDescription>
                                            Sistem akan membaca berkas dan
                                            folder yang ada di akun
                                            <strong>{{ account.alias }}</strong
                                            >, lalu menempatkannya ke dalam
                                            folder virtual
                                            <code
                                                >[{{ account.provider_label }} -
                                                {{ account.alias }}]</code
                                            >.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div
                                        class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400"
                                    >
                                        Pemindaian berjalan di latar belakang.
                                        Berkas yang sudah ada akan diperbarui
                                        tanpa membuat duplikat.
                                    </div>
                                    <DialogFooter class="gap-2 sm:gap-0">
                                        <DialogClose as-child>
                                            <Button variant="ghost"
                                                >Batal</Button
                                            >
                                        </DialogClose>
                                        <DialogClose as-child>
                                            <Button
                                                class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-600 dark:hover:bg-teal-500"
                                                @click="triggerScan(account.id)"
                                            >
                                                Mulai Pemindaian
                                            </Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <!-- Ganti Alias Dialog -->
                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 gap-1.5 text-xs"
                                    >
                                        <Pencil
                                            class="text-muted-foreground h-3.5 w-3.5"
                                        />
                                        <span>Ganti alias</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <Form
                                        v-bind="
                                            update.form({ account: account.id })
                                        "
                                        :options="{ preserveScroll: true }"
                                        class="space-y-6"
                                        v-slot="{ errors, processing }"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Ganti alias akun</DialogTitle
                                            >
                                            <DialogDescription>
                                                Nama alias yang muncul di daftar
                                                akun dan pilihan tujuan upload.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div class="grid gap-2">
                                            <Label :for="`alias-${account.id}`"
                                                >Alias Akun</Label
                                            >
                                            <Input
                                                :id="`alias-${account.id}`"
                                                name="alias"
                                                :default-value="account.alias"
                                                required
                                                maxlength="100"
                                                placeholder="Contoh: Akun Pribadi, Drive Kerja"
                                            />
                                            <p
                                                v-if="errors.alias"
                                                class="text-sm text-red-600"
                                            >
                                                {{ errors.alias }}
                                            </p>
                                        </div>
                                        <DialogFooter>
                                            <DialogClose as-child>
                                                <Button variant="ghost"
                                                    >Batal</Button
                                                >
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                :disabled="processing"
                                            >
                                                {{
                                                    processing
                                                        ? 'Menyimpan...'
                                                        : 'Simpan Perubahan'
                                                }}
                                            </Button>
                                        </DialogFooter>
                                    </Form>
                                </DialogContent>
                            </Dialog>

                            <!-- Putuskan Dialog -->
                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 gap-1.5 text-xs text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                        <span>Putuskan</span>
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <Form
                                        v-bind="
                                            destroy.form({
                                                account: account.id,
                                            })
                                        "
                                        :options="{ preserveScroll: true }"
                                        class="space-y-6"
                                        v-slot="{ processing }"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Putuskan
                                                {{
                                                    account.alias
                                                }}?</DialogTitle
                                            >
                                            <DialogDescription>
                                                Token atau sesi autentikasi akan
                                                dihapus dari server. Berkas yang
                                                sudah tersimpan di akun ini
                                                tetap tercatat, tetapi tidak
                                                dapat diunduh atau dihapus lagi
                                                melalui aplikasi ini.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose as-child>
                                                <Button variant="ghost"
                                                    >Batal</Button
                                                >
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                :disabled="processing"
                                            >
                                                {{
                                                    processing
                                                        ? 'Memutuskan...'
                                                        : 'Putuskan Akun'
                                                }}
                                            </Button>
                                        </DialogFooter>
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                </div>

                <!-- Provider Empty State (when 0 accounts for this provider) -->
                <div v-else class="border-t p-5">
                    <div
                        v-if="provider.connectable"
                        class="bg-muted/20 space-y-3 rounded-lg border border-dashed p-6 text-center"
                    >
                        <p class="text-foreground text-sm font-medium">
                            Belum ada akun {{ provider.label }} yang terhubung
                        </p>
                        <p
                            class="text-muted-foreground mx-auto max-w-md text-xs"
                        >
                            Hubungkan akun {{ provider.label }} Anda untuk
                            menambah kapasitas ruang penyimpanan virtual.
                        </p>
                        <div>
                            <TelegramConnect
                                v-if="provider.name === 'telegram'"
                                label="Hubungkan Telegram"
                                size="sm"
                            >
                                <template #default="{ reset }">
                                    <Button
                                        size="sm"
                                        class="gap-1.5 bg-teal-600 text-white hover:bg-teal-700"
                                        @click="reset"
                                    >
                                        <Plus class="h-3.5 w-3.5" />
                                        <span>Hubungkan Telegram</span>
                                    </Button>
                                </template>
                            </TelegramConnect>
                            <MegaConnect
                                v-else-if="provider.name === 'mega'"
                                label="Hubungkan MEGA"
                                size="sm"
                            >
                                <template #default="{ reset }">
                                    <Button
                                        size="sm"
                                        class="gap-1.5 bg-teal-600 text-white hover:bg-teal-700"
                                        @click="reset"
                                    >
                                        <Plus class="h-3.5 w-3.5" />
                                        <span>Hubungkan MEGA</span>
                                    </Button>
                                </template>
                            </MegaConnect>
                            <Button
                                v-else
                                size="sm"
                                class="gap-1.5 bg-teal-600 text-white hover:bg-teal-700"
                                as-child
                            >
                                <a
                                    :href="
                                        connectRedirect({
                                            provider: provider.name,
                                        }).url
                                    "
                                >
                                    <Plus class="h-3.5 w-3.5" />
                                    <span>Hubungkan {{ provider.label }}</span>
                                </a>
                            </Button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="space-y-3 rounded-lg border border-dashed border-amber-200 bg-amber-50/50 p-5 dark:border-amber-800/60 dark:bg-amber-950/20"
                    >
                        <div class="flex items-start gap-2.5">
                            <Info
                                class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
                            />
                            <div class="space-y-1 text-xs">
                                <p
                                    class="font-medium text-amber-900 dark:text-amber-200"
                                >
                                    Kredensial OAuth belum dikonfigurasi
                                </p>
                                <p
                                    class="leading-relaxed text-amber-700 dark:text-amber-300/90"
                                >
                                    Untuk menghubungkan akun
                                    {{ provider.label }}, lengkapi variabel
                                    berikut di dalam berkas
                                    <code
                                        class="rounded bg-amber-100 px-1 py-0.5 font-mono text-[11px] dark:bg-amber-900/60"
                                        >.env</code
                                    >:
                                </p>
                            </div>
                        </div>

                        <div
                            class="space-y-1 overflow-x-auto rounded bg-neutral-900 p-3 font-mono text-xs text-neutral-100"
                        >
                            <div
                                v-for="line in getEnvSnippet(provider.name)"
                                :key="line"
                            >
                                {{ line }}
                            </div>
                        </div>

                        <p class="text-muted-foreground text-[11px]">
                            Setelah mengisi berkas
                            <code class="font-mono">.env</code>, jalankan
                            <code class="bg-muted rounded px-1 py-0.5 font-mono"
                                >php artisan config:clear</code
                            >
                            di terminal.
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
