<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    FolderInput,
    Folder as FolderIcon,
    FolderPlus,
    Download,
    File as FileIcon,
    Search,
    Trash2,
    Upload,
} from '@lucide/vue';
import { destroy as destroyFile } from '@/actions/App/Http/Controllers/FileManagerController';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes } from '@/lib/format';
import { useUploader } from '@/composables/useUploader';

const props = defineProps<{
    folder: { id: number; name: string; parent_id: number | null } | null;
    breadcrumb: Array<{ id: number; name: string }>;
    folders: Array<{ id: number; name: string; parent_id: number | null }>;
    files: Array<{
        id: number;
        name: string;
        size: number;
        mime_type: string | null;
        is_chunked: boolean;
        account_label: string;
        accessible: boolean;
    }>;
    allFolders: Array<{ id: number; name: string; parent_id: number | null }>;
    accounts: Array<{ id: number; label: string; unlimited: boolean }>;
    search: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Files', href: '/files' }],
    },
});

const searchInput = ref(props.search);
const isDragging = ref(false);
const dragDepth = ref(0);
const targetAccountId = ref<string>('auto');
const fileInput = ref<HTMLInputElement | null>(null);

const { items, serverJobs, uploadingCount, add, startPolling, stopPolling } =
    useUploader();

const activeJobs = computed(() =>
    serverJobs.value.filter(
        (j) => j.status === 'pending' || j.status === 'processing',
    ),
);
const recentJobs = computed(() =>
    serverJobs.value.filter(
        (j) => j.status === 'done' || j.status === 'failed',
    ),
);

const panelVisible = computed(
    () =>
        items.value.length > 0 ||
        activeJobs.value.length > 0 ||
        recentJobs.value.length > 0,
);

// Poll the server job queue whenever uploads are in flight.
watch(
    () => uploadingCount() + activeJobs.value.length,
    (count) => (count > 0 ? startPolling() : stopPolling()),
    { immediate: true },
);

function submitFiles(files: FileList | File[] | null): void {
    if (!files || files.length === 0) {
        return;
    }

    add(
        Array.from(files),
        props.folder?.id ?? null,
        targetAccountId.value === 'auto' ? null : Number(targetAccountId.value),
    );
}

function onDrop(event: DragEvent): void {
    dragDepth.value = 0;
    isDragging.value = false;
    submitFiles(event.dataTransfer?.files ?? null);
}

function navigateToFolder(id: number | null): void {
    router.get('/files', id ? { folder: id } : {}, { preserveState: true });
}

const searchForm = useForm({ q: props.search });

function submitSearch(): void {
    searchForm.get('/files', { preserveState: true });
}

const newFolderOpen = ref(false);
const newFolderForm = useForm({
    name: '',
    parent_id: props.folder?.id ?? null,
});

function createFolder(): void {
    newFolderForm.parent_id = props.folder?.id ?? null;
    newFolderForm.post('/files/folders', {
        preserveScroll: true,
        onSuccess: () => {
            newFolderForm.reset('name');
            newFolderOpen.value = false;
        },
    });
}

const moveTarget = ref<string>('root');
const movingFile = ref<{ id: number; name: string } | null>(null);

function openMove(file: { id: number; name: string }): void {
    movingFile.value = file;
    moveTarget.value = props.folder ? String(props.folder.id) : 'root';
}

function submitMove(): void {
    if (!movingFile.value) {
        return;
    }

    router.patch(
        `/files/${movingFile.value.id}/move`,
        {
            folder_id:
                moveTarget.value === 'root' ? null : Number(moveTarget.value),
        },
        { preserveScroll: true, onSuccess: () => (movingFile.value = null) },
    );
}

const deletingFile = ref<{ id: number; name: string } | null>(null);

function submitDelete(): void {
    if (!deletingFile.value) {
        return;
    }

    router.delete(`/files/${deletingFile.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (deletingFile.value = null),
    });
}

const renamingFolder = ref<{ id: number; name: string } | null>(null);
const renameForm = useForm({ name: '' });

function submitRenameFolder(): void {
    if (!renamingFolder.value) {
        return;
    }

    router.patch(
        `/files/folders/${renamingFolder.value.id}`,
        { name: renameForm.name },
        {
            preserveScroll: true,
            onSuccess: () => (renamingFolder.value = null),
        },
    );
}

const deletingFolder = ref<{ id: number; name: string } | null>(null);

function submitDeleteFolder(): void {
    if (!deletingFolder.value) {
        return;
    }

    router.delete(`/files/folders/${deletingFolder.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (deletingFolder.value = null),
    });
}

function folderLabel(id: number | null): string {
    if (id === null) {
        return 'Root';
    }
    const index = props.allFolders.findIndex((f) => f.id === id);
    return index >= 0 ? props.allFolders[index].name : `Folder #${id}`;
}

const isEmpty = computed(
    () =>
        props.folders.length === 0 &&
        props.files.length === 0 &&
        props.search === '',
);
const searchEmpty = computed(
    () => props.files.length === 0 && props.search !== '',
);
</script>

<template>
    <Head title="Files" />

    <div
        class="relative flex h-full flex-1 flex-col"
        @dragenter.prevent="
            dragDepth++;
            isDragging = true;
        "
        @dragover.prevent
        @dragleave.prevent="
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) isDragging = false;
        "
        @drop.prevent="onDrop"
    >
        <div class="mx-auto w-full max-w-5xl space-y-4 px-4 py-6">
            <div
                class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
            >
                <nav
                    aria-label="Breadcrumb"
                    class="flex min-w-0 flex-wrap items-center gap-1 text-sm"
                >
                    <Link
                        href="/files"
                        class="hover:underline"
                        :class="{ 'font-medium': !folder }"
                        >Root</Link
                    >
                    <template v-for="crumb in breadcrumb" :key="crumb.id">
                        <span class="text-muted-foreground">/</span>
                        <Link
                            :href="`/files?folder=${crumb.id}`"
                            class="hover:underline"
                            :class="{ 'font-medium': crumb.id === folder?.id }"
                        >
                            {{ crumb.name }}
                        </Link>
                    </template>
                </nav>

                <form
                    class="flex items-center gap-2"
                    @submit.prevent="submitSearch"
                >
                    <div class="relative">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-2.5 left-2.5 h-4 w-4"
                        />
                        <Input
                            v-model="searchInput"
                            type="search"
                            placeholder="Cari file"
                            class="w-48 pl-8"
                            aria-label="Cari file berdasarkan nama"
                            @input="searchForm.q = searchInput"
                        />
                    </div>
                    <Button type="submit" variant="ghost" size="sm"
                        >Cari</Button
                    >
                </form>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="sr-only"
                    aria-label="Pilih file untuk diunggah"
                    @change="
                        submitFiles(($event.target as HTMLInputElement).files)
                    "
                />
                <Button size="sm" @click="fileInput?.click()">
                    <Upload class="h-4 w-4" />
                    Unggah
                </Button>

                <Dialog v-model:open="newFolderOpen">
                    <DialogTrigger as-child>
                        <Button size="sm" variant="outline">
                            <FolderPlus class="h-4 w-4" />
                            Folder baru
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <form class="space-y-6" @submit.prevent="createFolder">
                            <DialogHeader>
                                <DialogTitle>Folder baru</DialogTitle>
                                <DialogDescription>
                                    Folder virtual, tidak dibuat di provider
                                    asli.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="folder-name">Nama folder</Label>
                                <Input
                                    id="folder-name"
                                    v-model="newFolderForm.name"
                                    required
                                    maxlength="120"
                                />
                                <p
                                    v-if="newFolderForm.errors.name"
                                    class="text-sm text-red-600"
                                >
                                    {{ newFolderForm.errors.name }}
                                </p>
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="ghost"
                                        >Batal</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="submit"
                                    :disabled="newFolderForm.processing"
                                    >Buat</Button
                                >
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <Select v-model="targetAccountId">
                    <SelectTrigger class="w-56" aria-label="Akun tujuan upload">
                        <SelectValue placeholder="Akun tujuan" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="auto"
                            >Otomatis (sisa kuota terbesar)</SelectItem
                        >
                        <SelectItem
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="panelVisible"
                class="rounded-lg border p-3"
                aria-live="polite"
            >
                <p class="mb-2 text-xs font-medium">Transfer</p>
                <ul class="space-y-1.5 text-sm">
                    <li
                        v-for="item in items"
                        :key="item.id"
                        class="flex items-center gap-3"
                    >
                        <Upload
                            class="text-muted-foreground h-3.5 w-3.5 shrink-0"
                        />
                        <span class="w-48 truncate">{{ item.name }}</span>
                        <div
                            class="bg-muted h-1 w-40 overflow-hidden rounded-full"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    item.status === 'failed'
                                        ? 'bg-red-500'
                                        : 'bg-teal-600 dark:bg-teal-400'
                                "
                                :style="{
                                    width: `${item.status === 'done' ? 100 : item.progress}%`,
                                }"
                            />
                        </div>
                        <span class="text-muted-foreground text-xs">
                            {{
                                item.status === 'uploading'
                                    ? `${item.progress}%`
                                    : item.status === 'done'
                                      ? 'terkirim ke antrean'
                                      : item.error
                            }}
                        </span>
                    </li>
                    <li
                        v-for="job in activeJobs"
                        :key="job.id"
                        class="flex items-center gap-3"
                    >
                        <Upload
                            class="text-muted-foreground h-3.5 w-3.5 shrink-0"
                        />
                        <span class="w-48 truncate">{{ job.name }}</span>
                        <div
                            class="bg-muted h-1 w-40 overflow-hidden rounded-full"
                        >
                            <div
                                class="h-full rounded-full bg-teal-600 dark:bg-teal-400"
                                :style="{
                                    width: `${Math.max(5, job.progress)}%`,
                                }"
                            />
                        </div>
                        <span class="text-muted-foreground text-xs"
                            >memproses di server {{ job.progress }}%</span
                        >
                    </li>
                    <li
                        v-for="job in recentJobs"
                        :key="job.id"
                        class="flex items-center gap-3"
                    >
                        <Upload
                            class="text-muted-foreground h-3.5 w-3.5 shrink-0"
                        />
                        <span class="w-48 truncate">{{ job.name }}</span>
                        <span
                            class="text-xs"
                            :class="
                                job.status === 'done'
                                    ? 'text-teal-600 dark:text-teal-400'
                                    : 'text-red-600 dark:text-red-400'
                            "
                        >
                            {{
                                job.status === 'done'
                                    ? 'selesai'
                                    : `gagal: ${job.error}`
                            }}
                        </span>
                    </li>
                </ul>
            </div>

            <div
                v-if="isEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <p class="font-medium">
                    {{
                        folder
                            ? `Folder "${folder.name}" kosong`
                            : 'Root kosong'
                    }}
                </p>
                <p class="text-muted-foreground mt-1 text-sm">
                    Tarik file ke sini, atau klik Unggah. Pilih akun tujuan di
                    dropdown, atau biarkan otomatis.
                </p>
                <Button size="sm" class="mt-4" @click="fileInput?.click()">
                    <Upload class="h-4 w-4" />
                    Pilih file
                </Button>
            </div>

            <div
                v-else-if="searchEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <p class="font-medium">Tidak ada file bernama "{{ search }}"</p>
                <Link
                    href="/files"
                    class="text-sm text-teal-700 hover:underline dark:text-teal-400"
                    >Kembali ke daftar</Link
                >
            </div>

            <div v-else class="overflow-hidden rounded-lg border">
                <ul class="divide-y">
                    <li
                        v-for="item in folders"
                        :key="`f-${item.id}`"
                        class="hover:bg-muted/50 flex items-center gap-3 px-4 py-2.5"
                    >
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-3 text-left"
                            @click="navigateToFolder(item.id)"
                        >
                            <FolderIcon
                                class="h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate text-sm">{{
                                item.name
                            }}</span>
                        </button>
                        <div class="flex shrink-0 items-center gap-1">
                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-11 w-11"
                                        aria-label="Ganti nama folder"
                                        @click="
                                            renamingFolder = {
                                                id: item.id,
                                                name: item.name,
                                            };
                                            renameForm.name = item.name;
                                        "
                                    >
                                        <FolderInput class="h-4 w-4" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <form
                                        class="space-y-6"
                                        @submit.prevent="submitRenameFolder"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Ganti nama folder</DialogTitle
                                            >
                                        </DialogHeader>
                                        <div class="grid gap-2">
                                            <Label for="rename-folder"
                                                >Nama</Label
                                            >
                                            <Input
                                                id="rename-folder"
                                                v-model="renameForm.name"
                                                required
                                                maxlength="120"
                                            />
                                        </div>
                                        <DialogFooter>
                                            <DialogClose as-child
                                                ><Button
                                                    type="button"
                                                    variant="ghost"
                                                    >Batal</Button
                                                ></DialogClose
                                            >
                                            <Button
                                                type="submit"
                                                :disabled="
                                                    renameForm.processing
                                                "
                                                >Simpan</Button
                                            >
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>

                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-11 w-11 text-red-600 hover:text-red-600"
                                        aria-label="Hapus folder"
                                        @click="
                                            deletingFolder = {
                                                id: item.id,
                                                name: item.name,
                                            }
                                        "
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <form
                                        class="space-y-6"
                                        @submit.prevent="submitDeleteFolder"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Hapus folder
                                                {{
                                                    deletingFolder?.name
                                                }}?</DialogTitle
                                            >
                                            <DialogDescription>
                                                Folder harus kosong (tanpa file
                                                dan subfolder) sebelum bisa
                                                dihapus.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose as-child
                                                ><Button
                                                    type="button"
                                                    variant="ghost"
                                                    >Batal</Button
                                                ></DialogClose
                                            >
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                >Hapus</Button
                                            >
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </li>

                    <li
                        v-for="file in files"
                        :key="`file-${file.id}`"
                        class="hover:bg-muted/50 flex items-center gap-3 px-4 py-2.5"
                    >
                        <FileIcon
                            class="text-muted-foreground h-4 w-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span
                            class="min-w-0 flex-1 truncate text-sm"
                            :title="file.name"
                        >
                            {{ file.name }}
                            <span
                                v-if="!file.accessible"
                                class="text-red-600 dark:text-red-400"
                                >(akun diputus)</span
                            >
                        </span>
                        <span
                            class="text-muted-foreground hidden w-20 text-right text-xs sm:block"
                        >
                            {{ formatBytes(file.size) }}
                        </span>
                        <span
                            class="text-muted-foreground hidden w-48 truncate text-xs lg:block"
                        >
                            {{ file.account_label }}
                        </span>
                        <div class="flex shrink-0 items-center gap-1">
                            <Button
                                v-if="file.accessible"
                                variant="ghost"
                                size="icon"
                                class="h-11 w-11"
                                as-child
                            >
                                <a
                                    :href="`/files/${file.id}/download`"
                                    :aria-label="`Unduh ${file.name}`"
                                >
                                    <Download class="h-4 w-4" />
                                </a>
                            </Button>
                            <span
                                v-else
                                class="text-muted-foreground px-1 text-xs"
                                title="Unduh tidak tersedia: akun storage diputus"
                            >
                                tidak bisa diunduh
                            </span>

                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-11 w-11"
                                        :aria-label="`Pindahkan ${file.name}`"
                                        @click="openMove(file)"
                                    >
                                        <FolderInput class="h-4 w-4" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <form
                                        class="space-y-6"
                                        @submit.prevent="submitMove"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Pindahkan
                                                {{
                                                    movingFile?.name
                                                }}</DialogTitle
                                            >
                                            <DialogDescription>
                                                Hanya lokasi virtual yang
                                                berubah, file tidak diunggah
                                                ulang.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div class="grid gap-2">
                                            <Label for="move-target"
                                                >Folder tujuan</Label
                                            >
                                            <Select v-model="moveTarget">
                                                <SelectTrigger
                                                    ><SelectValue
                                                /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="root"
                                                        >Root</SelectItem
                                                    >
                                                    <SelectItem
                                                        v-for="f in allFolders"
                                                        :key="f.id"
                                                        :value="String(f.id)"
                                                    >
                                                        {{ f.name }}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <DialogFooter>
                                            <DialogClose as-child
                                                ><Button
                                                    type="button"
                                                    variant="ghost"
                                                    >Batal</Button
                                                ></DialogClose
                                            >
                                            <Button type="submit"
                                                >Pindahkan</Button
                                            >
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>

                            <Dialog>
                                <DialogTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-11 w-11 text-red-600 hover:text-red-600"
                                        :aria-label="`Hapus ${file.name}`"
                                        @click="
                                            deletingFile = {
                                                id: file.id,
                                                name: file.name,
                                            }
                                        "
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <form
                                        class="space-y-6"
                                        @submit.prevent="submitDelete"
                                    >
                                        <DialogHeader>
                                            <DialogTitle
                                                >Hapus
                                                {{
                                                    deletingFile?.name
                                                }}?</DialogTitle
                                            >
                                            <DialogDescription>
                                                File dihapus dari provider asli
                                                dan dari daftar. Tidak bisa
                                                dibatalkan.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <DialogClose as-child
                                                ><Button
                                                    type="button"
                                                    variant="ghost"
                                                    >Batal</Button
                                                ></DialogClose
                                            >
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                >Hapus</Button
                                            >
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div
            v-if="isDragging"
            class="bg-background/80 pointer-events-none absolute inset-0 z-50 flex items-center justify-center border-2 border-dashed border-teal-500"
        >
            <p class="text-sm font-medium">Lepaskan file untuk diunggah</p>
        </div>
    </div>
</template>
