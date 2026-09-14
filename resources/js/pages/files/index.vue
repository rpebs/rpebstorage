<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useStorage } from '@vueuse/core';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronRight,
    Download,
    File as FileIcon,
    FileArchive,
    FileAudio,
    FileCode,
    FileImage,
    FileText,
    FileVideo,
    Folder as FolderIcon,
    FolderInput,
    FolderOpen,
    FolderPlus,
    HardDrive,
    Home,
    LayoutGrid,
    List,
    MoreVertical,
    Search,
    Trash2,
    Upload,
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes, formatDate } from '@/lib/format';
import { useUploader } from '@/composables/useUploader';

interface FolderItem {
    id: number;
    name: string;
    parent_id: number | null;
    updated_at?: string | null;
}

interface FileItem {
    id: number;
    name: string;
    size: number;
    mime_type: string | null;
    is_chunked: boolean;
    account_label: string;
    accessible: boolean;
    updated_at?: string | null;
    has_thumbnail?: boolean;
    thumbnail_url?: string | null;
}

const props = defineProps<{
    folder: { id: number; name: string; parent_id: number | null } | null;
    breadcrumb: Array<{ id: number; name: string }>;
    folders: Array<FolderItem>;
    files: Array<FileItem>;
    allFolders: Array<{ id: number; name: string; parent_id: number | null }>;
    accounts: Array<{ id: number; label: string; unlimited: boolean }>;
    search: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Files', href: '/files' }],
    },
});

type SortKey = 'name' | 'size' | 'date' | 'type';
type SortDirection = 'asc' | 'desc';
type ViewMode = 'table' | 'grid';

// User preference persistence
const sortKey = useStorage<SortKey>('rpebstorage_files_sort_key', 'name');
const sortDir = useStorage<SortDirection>('rpebstorage_files_sort_dir', 'asc');
const viewMode = useStorage<ViewMode>('rpebstorage_files_view_mode', 'table');

const sortCombined = computed({
    get: () => `${sortKey.value}-${sortDir.value}`,
    set: (val: string) => {
        const [k, d] = val.split('-');
        if (k) sortKey.value = k as SortKey;
        if (d) sortDir.value = d as SortDirection;
    },
});

function toggleSort(col: SortKey): void {
    if (sortKey.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = col;
        sortDir.value = col === 'size' || col === 'date' ? 'desc' : 'asc';
    }
}

const searchInput = ref(props.search);
const isDragging = ref(false);
const dragDepth = ref(0);
const targetAccountId = ref<string>('auto');
const fileInput = ref<HTMLInputElement | null>(null);

const failedThumbnails = ref<Record<number, boolean>>({});

function onThumbnailError(id: number): void {
    failedThumbnails.value[id] = true;
}

function hasValidThumbnail(file: FileItem): boolean {
    return Boolean(file.has_thumbnail && file.thumbnail_url && !failedThumbnails.value[file.id]);
}

const {
    items,
    serverJobs,
    uploadingCount,
    add,
    clear,
    startPolling,
    stopPolling,
} = useUploader();

const activeJobs = computed(() =>
    serverJobs.value.filter(
        (j) => j.status === 'pending' || j.status === 'processing',
    ),
);

const panelVisible = computed(
    () => items.value.length > 0 || activeJobs.value.length > 0,
);

let dismissTimer: number | null = null;

// Poll the server job queue whenever uploads are in flight.
watch(
    () => uploadingCount() + activeJobs.value.length,
    (count, oldCount) => {
        if (count > 0) {
            if (dismissTimer !== null) {
                window.clearTimeout(dismissTimer);
                dismissTimer = null;
            }
            startPolling();
        } else {
            stopPolling();
            if (oldCount && oldCount > 0) {
                router.reload({ only: ['files', 'folders'] });
                dismissTimer = window.setTimeout(() => {
                    clear();
                }, 3500);
            }
        }
    },
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
    searchForm.q = searchInput.value;
    searchForm.get('/files', { preserveState: true });
}

function clearSearch(): void {
    searchInput.value = '';
    searchForm.q = '';
    router.get('/files', props.folder ? { folder: props.folder.id } : {}, {
        preserveState: true,
    });
}

// Dialog States
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

const renamingFolder = ref<{ id: number; name: string } | null>(null);
const renameForm = useForm({ name: '' });

function openRenameFolder(item: { id: number; name: string }): void {
    renamingFolder.value = item;
    renameForm.name = item.name;
}

function submitRenameFolder(): void {
    if (!renamingFolder.value) {
        return;
    }

    renameForm.patch(`/files/folders/${renamingFolder.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            renamingFolder.value = null;
        },
    });
}

const deletingFolder = ref<{ id: number; name: string } | null>(null);

function submitDeleteFolder(): void {
    if (!deletingFolder.value) {
        return;
    }

    router.delete(`/files/folders/${deletingFolder.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingFolder.value = null;
        },
    });
}

const movingFile = ref<{ id: number; name: string } | null>(null);
const moveTarget = ref<string>('root');

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
        {
            preserveScroll: true,
            onSuccess: () => {
                movingFile.value = null;
            },
        },
    );
}

const deletingFile = ref<{ id: number; name: string } | null>(null);

function submitDelete(): void {
    if (!deletingFile.value) {
        return;
    }

    router.delete(`/files/${deletingFile.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingFile.value = null;
        },
    });
}

// Helpers
function getFileInfo(name: string, mimeType: string | null) {
    const ext = name.split('.').pop()?.toLowerCase() || '';
    const mime = (mimeType || '').toLowerCase();

    if (
        ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'].includes(
            ext,
        ) ||
        mime.startsWith('image/')
    ) {
        return {
            category: 'image',
            icon: FileImage,
            badgeClass:
                'text-emerald-700 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
            iconClass: 'text-emerald-600 dark:text-emerald-400',
        };
    }
    if (
        ['mp4', 'mkv', 'mov', 'avi', 'webm', 'wmv'].includes(ext) ||
        mime.startsWith('video/')
    ) {
        return {
            category: 'video',
            icon: FileVideo,
            badgeClass:
                'text-purple-700 bg-purple-50 dark:bg-purple-950/40 dark:text-purple-300 border-purple-200 dark:border-purple-800',
            iconClass: 'text-purple-600 dark:text-purple-400',
        };
    }
    if (
        ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac'].includes(ext) ||
        mime.startsWith('audio/')
    ) {
        return {
            category: 'audio',
            icon: FileAudio,
            badgeClass:
                'text-amber-700 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200 dark:border-amber-800',
            iconClass: 'text-amber-600 dark:text-amber-400',
        };
    }
    if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2'].includes(ext)) {
        return {
            category: 'archive',
            icon: FileArchive,
            badgeClass:
                'text-amber-800 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-400 border-amber-200 dark:border-amber-800',
            iconClass: 'text-amber-700 dark:text-amber-500',
        };
    }
    if (
        [
            'js',
            'ts',
            'vue',
            'php',
            'html',
            'css',
            'json',
            'py',
            'go',
            'rs',
            'sql',
            'sh',
            'yaml',
            'yml',
            'xml',
        ].includes(ext)
    ) {
        return {
            category: 'code',
            icon: FileCode,
            badgeClass:
                'text-blue-700 bg-blue-50 dark:bg-blue-950/40 dark:text-blue-300 border-blue-200 dark:border-blue-800',
            iconClass: 'text-blue-600 dark:text-blue-400',
        };
    }
    if (
        [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'md',
            'csv',
        ].includes(ext) ||
        mime.startsWith('text/')
    ) {
        return {
            category: 'document',
            icon: FileText,
            badgeClass:
                'text-teal-700 bg-teal-50 dark:bg-teal-950/40 dark:text-teal-300 border-teal-200 dark:border-teal-800',
            iconClass: 'text-teal-600 dark:text-teal-400',
        };
    }

    return {
        category: 'other',
        icon: FileIcon,
        badgeClass: 'text-muted-foreground bg-muted border-border',
        iconClass: 'text-muted-foreground',
    };
}

// Sorting logic
const sortedFolders = computed(() => {
    const list = [...props.folders];
    list.sort((a, b) => {
        let cmp = 0;
        if (sortKey.value === 'name') {
            cmp = a.name.localeCompare(b.name, undefined, {
                sensitivity: 'base',
            });
        } else if (sortKey.value === 'date') {
            const timeA = a.updated_at ? new Date(a.updated_at).getTime() : 0;
            const timeB = b.updated_at ? new Date(b.updated_at).getTime() : 0;
            cmp = timeA - timeB;
        } else {
            cmp = a.name.localeCompare(b.name, undefined, {
                sensitivity: 'base',
            });
        }
        return sortDir.value === 'asc' ? cmp : -cmp;
    });
    return list;
});

const sortedFiles = computed(() => {
    const list = [...props.files];
    list.sort((a, b) => {
        let cmp = 0;
        if (sortKey.value === 'name') {
            cmp = a.name.localeCompare(b.name, undefined, {
                sensitivity: 'base',
            });
        } else if (sortKey.value === 'size') {
            cmp = a.size - b.size;
        } else if (sortKey.value === 'date') {
            const timeA = a.updated_at ? new Date(a.updated_at).getTime() : 0;
            const timeB = b.updated_at ? new Date(b.updated_at).getTime() : 0;
            cmp = timeA - timeB;
        } else if (sortKey.value === 'type') {
            const extA = a.name.split('.').pop()?.toLowerCase() || '';
            const extB = b.name.split('.').pop()?.toLowerCase() || '';
            cmp = extA.localeCompare(extB);
            if (cmp === 0) {
                cmp = a.name.localeCompare(b.name, undefined, {
                    sensitivity: 'base',
                });
            }
        }
        return sortDir.value === 'asc' ? cmp : -cmp;
    });
    return list;
});

const totalSize = computed(() =>
    props.files.reduce((acc, f) => acc + (f.size || 0), 0),
);

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
        <div class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6">
            <!-- Breadcrumb Bar & Search -->
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <nav
                    aria-label="Breadcrumb folder"
                    class="flex min-w-0 flex-wrap items-center gap-1 text-sm"
                >
                    <Link
                        href="/files"
                        class="text-muted-foreground hover:bg-muted/60 hover:text-foreground flex items-center gap-1.5 rounded-md px-2 py-1 transition-colors"
                        :class="{
                            'bg-muted/40 text-foreground font-semibold':
                                !folder,
                        }"
                    >
                        <Home class="h-3.5 w-3.5" />
                        <span>Root</span>
                    </Link>
                    <template v-for="crumb in breadcrumb" :key="crumb.id">
                        <ChevronRight
                            class="text-muted-foreground/60 h-3.5 w-3.5 shrink-0"
                        />
                        <Link
                            :href="`/files?folder=${crumb.id}`"
                            class="text-muted-foreground hover:bg-muted/60 hover:text-foreground max-w-[140px] truncate rounded-md px-2 py-1 transition-colors sm:max-w-[220px]"
                            :class="{
                                'bg-muted/40 text-foreground font-semibold':
                                    crumb.id === folder?.id,
                            }"
                            :title="crumb.name"
                        >
                            {{ crumb.name }}
                        </Link>
                    </template>
                </nav>

                <!-- Search box -->
                <form
                    class="relative flex w-full items-center gap-1.5 sm:w-auto"
                    @submit.prevent="submitSearch"
                >
                    <div class="relative w-full sm:w-56">
                        <Search
                            class="text-muted-foreground pointer-events-none absolute top-2.5 left-2.5 h-4 w-4"
                        />
                        <Input
                            v-model="searchInput"
                            type="search"
                            placeholder="Cari berkas..."
                            class="h-9 pr-7 pl-8 text-xs"
                            aria-label="Cari berkas berdasarkan nama"
                            @keydown.esc="clearSearch"
                        />
                        <button
                            v-if="searchInput"
                            type="button"
                            class="text-muted-foreground hover:text-foreground absolute top-2.5 right-2 flex h-4 w-4 items-center justify-center rounded-xs"
                            aria-label="Hapus kata kunci pencarian"
                            @click="clearSearch"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        class="h-9 text-xs"
                    >
                        Cari
                    </Button>
                </form>
            </div>

            <!-- Action Toolbar: Upload, New Folder, Target Account, Sort & View Mode -->
            <div
                class="bg-card flex flex-col gap-3 rounded-lg border p-3 shadow-2xs sm:flex-row sm:items-center sm:justify-between"
            >
                <!-- Left: Action triggers -->
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        class="sr-only"
                        aria-label="Pilih berkas untuk diunggah"
                        @change="
                            submitFiles(
                                ($event.target as HTMLInputElement).files,
                            )
                        "
                    />
                    <Button
                        size="sm"
                        class="h-9 gap-1.5 bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        @click="fileInput?.click()"
                    >
                        <Upload class="h-4 w-4" />
                        <span>Unggah</span>
                    </Button>

                    <Dialog v-model:open="newFolderOpen">
                        <DialogTrigger as-child>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-9 gap-1.5"
                            >
                                <FolderPlus class="h-4 w-4" />
                                <span>Folder baru</span>
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <form
                                class="space-y-5"
                                @submit.prevent="createFolder"
                            >
                                <DialogHeader>
                                    <DialogTitle>Folder baru</DialogTitle>
                                    <DialogDescription>
                                        Folder virtual di rpebstorage untuk
                                        merapikan penyimpanan berkas.
                                    </DialogDescription>
                                </DialogHeader>
                                <div class="grid gap-2">
                                    <Label for="folder-name">Nama folder</Label>
                                    <Input
                                        id="folder-name"
                                        v-model="newFolderForm.name"
                                        required
                                        maxlength="120"
                                        placeholder="Contoh: Dokumen Kerja"
                                    />
                                    <p
                                        v-if="newFolderForm.errors.name"
                                        class="text-xs text-red-600 dark:text-red-400"
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
                                        class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                                    >
                                        Buat Folder
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

                    <!-- Target Account Selector -->
                    <Select v-model="targetAccountId">
                        <SelectTrigger
                            class="h-9 w-48 text-xs sm:w-56"
                            aria-label="Akun tujuan unggah"
                        >
                            <HardDrive
                                class="text-muted-foreground mr-1.5 h-3.5 w-3.5 shrink-0"
                            />
                            <SelectValue placeholder="Akun tujuan" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="auto">
                                Otomatis (sisa kuota terbesar)
                            </SelectItem>
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

                <!-- Right: Sorting & View Mode Switcher -->
                <div
                    class="flex flex-wrap items-center justify-between gap-2 sm:justify-end"
                >
                    <!-- Sort Selector -->
                    <div class="flex items-center gap-1.5">
                        <span
                            class="text-muted-foreground hidden text-xs lg:inline"
                            >Urutkan:</span
                        >
                        <Select v-model="sortCombined">
                            <SelectTrigger
                                class="h-9 w-40 text-xs sm:w-44"
                                aria-label="Pilihan pengurutan berkas"
                            >
                                <ArrowUpDown
                                    class="text-muted-foreground mr-1.5 h-3.5 w-3.5 shrink-0"
                                />
                                <SelectValue placeholder="Urutkan" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="name-asc"
                                    >Nama (A ke Z)</SelectItem
                                >
                                <SelectItem value="name-desc"
                                    >Nama (Z ke A)</SelectItem
                                >
                                <SelectItem value="size-desc"
                                    >Ukuran (Terbesar)</SelectItem
                                >
                                <SelectItem value="size-asc"
                                    >Ukuran (Terkecil)</SelectItem
                                >
                                <SelectItem value="date-desc"
                                    >Terbaru diubah</SelectItem
                                >
                                <SelectItem value="date-asc"
                                    >Terlama diubah</SelectItem
                                >
                                <SelectItem value="type-asc"
                                    >Tipe berkas</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- View Mode Toggle (Table / Grid) -->
                    <div
                        class="bg-muted/30 flex items-center rounded-md border p-0.5"
                        role="group"
                        aria-label="Mode tampilan berkas"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 rounded-sm transition-colors"
                            :class="{
                                'bg-background text-foreground font-medium shadow-xs':
                                    viewMode === 'table',
                                'text-muted-foreground hover:text-foreground':
                                    viewMode !== 'table',
                            }"
                            :aria-pressed="viewMode === 'table'"
                            aria-label="Tampilan Tabel"
                            @click="viewMode = 'table'"
                        >
                            <List class="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-8 w-8 rounded-sm transition-colors"
                            :class="{
                                'bg-background text-foreground font-medium shadow-xs':
                                    viewMode === 'grid',
                                'text-muted-foreground hover:text-foreground':
                                    viewMode !== 'grid',
                            }"
                            :aria-pressed="viewMode === 'grid'"
                            aria-label="Tampilan Grid"
                            @click="viewMode = 'grid'"
                        >
                            <LayoutGrid class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Upload Progress Panel -->
            <div
                v-if="panelVisible"
                class="rounded-lg border border-teal-200 bg-teal-50/40 p-3 dark:border-teal-900/60 dark:bg-teal-950/20"
                aria-live="polite"
            >
                <div class="mb-2 flex items-center justify-between">
                    <p
                        class="text-xs font-semibold text-teal-800 dark:text-teal-300"
                    >
                        Transfer Aktif
                    </p>
                    <div class="flex items-center gap-2">
                        <Link
                            href="/logs"
                            class="text-muted-foreground hover:text-foreground text-xs underline"
                        >
                            Log Aktivitas
                        </Link>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-muted-foreground hover:text-foreground h-6 w-6"
                            aria-label="Tutup panel transfer"
                            @click="clear"
                        >
                            <X class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
                <ul class="space-y-1.5 text-sm">
                    <li
                        v-for="item in items"
                        :key="item.id"
                        class="flex items-center gap-3 text-xs"
                    >
                        <Upload
                            class="h-3.5 w-3.5 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <span class="w-44 truncate font-medium sm:w-56">{{
                            item.name
                        }}</span>
                        <div
                            class="bg-muted h-1.5 w-32 overflow-hidden rounded-full sm:w-44"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-300"
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
                        <span
                            class="text-muted-foreground text-xs tabular-nums"
                        >
                            {{
                                item.status === 'uploading'
                                    ? `${item.progress}%`
                                    : item.status === 'done'
                                      ? 'terkirim ke server'
                                      : item.error
                            }}
                        </span>
                    </li>
                    <li
                        v-for="job in activeJobs"
                        :key="job.id"
                        class="flex items-center gap-3 text-xs"
                    >
                        <Upload
                            class="h-3.5 w-3.5 shrink-0 text-teal-600 dark:text-teal-400"
                        />
                        <span class="w-44 truncate font-medium sm:w-56">{{
                            job.name
                        }}</span>
                        <div
                            class="bg-muted h-1.5 w-32 overflow-hidden rounded-full sm:w-44"
                        >
                            <div
                                class="h-full rounded-full bg-teal-600 transition-all duration-300 dark:bg-teal-400"
                                :style="{
                                    width: `${Math.max(5, job.progress)}%`,
                                }"
                            />
                        </div>
                        <span
                            class="text-muted-foreground text-xs tabular-nums"
                        >
                            memproses di server {{ job.progress }}%
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Directory Summary Bar -->
            <div
                v-if="!isEmpty && !searchEmpty"
                class="text-muted-foreground flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-xs"
            >
                <div class="flex items-center gap-2">
                    <span>{{ sortedFolders.length }} folder</span>
                    <span>•</span>
                    <span>{{ sortedFiles.length }} berkas</span>
                    <span v-if="sortedFiles.length > 0">•</span>
                    <span
                        v-if="sortedFiles.length > 0"
                        class="text-foreground font-medium tabular-nums"
                    >
                        Total {{ formatBytes(totalSize) }}
                    </span>
                </div>
                <div v-if="search" class="text-xs">
                    Hasil pencarian untuk:
                    <span class="text-foreground font-medium"
                        >"{{ search }}"</span
                    >
                </div>
            </div>

            <!-- Empty Folder State -->
            <div
                v-if="isEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <FolderOpen
                    class="text-muted-foreground/60 mx-auto mb-3 h-10 w-10"
                />
                <p class="text-sm font-semibold">
                    {{
                        folder
                            ? `Folder "${folder.name}" kosong`
                            : 'Penyimpanan utama masih kosong'
                    }}
                </p>
                <p class="text-muted-foreground mt-1 text-xs">
                    Tarik berkas langsung ke area ini, atau klik tombol Unggah
                    untuk memulai penyimpanan.
                </p>
                <div class="mt-5 flex items-center justify-center gap-2">
                    <Button
                        size="sm"
                        class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        @click="fileInput?.click()"
                    >
                        <Upload class="mr-1.5 h-3.5 w-3.5" />
                        Pilih berkas
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="newFolderOpen = true"
                    >
                        <FolderPlus class="mr-1.5 h-3.5 w-3.5" />
                        Buat folder
                    </Button>
                </div>
            </div>

            <!-- Empty Search State -->
            <div
                v-else-if="searchEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <Search class="text-muted-foreground/60 mx-auto mb-3 h-9 w-9" />
                <p class="text-sm font-semibold">
                    Tidak ada berkas bernama "{{ search }}"
                </p>
                <p class="text-muted-foreground mt-1 text-xs">
                    Periksa kembali ejaan kata kunci atau kembali ke daftar
                    berkas.
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    class="mt-4"
                    @click="clearSearch"
                >
                    Kembali ke daftar
                </Button>
            </div>

            <!-- File Explorer: TABLE VIEW -->
            <div
                v-else-if="viewMode === 'table'"
                class="bg-card overflow-hidden rounded-lg border shadow-2xs"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="bg-muted/40 text-muted-foreground border-b font-medium select-none"
                        >
                            <tr>
                                <th
                                    class="hover:text-foreground cursor-pointer px-4 py-3"
                                    @click="toggleSort('name')"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <span>Nama</span>
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'name' &&
                                                sortDir === 'asc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowDown
                                            v-else-if="
                                                sortKey === 'name' &&
                                                sortDir === 'desc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="h-3 w-3 opacity-35"
                                        />
                                    </div>
                                </th>
                                <th
                                    class="hover:text-foreground w-24 cursor-pointer px-4 py-3 text-right"
                                    @click="toggleSort('size')"
                                >
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <span>Ukuran</span>
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'size' &&
                                                sortDir === 'asc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowDown
                                            v-else-if="
                                                sortKey === 'size' &&
                                                sortDir === 'desc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="h-3 w-3 opacity-35"
                                        />
                                    </div>
                                </th>
                                <th class="hidden w-44 px-4 py-3 md:table-cell">
                                    Akun Storage
                                </th>
                                <th
                                    class="hover:text-foreground hidden w-36 cursor-pointer px-4 py-3 sm:table-cell"
                                    @click="toggleSort('date')"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <span>Terakhir Diubah</span>
                                        <ArrowUp
                                            v-if="
                                                sortKey === 'date' &&
                                                sortDir === 'asc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowDown
                                            v-else-if="
                                                sortKey === 'date' &&
                                                sortDir === 'desc'
                                            "
                                            class="h-3 w-3 text-teal-600 dark:text-teal-400"
                                        />
                                        <ArrowUpDown
                                            v-else
                                            class="h-3 w-3 opacity-35"
                                        />
                                    </div>
                                </th>
                                <th class="w-24 px-4 py-3 text-right">
                                    <span class="sr-only">Aksi</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <!-- Folders in Table View -->
                            <tr
                                v-for="item in sortedFolders"
                                :key="`folder-${item.id}`"
                                class="hover:bg-muted/40 transition-colors"
                            >
                                <td class="px-4 py-2.5">
                                    <button
                                        type="button"
                                        class="text-foreground flex max-w-full min-w-0 items-center gap-2.5 text-left font-medium transition-colors hover:text-teal-600 dark:hover:text-teal-400"
                                        @click="navigateToFolder(item.id)"
                                    >
                                        <FolderIcon
                                            class="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-400"
                                        />
                                        <span class="truncate">{{
                                            item.name
                                        }}</span>
                                    </button>
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-2.5 text-right"
                                >
                                    <Badge
                                        variant="outline"
                                        class="text-muted-foreground text-[10px]"
                                    >
                                        Folder
                                    </Badge>
                                </td>
                                <td
                                    class="text-muted-foreground hidden px-4 py-2.5 md:table-cell"
                                >
                                    -
                                </td>
                                <td
                                    class="text-muted-foreground hidden px-4 py-2.5 tabular-nums sm:table-cell"
                                >
                                    {{ formatDate(item.updated_at) }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-8 w-8"
                                            :aria-label="`Ganti nama folder ${item.name}`"
                                            @click="openRenameFolder(item)"
                                        >
                                            <FolderInput class="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground h-8 w-8 hover:text-red-600"
                                            :aria-label="`Hapus folder ${item.name}`"
                                            @click="deletingFolder = item"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Files in Table View -->
                            <tr
                                v-for="file in sortedFiles"
                                :key="`file-${file.id}`"
                                class="hover:bg-muted/40 transition-colors"
                            >
                                <td class="px-4 py-2.5">
                                    <div
                                        class="flex min-w-0 items-center gap-2.5"
                                    >
                                        <template v-if="hasValidThumbnail(file)">
                                            <img
                                                :src="file.thumbnail_url!"
                                                :alt="file.name"
                                                loading="lazy"
                                                class="h-7 w-7 shrink-0 rounded object-cover border border-border/60 bg-muted/40"
                                                @error="onThumbnailError(file.id)"
                                            />
                                        </template>
                                        <template v-else>
                                            <div class="flex h-7 w-7 shrink-0 items-center justify-center">
                                                <component
                                                    :is="
                                                        getFileInfo(
                                                            file.name,
                                                            file.mime_type,
                                                        ).icon
                                                    "
                                                    class="h-4 w-4 shrink-0"
                                                    :class="
                                                        getFileInfo(
                                                            file.name,
                                                            file.mime_type,
                                                        ).iconClass
                                                    "
                                                />
                                            </div>
                                        </template>
                                        <span
                                            class="text-foreground truncate font-medium"
                                            :title="file.name"
                                        >
                                            {{ file.name }}
                                        </span>
                                        <Badge
                                            v-if="file.is_chunked"
                                            variant="outline"
                                            class="text-muted-foreground hidden text-[10px] sm:inline-flex"
                                        >
                                            Chunked
                                        </Badge>
                                        <span
                                            v-if="!file.accessible"
                                            class="text-[11px] text-red-600 dark:text-red-400"
                                        >
                                            (akun terputus)
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="text-muted-foreground px-4 py-2.5 text-right font-medium whitespace-nowrap tabular-nums"
                                >
                                    {{ formatBytes(file.size) }}
                                </td>
                                <td
                                    class="text-muted-foreground hidden px-4 py-2.5 md:table-cell"
                                >
                                    <div
                                        class="flex max-w-[170px] items-center gap-1.5 truncate"
                                        :title="file.account_label"
                                    >
                                        <HardDrive
                                            class="h-3.5 w-3.5 shrink-0 opacity-70"
                                        />
                                        <span class="truncate">{{
                                            file.account_label || 'Otomatis'
                                        }}</span>
                                    </div>
                                </td>
                                <td
                                    class="text-muted-foreground hidden px-4 py-2.5 whitespace-nowrap tabular-nums sm:table-cell"
                                >
                                    {{ formatDate(file.updated_at) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right whitespace-nowrap"
                                >
                                    <div
                                        class="flex items-center justify-end gap-1"
                                    >
                                        <Button
                                            v-if="file.accessible"
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-8 w-8"
                                            as-child
                                        >
                                            <a
                                                :href="`/files/${file.id}/download`"
                                                :aria-label="`Unduh ${file.name}`"
                                            >
                                                <Download class="h-3.5 w-3.5" />
                                            </a>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-8 w-8"
                                            :aria-label="`Pindahkan ${file.name}`"
                                            @click="openMove(file)"
                                        >
                                            <FolderInput class="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground h-8 w-8 hover:text-red-600"
                                            :aria-label="`Hapus ${file.name}`"
                                            @click="deletingFile = file"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- File Explorer: GRID VIEW -->
            <div v-else-if="viewMode === 'grid'" class="space-y-6">
                <!-- Folders Group -->
                <div v-if="sortedFolders.length > 0" class="space-y-2.5">
                    <h2
                        class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                    >
                        Folder ({{ sortedFolders.length }})
                    </h2>
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
                    >
                        <div
                            v-for="item in sortedFolders"
                            :key="`grid-folder-${item.id}`"
                            role="button"
                            tabindex="0"
                            class="group bg-card hover:bg-muted/30 relative flex cursor-pointer flex-col justify-between rounded-lg border p-3 shadow-2xs transition-all hover:border-teal-500/50 focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:outline-none"
                            @click="navigateToFolder(item.id)"
                            @keydown.enter="navigateToFolder(item.id)"
                        >
                            <div class="flex items-start justify-between gap-1">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-md bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400"
                                >
                                    <FolderIcon class="h-5 w-5" />
                                </div>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-7 w-7"
                                            :aria-label="`Menu aksi folder ${item.name}`"
                                            @click.stop
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-36 text-xs"
                                    >
                                        <DropdownMenuItem
                                            class="flex cursor-pointer items-center gap-2"
                                            @click.stop="openRenameFolder(item)"
                                        >
                                            <FolderInput class="h-3.5 w-3.5" />
                                            <span>Ganti Nama</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            class="flex cursor-pointer items-center gap-2 text-red-600 focus:text-red-600"
                                            @click.stop="deletingFolder = item"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                            <span>Hapus</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>

                            <div class="mt-2.5 min-w-0">
                                <p
                                    class="text-foreground truncate text-xs font-semibold group-hover:text-teal-600 dark:group-hover:text-teal-400"
                                    :title="item.name"
                                >
                                    {{ item.name }}
                                </p>
                                <p
                                    class="text-muted-foreground mt-0.5 text-[11px] tabular-nums"
                                >
                                    {{ formatDate(item.updated_at) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Files Group -->
                <div v-if="sortedFiles.length > 0" class="space-y-2.5">
                    <h2
                        class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                    >
                        Berkas ({{ sortedFiles.length }})
                    </h2>
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
                    >
                        <div
                            v-for="file in sortedFiles"
                            :key="`grid-file-${file.id}`"
                            class="group bg-card hover:bg-muted/30 relative flex flex-col justify-between rounded-lg border p-3 shadow-2xs transition-all hover:border-teal-500/50"
                        >
                            <!-- Visual file type preview box -->
                            <div
                                class="bg-muted/40 group-hover:bg-muted/60 relative flex h-24 w-full flex-col items-center justify-center overflow-hidden rounded-md transition-colors"
                            >
                                <template v-if="hasValidThumbnail(file)">
                                    <img
                                        :src="file.thumbnail_url!"
                                        :alt="file.name"
                                        loading="lazy"
                                        class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
                                        @error="onThumbnailError(file.id)"
                                    />
                                </template>
                                <template v-else>
                                    <component
                                        :is="
                                            getFileInfo(file.name, file.mime_type)
                                                .icon
                                        "
                                        class="h-8 w-8"
                                        :class="
                                            getFileInfo(file.name, file.mime_type)
                                                .iconClass
                                        "
                                    />
                                    <span
                                        class="text-muted-foreground mt-1 text-[10px] font-medium uppercase"
                                    >
                                        {{ file.name.split('.').pop() || 'FILE' }}
                                    </span>
                                </template>

                                <!-- Action trigger button -->
                                <div class="absolute top-1 right-1">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="text-muted-foreground hover:text-foreground h-6 w-6 rounded bg-background/80 backdrop-blur-xs border border-border/30 hover:bg-background"
                                                :aria-label="`Menu aksi ${file.name}`"
                                            >
                                                <MoreVertical
                                                    class="h-3.5 w-3.5"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-36 text-xs"
                                        >
                                            <DropdownMenuItem
                                                v-if="file.accessible"
                                                as-child
                                            >
                                                <a
                                                    :href="`/files/${file.id}/download`"
                                                    class="flex cursor-pointer items-center gap-2"
                                                >
                                                    <Download
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    <span>Unduh</span>
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="flex cursor-pointer items-center gap-2"
                                                @click="openMove(file)"
                                            >
                                                <FolderInput
                                                    class="h-3.5 w-3.5"
                                                />
                                                <span>Pindahkan</span>
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="flex cursor-pointer items-center gap-2 text-red-600 focus:text-red-600"
                                                @click="deletingFile = file"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                                <span>Hapus</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>

                                <!-- Disconnected indicator banner -->
                                <div
                                    v-if="!file.accessible"
                                    class="absolute right-1 bottom-1 left-1 rounded-xs bg-red-600/90 py-0.5 text-center text-[9px] font-medium text-white"
                                >
                                    Akun terputus
                                </div>
                            </div>

                            <!-- Meta info -->
                            <div class="mt-2.5 min-w-0 flex-1">
                                <p
                                    class="text-foreground line-clamp-2 text-xs font-medium transition-colors group-hover:text-teal-600 dark:group-hover:text-teal-400"
                                    :title="file.name"
                                >
                                    {{ file.name }}
                                </p>
                                <div
                                    class="text-muted-foreground mt-1 flex items-center justify-between text-[11px] tabular-nums"
                                >
                                    <span>{{ formatBytes(file.size) }}</span>
                                    <span
                                        v-if="file.is_chunked"
                                        class="text-[9px] text-teal-600 dark:text-teal-400"
                                    >
                                        Chunked
                                    </span>
                                </div>
                                <p
                                    v-if="file.account_label"
                                    class="text-muted-foreground mt-1 flex items-center gap-1 truncate text-[10px]"
                                    :title="file.account_label"
                                >
                                    <HardDrive
                                        class="h-3 w-3 shrink-0 opacity-60"
                                    />
                                    <span class="truncate">{{
                                        file.account_label
                                    }}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drag & Drop Full View Overlay -->
        <div
            v-if="isDragging"
            class="bg-background/90 pointer-events-none absolute inset-0 z-50 flex items-center justify-center border-2 border-dashed border-teal-500"
        >
            <div class="flex flex-col items-center gap-2 text-center">
                <Upload
                    class="h-8 w-8 animate-bounce text-teal-600 dark:text-teal-400"
                />
                <p class="text-foreground text-sm font-semibold">
                    Lepaskan berkas untuk diunggah ke
                    {{ folder ? folder.name : 'Root' }}
                </p>
                <p class="text-muted-foreground text-xs">
                    Berkas akan otomatis diproses dan disimpan ke akun storage
                    yang dipilih.
                </p>
            </div>
        </div>

        <!-- Global Modals: Rename Folder -->
        <Dialog
            :open="renamingFolder !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) renamingFolder = null;
                }
            "
        >
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitRenameFolder">
                    <DialogHeader>
                        <DialogTitle>Ganti nama folder</DialogTitle>
                        <DialogDescription>
                            Ubah nama folder virtual ini.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="rename-folder">Nama Folder</Label>
                        <Input
                            id="rename-folder"
                            v-model="renameForm.name"
                            required
                            maxlength="120"
                        />
                        <p
                            v-if="renameForm.errors.name"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            {{ renameForm.errors.name }}
                        </p>
                    </div>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="renameForm.processing"
                            class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        >
                            Simpan Perubahan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Delete Folder -->
        <Dialog
            :open="deletingFolder !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) deletingFolder = null;
                }
            "
        >
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitDeleteFolder">
                    <DialogHeader>
                        <DialogTitle>
                            Hapus folder {{ deletingFolder?.name }}?
                        </DialogTitle>
                        <DialogDescription>
                            Folder harus dalam kondisi kosong (tidak ada berkas
                            maupun subfolder di dalamnya) sebelum dapat dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive">
                            Hapus Folder
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Move File -->
        <Dialog
            :open="movingFile !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) movingFile = null;
                }
            "
        >
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitMove">
                    <DialogHeader>
                        <DialogTitle>
                            Pindahkan {{ movingFile?.name }}
                        </DialogTitle>
                        <DialogDescription>
                            Lokasi virtual berkas akan diperbarui. Berkas di
                            akun cloud storage tidak perlu diunggah ulang.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="move-target">Folder Tujuan</Label>
                        <Select v-model="moveTarget">
                            <SelectTrigger id="move-target" class="text-xs">
                                <SelectValue
                                    placeholder="Pilih folder tujuan"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="root"
                                    >Root (Tingkat Utama)</SelectItem
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
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        >
                            Pindahkan Berkas
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Delete File -->
        <Dialog
            :open="deletingFile !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) deletingFile = null;
                }
            "
        >
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitDelete">
                    <DialogHeader>
                        <DialogTitle>
                            Hapus {{ deletingFile?.name }}?
                        </DialogTitle>
                        <DialogDescription>
                            Berkas akan dihapus secara permanen dari provider
                            penyimpanan asli dan dari daftar sistem. Tindakan
                            ini tidak dapat dibatalkan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive">
                            Hapus Berkas
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
