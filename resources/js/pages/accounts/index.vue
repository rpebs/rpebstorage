<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { redirect as connectRedirect } from '@/actions/App/Http/Controllers/Auth/ProviderOAuthController';
import { destroy, index, update } from '@/actions/App/Http/Controllers/StorageAccountController';
import CapacityBar from '@/components/CapacityBar.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
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
import { Label } from '@/components/ui/label';

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

defineProps<{
    accounts: Array<{
        id: number;
        alias: string;
        provider: string;
        provider_label: string;
        status: string;
        quota_total: number | null;
        quota_used: number;
        file_count: number;
    }>;
    providers: Array<{
        name: string;
        label: string;
        connectable: boolean;
        credentials_missing: boolean;
    }>;
}>();

const statusLabel: Record<string, string> = {
    active: 'Aktif',
    expired: 'Kedaluwarsa',
    disconnected: 'Diputus',
};

const statusClass: Record<string, string> = {
    active: 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950 dark:text-teal-300 dark:border-teal-800',
    expired: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-800',
    disconnected: 'bg-neutral-100 text-neutral-500 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:border-neutral-700',
};
</script>

<template>
    <Head title="Akun Storage" />
    <div class="mx-auto w-full max-w-3xl space-y-8 px-4 py-6">
        <header class="space-y-1">
            <h1 class="text-lg font-semibold">Akun Storage</h1>
            <p class="text-muted-foreground text-sm">
                Hubungkan satu atau lebih akun per provider. Semua akun gabung jadi satu filesystem virtual.
            </p>
        </header>

        <section aria-label="Hubungkan provider" class="space-y-3">
            <h2 class="text-sm font-medium">Hubungkan provider</h2>
            <div class="flex flex-wrap gap-2">
                <template v-for="provider in providers" :key="provider.name">
                    <TelegramConnect v-if="provider.name === 'telegram'" />
                    <Button v-else variant="outline" as-child>
                        <a :href="connectRedirect({ provider: provider.name }).url">{{ provider.label }}</a>
                    </Button>
                </template>
            </div>
        </section>

        <section v-if="accounts.length === 0" class="rounded-lg border border-dashed p-8 text-center">
            <p class="font-medium">Belum ada akun terhubung</p>
            <p class="text-muted-foreground mt-1 text-sm">
                Hubungkan salah satu provider di atas untuk mulai mengunggah file.
            </p>
        </section>

        <section v-else aria-label="Daftar akun" class="space-y-3">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-medium">{{ account.alias }}</p>
                        <Badge variant="outline" :class="statusClass[account.status]">
                            {{ statusLabel[account.status] }}
                        </Badge>
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{ account.provider_label }} · {{ account.file_count }} file
                    </p>
                    <CapacityBar :used="account.quota_used" :total="account.quota_total" />
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="ghost" size="sm">Ganti alias</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="update.form({ account: account.id })"
                                :options="{ preserveScroll: true }"
                                class="space-y-6"
                                v-slot="{ errors, processing }"
                            >
                                <DialogHeader>
                                    <DialogTitle>Ganti alias akun</DialogTitle>
                                    <DialogDescription>
                                        Nama yang muncul di daftar akun dan pilihan tujuan upload.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Label for="alias">Alias</Label>
                                    <Input id="alias" name="alias" :default-value="account.alias" required maxlength="100" />
                                    <p v-if="errors.alias" class="text-sm text-red-600">{{ errors.alias }}</p>
                                </div>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="ghost">Batal</Button>
                                    </DialogClose>
                                    <Button type="submit" :disabled="processing">Simpan</Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button variant="ghost" size="sm" class="text-red-600 hover:text-red-600">Putuskan</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="destroy.form({ account: account.id })"
                                :options="{ preserveScroll: true }"
                                class="space-y-6"
                                v-slot="{ processing }"
                            >
                                <DialogHeader>
                                    <DialogTitle>Putuskan {{ account.alias }}?</DialogTitle>
                                    <DialogDescription>
                                        Token atau session dihapus dari server. File yang sudah tersimpan di akun ini
                                        tetap tercatat tapi tidak bisa diunduh atau dihapus lagi dari sini.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button variant="ghost">Batal</Button>
                                    </DialogClose>
                                    <Button type="submit" variant="destructive" :disabled="processing">
                                        Putuskan akun
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </section>
    </div>
</template>
