<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { postJson } from '@/lib/fetchJson';
import { Button } from '@/components/ui/button';
import {
    Dialog,
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
        label: 'Telegram',
        variant: 'outline',
        size: 'default',
    },
);

const open = ref(false);
const step = ref<'phone' | 'code'>('phone');
const phone = ref('');
const code = ref('');
const password = ref('');
const needsPassword = ref(false);
const error = ref('');
const busy = ref(false);

async function requestCode() {
    busy.value = true;
    error.value = '';

    try {
        await postJson('/accounts/telegram/start', { phone: phone.value });
        step.value = 'code';
    } catch (e: unknown) {
        error.value =
            e instanceof Error ? e.message : 'Gagal mengirim kode OTP.';
    } finally {
        busy.value = false;
    }
}

async function verify() {
    busy.value = true;
    error.value = '';

    try {
        const response = await postJson<{ status?: string }>(
            '/accounts/telegram/verify',
            {
                code: code.value,
                password: password.value || undefined,
            },
        );

        if (response.status === 'password_needed') {
            needsPassword.value = true;
            error.value =
                'Akun ini pakai verifikasi dua langkah. Masukkan password Telegram.';
            busy.value = false;
            return;
        }

        open.value = false;
        router.reload();
    } catch (e: unknown) {
        error.value = e instanceof Error ? e.message : 'Verifikasi gagal.';
    } finally {
        busy.value = false;
    }
}

function reset() {
    step.value = 'phone';
    phone.value = '';
    code.value = '';
    password.value = '';
    needsPassword.value = false;
    error.value = '';
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
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Hubungkan Telegram</DialogTitle>
                <DialogDescription>
                    Login pakai nomor Telegram kamu (MTProto). Kode OTP dikirim
                    ke aplikasi Telegram.
                </DialogDescription>
            </DialogHeader>

            <form
                v-if="step === 'phone'"
                class="space-y-4"
                @submit.prevent="requestCode"
            >
                <div class="grid gap-2">
                    <Label for="phone">Nomor telepon</Label>
                    <Input
                        id="phone"
                        v-model="phone"
                        type="tel"
                        placeholder="+6281234567890"
                        autocomplete="tel"
                        required
                    />
                </div>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                <DialogFooter>
                    <Button type="submit" :disabled="busy">
                        <Spinner v-if="busy" />
                        Kirim kode
                    </Button>
                </DialogFooter>
            </form>

            <form v-else class="space-y-4" @submit.prevent="verify">
                <div class="grid gap-2">
                    <Label for="code">Kode OTP</Label>
                    <Input
                        id="code"
                        v-model="code"
                        inputmode="numeric"
                        required
                        autofocus
                    />
                </div>
                <div v-if="needsPassword" class="grid gap-2">
                    <Label for="tg-password">Password 2FA Telegram</Label>
                    <Input
                        id="tg-password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                    />
                </div>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="busy"
                        @click="reset"
                    >
                        Ulangi
                    </Button>
                    <Button type="submit" :disabled="busy">
                        <Spinner v-if="busy" />
                        Verifikasi
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
