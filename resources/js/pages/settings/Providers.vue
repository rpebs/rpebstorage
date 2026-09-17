<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, Info } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as backupIndex } from '@/routes/backup';
import {
    edit as editProviders,
    update as updateProviders,
} from '@/routes/providers';

type FieldSource = 'app' | 'env' | 'unset';

interface ProviderField {
    key: string;
    label: string;
    secret: boolean;
    value: string;
    env: string;
    source: FieldSource;
    hint: string;
    placeholder: string;
}

interface ProviderConfig {
    name: string;
    label: string;
    configured: boolean;
    oauth: boolean;
    redirect_uri: string | null;
    console_url: string;
    console_note: string;
    fields: ProviderField[];
}

const props = defineProps<{
    providers: ProviderConfig[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Kredensial Provider',
                href: editProviders(),
            },
        ],
    },
});

const form = useForm({
    settings: props.providers.flatMap((provider) =>
        provider.fields.map((field) => ({
            key: field.key,
            value: field.value,
        })),
    ),
});

function errorFor(key: string): string | undefined {
    const index = form.settings.findIndex((entry) => entry.key === key);

    if (index === -1) {
        return undefined;
    }

    const errors = form.errors as Record<string, string | undefined>;

    return errors[`settings.${index}.value`];
}

function save(): void {
    form.put(updateProviders().url, { preserveScroll: true });
}

// Pasangkan tiap field dengan posisinya di payload form, supaya v-model dan
// pesan error tetap menunjuk field yang benar walau urutan provider berubah.
const providerRows = computed(() =>
    props.providers.map((provider) => ({
        ...provider,
        fields: provider.fields.map((field) => ({
            ...field,
            index: form.settings.findIndex((entry) => entry.key === field.key),
        })),
    })),
);

const anyFromEnv = computed(() =>
    props.providers.some((provider) =>
        provider.fields.some((field) => field.source === 'env'),
    ),
);

const copiedUri = ref<string | null>(null);

async function copyRedirectUri(uri: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(uri);
        copiedUri.value = uri;
        window.setTimeout(() => {
            if (copiedUri.value === uri) {
                copiedUri.value = null;
            }
        }, 2000);
    } catch {
        copiedUri.value = null;
    }
}

const sourceLabel: Record<FieldSource, string> = {
    app: 'Disimpan di aplikasi',
    env: 'Masih dibaca dari .env',
    unset: 'Belum diisi',
};
</script>

<template>
    <Head title="Kredensial Provider" />

    <h1 class="sr-only">Kredensial Provider</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Kredensial Provider"
            description="Client ID, client secret, dan API Telegram disimpan di database aplikasi — secret dienkripsi dengan APP_KEY. Berkas .env tidak perlu diubah lagi."
        />

        <Alert v-if="anyFromEnv">
            <Info />
            <AlertTitle>Sebagian kredensial masih dibaca dari .env</AlertTitle>
            <AlertDescription>
                Isi nilainya di bawah lalu simpan untuk memindahkannya ke
                database aplikasi. Setelah tersimpan, nilai di .env diabaikan.
            </AlertDescription>
        </Alert>

        <Card v-for="provider in providerRows" :key="provider.name">
            <CardHeader>
                <div class="flex items-start justify-between gap-3">
                    <div class="space-y-1">
                        <CardTitle>{{ provider.label }}</CardTitle>
                        <CardDescription>
                            {{ provider.console_note }}
                        </CardDescription>
                    </div>
                    <Badge
                        v-if="provider.configured"
                        class="border-teal-200 bg-teal-50 text-teal-700 dark:border-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                    >
                        <Check />
                        Siap dipakai
                    </Badge>
                    <Badge
                        v-else
                        class="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                    >
                        Belum lengkap
                    </Badge>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <div v-if="provider.redirect_uri" class="space-y-2">
                    <Label :for="`redirect-${provider.name}`"
                        >Redirect URI</Label
                    >
                    <div class="flex items-center gap-2">
                        <Input
                            :id="`redirect-${provider.name}`"
                            :model-value="provider.redirect_uri"
                            readonly
                            class="font-mono text-xs"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="shrink-0 gap-1.5"
                            @click="copyRedirectUri(provider.redirect_uri)"
                        >
                            <Check
                                v-if="copiedUri === provider.redirect_uri"
                                class="h-3.5 w-3.5"
                            />
                            <Copy v-else class="h-3.5 w-3.5" />
                            {{
                                copiedUri === provider.redirect_uri
                                    ? 'Tersalin'
                                    : 'Salin'
                            }}
                        </Button>
                    </div>
                </div>

                <div
                    v-for="field in provider.fields"
                    :key="field.key"
                    class="space-y-2"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Label :for="field.key">{{ field.label }}</Label>
                        <Badge variant="outline">{{
                            sourceLabel[field.source]
                        }}</Badge>
                    </div>

                    <PasswordInput
                        v-if="field.secret"
                        :id="field.key"
                        v-model="form.settings[field.index].value"
                        :placeholder="field.placeholder"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <Input
                        v-else
                        :id="field.key"
                        v-model="form.settings[field.index].value"
                        :placeholder="field.placeholder"
                        autocomplete="off"
                        spellcheck="false"
                    />

                    <p class="text-muted-foreground text-[11px]">
                        {{ field.hint }} Kosongkan untuk menghapus nilai yang
                        tersimpan di aplikasi.
                    </p>
                    <InputError :message="errorFor(field.key)" />
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t pt-4"
                >
                    <a
                        :href="provider.console_url"
                        target="_blank"
                        rel="noreferrer"
                        class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-xs"
                    >
                        Buka console {{ provider.label }}
                        <ExternalLink class="h-3.5 w-3.5" />
                    </a>
                    <Button
                        type="button"
                        class="gap-1.5 bg-teal-600 text-white hover:bg-teal-700"
                        :disabled="form.processing"
                        @click="save"
                    >
                        <Spinner v-if="form.processing" class="h-4 w-4" />
                        <span>{{
                            form.processing ? 'Menyimpan...' : 'Simpan'
                        }}</span>
                    </Button>
                </div>
            </CardContent>
        </Card>

        <p class="text-muted-foreground text-xs">
            Kredensial ini ikut terbawa pada
            <Link :href="backupIndex()" class="underline">Backup & Restore</Link
            >, jadi perangkat baru tidak perlu mengisi ulang.
        </p>
    </div>
</template>