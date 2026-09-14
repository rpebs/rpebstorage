<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';

const props = withDefaults(
    defineProps<{
        label?: string;
        variant?:
            | 'default'
            | 'destructive'
            | 'outline'
            | 'secondary'
            | 'ghost'
            | 'link';
        size?: 'default' | 'sm' | 'lg' | 'icon';
    }>(),
    {
        label: 'Hubungkan MEGA',
        variant: 'outline',
        size: 'default',
    },
);

const open = ref(false);
const email = ref('');
const password = ref('');
const twoFactorCode = ref('');
const alias = ref('');
const error = ref('');
const busy = ref(false);

function submit() {
    if (!email.value || !password.value) {
        error.value = 'Email dan kata sandi MEGA wajib diisi.';
        return;
    }

    busy.value = true;
    error.value = '';

    router.post(
        '/accounts/mega/connect',
        {
            email: email.value,
            password: password.value,
            two_factor_code: twoFactorCode.value || undefined,
            alias: alias.value || undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                reset();
            },
            onError: (errors) => {
                error.value =
                    errors.email ||
                    errors.password ||
                    errors.two_factor_code ||
                    errors.alias ||
                    'Gagal menghubungkan akun MEGA. Periksa kredensial Anda.';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function reset() {
    email.value = '';
    password.value = '';
    twoFactorCode.value = '';
    alias.value = '';
    error.value = '';
    busy.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot :open="open" :reset="reset">
                <Button :variant="variant" :size="size" @click="reset">
                    {{ label }}
                </Button>
            </slot>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Hubungkan Akun MEGA</DialogTitle>
                <DialogDescription>
                    Masukkan kredensial akun MEGA Anda. Sistem mendukung akun
                    versi lama maupun baru (PBKDF2/v2) dan 2FA secara aman.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="mega-email">Email MEGA</Label>
                    <Input
                        id="mega-email"
                        v-model="email"
                        type="email"
                        placeholder="nama@email.com"
                        autocomplete="username"
                        required
                        :disabled="busy"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="mega-password">Kata Sandi MEGA</Label>
                    <Input
                        id="mega-password"
                        v-model="password"
                        type="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                        :disabled="busy"
                    />
                    <p class="text-muted-foreground text-[11px]">
                        Kata sandi hanya digunakan sekali saat verifikasi awal
                        untuk menurunkan kunci master sesi.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="mega-2fa"
                        >Kode 2FA / Authenticator (Opsional)</Label
                    >
                    <Input
                        id="mega-2fa"
                        v-model="twoFactorCode"
                        type="text"
                        placeholder="Contoh: 123456 (kosongkan bila tidak pakai 2FA)"
                        maxlength="10"
                        autocomplete="one-time-code"
                        :disabled="busy"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="mega-alias">Alias Akun (Opsional)</Label>
                    <Input
                        id="mega-alias"
                        v-model="alias"
                        type="text"
                        placeholder="Contoh: MEGA Pribadi, Drive Cadangan"
                        maxlength="100"
                        :disabled="busy"
                    />
                </div>

                <p v-if="error" class="text-xs text-red-600 dark:text-red-400">
                    {{ error }}
                </p>

                <DialogFooter class="gap-2 pt-2 sm:gap-0">
                    <DialogClose as-child>
                        <Button type="button" variant="ghost" :disabled="busy">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="busy"
                        class="gap-1.5 bg-teal-600 text-white hover:bg-teal-700"
                    >
                        <Spinner v-if="busy" class="h-4 w-4" />
                        <span>{{
                            busy ? 'Menghubungkan...' : 'Hubungkan Akun'
                        }}</span>
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
