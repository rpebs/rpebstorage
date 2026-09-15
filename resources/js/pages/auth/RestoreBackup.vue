<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, RefreshCw } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Restore dari Backup',
        description:
            'Pulihkan metadata file, akun cloud, dan konfigurasi dari berkas arsip Anda',
    },
});

const form = useForm({
    backup_file: null as File | null,
    password: '',
});

const onFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        const file = target.files[0];
        if (file.size === 0) {
            form.setError(
                'backup_file',
                'Berkas backup yang dipilih kosong (0 bytes). Pastikan berkas terunduh sempurna.',
            );
            form.backup_file = null;
            return;
        }
        form.clearErrors('backup_file');
        form.backup_file = file;
    } else {
        form.backup_file = null;
    }
};

const submit = () => {
    form.post('/restore', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Restore dari Backup" />

    <div class="space-y-6">
        <Alert
            v-if="form.errors.password || form.errors.backup_file"
            variant="destructive"
            class="border-destructive/50 text-destructive bg-destructive/10"
        >
            <AlertTriangle class="size-4" />
            <AlertTitle>Pemulihan Gagal</AlertTitle>
            <AlertDescription>
                {{ form.errors.password || form.errors.backup_file }}
            </AlertDescription>
        </Alert>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <Label for="backup_file">Berkas Backup (.zip)</Label>
                <Input
                    id="backup_file"
                    type="file"
                    accept=".zip,application/zip,application/x-zip-compressed"
                    required
                    :tabindex="1"
                    @change="onFileChange"
                />
                <InputError :message="form.errors.backup_file" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Master Password Pengaman</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Masukkan master password arsip"
                />
                <InputError :message="form.errors.password" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full gap-2 bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-600 dark:hover:bg-teal-700"
                :tabindex="3"
                :disabled="form.processing"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <RefreshCw v-else class="size-4" />
                <span>{{
                    form.processing ? 'Memulihkan Sistem...' : 'Pulihkan Sistem'
                }}</span>
            </Button>
        </form>

        <div class="text-muted-foreground text-center text-sm">
            Sudah siap?
            <TextLink :href="login()">Kembali ke halaman login</TextLink>
        </div>
    </div>
</template>
