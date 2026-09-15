<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useStorage } from '@vueuse/core';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Check,
    ChevronRight,
    Download,
    Eye,
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
    Pencil,
    Plus,
    Search,
    Star,
    Tag,
    Tags,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import FilePreviewModal from '@/components/FilePreviewModal.vue';
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

interface LabelItem {
    id: number;
    name: string;
    color: string;
    files_count?: number;
    folders_count?: number;
}

interface FolderItem {
    id: number;
    name: string;
    parent_id: number | null;
    parent_name?: string | null;
    is_starred?: boolean;
    labels?: LabelItem[];
    updated_at?: string | null;
}

interface FileItem {
    id: number;
    name: string;
    size: number;
    mime_type: string | null;
    is_chunked: boolean;
    is_starred?: boolean;
    virtual_folder_id?: number | null;
    folder_name?: string | null;
    labels?: LabelItem[];
    account_label: string;
    accessible: boolean;
    updated_at?: string | null;
    has_thumbnail?: boolean;
    thumbnail_url?: string | null;
    is_previewable?: boolean;
    preview_type?: string;
    preview_url?: string | null;
}

const props = withDefaults(
    defineProps<{
        folder: { id: number; name: string; parent_id: number | null } | null;
        breadcrumb: Array<{ id: number | string; name: string }>;
        folders: Array<FolderItem>;
        files: Array<FileItem>;
        allFolders: Array<{
            id: number;
            name: string;
            parent_id: number | null;
        }>;
        allLabels?: Array<LabelItem>;
        currentFilter?: 'all' | 'starred' | 'label';
        currentLabel?: LabelItem | null;
        accounts: Array<{ id: number; label: string; unlimited: boolean }>;
        search: string;
    }>(),
    {
        allLabels: () => [],
        currentFilter: 'all',
        currentLabel: null,
    },
);

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
    return Boolean(
        file.has_thumbnail &&
        file.thumbnail_url &&
        !failedThumbnails.value[file.id],
    );
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
    router.get('/files', id ? { folder: id } : {}, { preserveState: false });
}

function navigateToFilter(
    filter: 'all' | 'starred' | 'label',
    labelId?: number,
): void {
    if (filter === 'starred') {
        router.get('/files', { filter: 'starred' }, { preserveState: false });
    } else if (filter === 'label' && labelId) {
        router.get('/files', { label: labelId }, { preserveState: false });
    } else {
        router.get('/files', props.folder ? { folder: props.folder.id } : {}, {
            preserveState: false,
        });
    }
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

// File Preview Modal State
const previewFile = ref<FileItem | null>(null);
const previewOpen = ref(false);

function openPreview(file: FileItem): void {
    if (!file.accessible) return;
    previewFile.value = file;
    previewOpen.value = true;
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

// Multi-Select & Batch Operations State
const selectedFileIds = ref<number[]>([]);
const lastSelectedFileId = ref<number | null>(null);

const isAllSelected = computed(
    () =>
        sortedFiles.value.length > 0 &&
        sortedFiles.value.every((f) => selectedFileIds.value.includes(f.id)),
);

const isSomeSelected = computed(() =>
    sortedFiles.value.some((f) => selectedFileIds.value.includes(f.id)),
);

const selectedFilesPreview = computed(() =>
    sortedFiles.value
        .filter((f) => selectedFileIds.value.includes(f.id))
        .slice(0, 5),
);

function isSelected(id: number): boolean {
    return selectedFileIds.value.includes(id);
}

function handleCheckboxClick(
    event: MouseEvent,
    fileId: number,
    index: number,
): void {
    if (event.shiftKey && lastSelectedFileId.value !== null) {
        const lastIndex = sortedFiles.value.findIndex(
            (f) => f.id === lastSelectedFileId.value,
        );
        if (lastIndex !== -1) {
            const start = Math.min(lastIndex, index);
            const end = Math.max(lastIndex, index);
            const rangeIds = sortedFiles.value
                .slice(start, end + 1)
                .map((f) => f.id);

            const targetState = (event.target as HTMLInputElement).checked;
            if (targetState) {
                const set = new Set([...selectedFileIds.value, ...rangeIds]);
                selectedFileIds.value = Array.from(set);
            } else {
                selectedFileIds.value = selectedFileIds.value.filter(
                    (id) => !rangeIds.includes(id),
                );
            }
            lastSelectedFileId.value = fileId;
            return;
        }
    }

    const isChecked = (event.target as HTMLInputElement).checked;
    if (isChecked) {
        if (!selectedFileIds.value.includes(fileId)) {
            selectedFileIds.value.push(fileId);
        }
    } else {
        selectedFileIds.value = selectedFileIds.value.filter(
            (id) => id !== fileId,
        );
    }
    lastSelectedFileId.value = fileId;
}

function toggleSelectAll(): void {
    if (isAllSelected.value) {
        selectedFileIds.value = [];
        lastSelectedFileId.value = null;
    } else {
        selectedFileIds.value = sortedFiles.value.map((f) => f.id);
    }
}

function clearSelection(): void {
    selectedFileIds.value = [];
    lastSelectedFileId.value = null;
}

// Reset selection on folder navigation or search
watch(
    () => [props.folder?.id, props.search],
    () => {
        clearSelection();
    },
);

// Bulk Move State
const bulkMoveOpen = ref(false);
const bulkMoveTarget = ref<string>('root');
const isSubmittingBulkMove = ref(false);

function openBulkMove(): void {
    bulkMoveTarget.value = props.folder ? String(props.folder.id) : 'root';
    bulkMoveOpen.value = true;
}

function submitBulkMove(): void {
    if (selectedFileIds.value.length === 0) return;

    isSubmittingBulkMove.value = true;
    router.post(
        '/files/bulk/move',
        {
            file_ids: selectedFileIds.value,
            folder_id:
                bulkMoveTarget.value === 'root'
                    ? null
                    : Number(bulkMoveTarget.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                bulkMoveOpen.value = false;
                clearSelection();
            },
            onFinish: () => {
                isSubmittingBulkMove.value = false;
            },
        },
    );
}

// Bulk Delete State
const bulkDeleteOpen = ref(false);
const isSubmittingBulkDelete = ref(false);

function submitBulkDelete(): void {
    if (selectedFileIds.value.length === 0) return;

    isSubmittingBulkDelete.value = true;
    router.post(
        '/files/bulk/delete',
        {
            file_ids: selectedFileIds.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                bulkDeleteOpen.value = false;
                clearSelection();
            },
            onFinish: () => {
                isSubmittingBulkDelete.value = false;
            },
        },
    );
}

// Bulk Zip State
const isZipping = ref(false);
const zipJob = ref<{
    id: number;
    name: string;
    status: string;
    progress: number;
    size?: number;
    error?: string | null;
} | null>(null);
const showZipDoneBanner = ref(false);
let zipPollTimer: number | null = null;

function stopZipPolling(): void {
    if (zipPollTimer !== null) {
        window.clearInterval(zipPollTimer);
        zipPollTimer = null;
    }
}

async function startBulkZip(): Promise<void> {
    if (selectedFileIds.value.length === 0 || isZipping.value) return;

    isZipping.value = true;
    const idsToZip = [...selectedFileIds.value];

    try {
        const csrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (csrf) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf[1]);
        }

        const res = await fetch('/files/bulk/zip', {
            method: 'POST',
            headers,
            body: JSON.stringify({ file_ids: idsToZip }),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            alert(err.message || 'Gagal memulai pembuatan arsip ZIP.');
            isZipping.value = false;
            return;
        }

        const data = await res.json();
        zipJob.value = {
            id: data.job_id,
            name: data.name,
            status: data.status,
            progress: data.progress ?? 0,
            size: data.size,
        };
        showZipDoneBanner.value = true;
        clearSelection();

        if (data.status === 'done' && data.download_url) {
            window.location.href = data.download_url;
            isZipping.value = false;
            return;
        }

        pollZipStatus(data.job_id);
    } catch {
        isZipping.value = false;
    }
}

function pollZipStatus(jobId: number): void {
    stopZipPolling();

    zipPollTimer = window.setInterval(async () => {
        try {
            const res = await fetch(`/files/zip/${jobId}/status`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                stopZipPolling();
                isZipping.value = false;
                return;
            }

            const data = await res.json();
            if (zipJob.value && zipJob.value.id === jobId) {
                zipJob.value.status = data.status;
                zipJob.value.progress = data.progress;
                zipJob.value.size = data.size;
                zipJob.value.error = data.error;
            }

            if (data.status === 'done') {
                stopZipPolling();
                isZipping.value = false;
                showZipDoneBanner.value = true;
                if (data.download_url) {
                    window.location.href = data.download_url;
                }
            } else if (data.status === 'failed') {
                stopZipPolling();
                isZipping.value = false;
            }
        } catch {
            // Transient error, continue next tick
        }
    }, 1500);
}

// Color Presets for Labels (utilitarian semantic colors)
const PRESET_COLORS: Array<{
    name: string;
    label: string;
    dot: string;
    bg: string;
    text: string;
    border: string;
}> = [
    {
        name: 'teal',
        label: 'Teal',
        dot: 'bg-teal-500',
        bg: 'bg-teal-50 dark:bg-teal-950/60',
        text: 'text-teal-800 dark:text-teal-200',
        border: 'border-teal-200 dark:border-teal-800',
    },
    {
        name: 'blue',
        label: 'Biru',
        dot: 'bg-blue-500',
        bg: 'bg-blue-50 dark:bg-blue-950/60',
        text: 'text-blue-800 dark:text-blue-200',
        border: 'border-blue-200 dark:border-blue-800',
    },
    {
        name: 'indigo',
        label: 'Nila',
        dot: 'bg-indigo-500',
        bg: 'bg-indigo-50 dark:bg-indigo-950/60',
        text: 'text-indigo-800 dark:text-indigo-200',
        border: 'border-indigo-200 dark:border-indigo-800',
    },
    {
        name: 'purple',
        label: 'Ungu',
        dot: 'bg-purple-500',
        bg: 'bg-purple-50 dark:bg-purple-950/60',
        text: 'text-purple-800 dark:text-purple-200',
        border: 'border-purple-200 dark:border-purple-800',
    },
    {
        name: 'rose',
        label: 'Merah',
        dot: 'bg-rose-500',
        bg: 'bg-rose-50 dark:bg-rose-950/60',
        text: 'text-rose-800 dark:text-rose-200',
        border: 'border-rose-200 dark:border-rose-800',
    },
    {
        name: 'amber',
        label: 'Oranye',
        dot: 'bg-amber-500',
        bg: 'bg-amber-50 dark:bg-amber-950/60',
        text: 'text-amber-800 dark:text-amber-200',
        border: 'border-amber-200 dark:border-amber-800',
    },
    {
        name: 'emerald',
        label: 'Hijau',
        dot: 'bg-emerald-500',
        bg: 'bg-emerald-50 dark:bg-emerald-950/60',
        text: 'text-emerald-800 dark:text-emerald-200',
        border: 'border-emerald-200 dark:border-emerald-800',
    },
    {
        name: 'slate',
        label: 'Abu-abu',
        dot: 'bg-zinc-500',
        bg: 'bg-zinc-100 dark:bg-zinc-800/80',
        text: 'text-zinc-800 dark:text-zinc-200',
        border: 'border-zinc-200 dark:border-zinc-700',
    },
];

function getLabelColorClasses(colorName: string): string {
    const found = PRESET_COLORS.find(
        (c) => c.name.toLowerCase() === (colorName || '').toLowerCase(),
    );
    return found
        ? `${found.bg} ${found.text} ${found.border}`
        : 'bg-teal-50 dark:bg-teal-950/60 text-teal-800 dark:text-teal-200 border-teal-200 dark:border-teal-800';
}

function getLabelDotClass(colorName: string): string {
    const found = PRESET_COLORS.find(
        (c) => c.name.toLowerCase() === (colorName || '').toLowerCase(),
    );
    return found ? found.dot : 'bg-teal-500';
}

// Star toggle logic
async function toggleStar(
    item: FileItem | FolderItem,
    isFolder = false,
): Promise<void> {
    const oldVal = item.is_starred ?? false;
    item.is_starred = !oldVal;

    try {
        const url = isFolder
            ? `/files/folders/${item.id}/star`
            : `/files/${item.id}/star`;
        const csrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (csrf) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf[1]);
        }
        const res = await fetch(url, { method: 'POST', headers });
        if (!res.ok) {
            item.is_starred = oldVal;
        } else if (props.currentFilter === 'starred' && !item.is_starred) {
            router.reload({ only: ['files', 'folders'] });
        }
    } catch {
        item.is_starred = oldVal;
    }
}

// Single Item Label Dialog State
const itemLabelsOpen = ref(false);
const labelingTarget = ref<{
    id: number;
    name: string;
    isFolder: boolean;
    labelIds: number[];
} | null>(null);
const isSavingItemLabels = ref(false);

function openItemLabels(item: FileItem | FolderItem, isFolder = false): void {
    labelingTarget.value = {
        id: item.id,
        name: item.name,
        isFolder,
        labelIds: (item.labels || []).map((l) => l.id),
    };
    itemLabelsOpen.value = true;
}

function toggleTargetLabel(labelId: number): void {
    if (!labelingTarget.value) return;
    const idx = labelingTarget.value.labelIds.indexOf(labelId);
    if (idx > -1) {
        labelingTarget.value.labelIds.splice(idx, 1);
    } else {
        labelingTarget.value.labelIds.push(labelId);
    }
}

function saveItemLabels(): void {
    if (!labelingTarget.value) return;

    isSavingItemLabels.value = true;
    const url = labelingTarget.value.isFolder
        ? `/files/folders/${labelingTarget.value.id}/labels`
        : `/files/${labelingTarget.value.id}/labels`;

    router.patch(
        url,
        { label_ids: labelingTarget.value.labelIds },
        {
            preserveScroll: true,
            onSuccess: () => {
                itemLabelsOpen.value = false;
                labelingTarget.value = null;
            },
            onFinish: () => {
                isSavingItemLabels.value = false;
            },
        },
    );
}

// Global Labels Management State
const manageLabelsOpen = ref(false);
const newLabelName = ref('');
const newLabelColor = ref('teal');
const isCreatingLabel = ref(false);

const editingLabel = ref<LabelItem | null>(null);
const editLabelName = ref('');
const editLabelColor = ref('teal');
const isSavingEditLabel = ref(false);

const deletingLabel = ref<LabelItem | null>(null);
const isDeletingLabel = ref(false);

function createLabel(): void {
    if (!newLabelName.value.trim()) return;

    isCreatingLabel.value = true;
    router.post(
        '/labels',
        {
            name: newLabelName.value.trim(),
            color: newLabelColor.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                newLabelName.value = '';
                newLabelColor.value = 'teal';
            },
            onFinish: () => {
                isCreatingLabel.value = false;
            },
        },
    );
}

function startEditLabel(lbl: LabelItem): void {
    editingLabel.value = lbl;
    editLabelName.value = lbl.name;
    editLabelColor.value = lbl.color;
}

function cancelEditLabel(): void {
    editingLabel.value = null;
    editLabelName.value = '';
    editLabelColor.value = 'teal';
}

function saveEditLabel(): void {
    if (!editingLabel.value || !editLabelName.value.trim()) return;

    isSavingEditLabel.value = true;
    router.patch(
        `/labels/${editingLabel.value.id}`,
        {
            name: editLabelName.value.trim(),
            color: editLabelColor.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelEditLabel();
            },
            onFinish: () => {
                isSavingEditLabel.value = false;
            },
        },
    );
}

function submitDeleteLabel(): void {
    if (!deletingLabel.value) return;

    isDeletingLabel.value = true;
    router.delete(`/labels/${deletingLabel.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deletingLabel.value = null;
        },
        onFinish: () => {
            isDeletingLabel.value = false;
        },
    });
}

function seedDefaultLabels(): void {
    router.post(
        '/labels/defaults',
        {},
        {
            preserveScroll: true,
        },
    );
}

// Bulk Actions State (Star & Labels)
const bulkLabelsOpen = ref(false);
const bulkSelectedLabelIds = ref<number[]>([]);
const isSubmittingBulkStar = ref(false);
const isSubmittingBulkLabels = ref(false);

function toggleBulkLabelSelection(id: number): void {
    const idx = bulkSelectedLabelIds.value.indexOf(id);
    if (idx > -1) {
        bulkSelectedLabelIds.value.splice(idx, 1);
    } else {
        bulkSelectedLabelIds.value.push(id);
    }
}

function submitBulkStar(isStarred: boolean): void {
    if (selectedFileIds.value.length === 0) return;

    isSubmittingBulkStar.value = true;
    router.post(
        '/files/bulk/star',
        {
            file_ids: selectedFileIds.value,
            is_starred: isStarred,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                clearSelection();
            },
            onFinish: () => {
                isSubmittingBulkStar.value = false;
            },
        },
    );
}

function submitBulkLabels(): void {
    if (
        selectedFileIds.value.length === 0 ||
        bulkSelectedLabelIds.value.length === 0
    )
        return;

    isSubmittingBulkLabels.value = true;
    router.post(
        '/files/bulk/labels',
        {
            file_ids: selectedFileIds.value,
            label_ids: bulkSelectedLabelIds.value,
            action: 'attach',
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                bulkLabelsOpen.value = false;
                bulkSelectedLabelIds.value = [];
                clearSelection();
            },
            onFinish: () => {
                isSubmittingBulkLabels.value = false;
            },
        },
    );
}

function handleKeydown(e: KeyboardEvent): void {
    if (
        e.key === 'Escape' &&
        selectedFileIds.value.length > 0 &&
        !bulkMoveOpen.value &&
        !bulkDeleteOpen.value &&
        !bulkLabelsOpen.value &&
        !itemLabelsOpen.value &&
        !manageLabelsOpen.value &&
        !newFolderOpen.value &&
        !previewOpen.value &&
        !renamingFolder.value &&
        !deletingFolder.value &&
        !movingFile.value &&
        !deletingFile.value
    ) {
        clearSelection();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    stopZipPolling();
});
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

            <!-- Tag & Filter Bar (Semua, Favorit, Labels, Kelola Label) -->
            <div class="flex flex-wrap items-center gap-1.5 pt-0.5 pb-1">
                <button
                    type="button"
                    class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-full px-3 text-xs font-medium transition-colors"
                    :class="
                        currentFilter === 'all' && !search
                            ? 'bg-teal-600 font-semibold text-white dark:bg-teal-500 dark:text-zinc-950'
                            : 'bg-muted/40 hover:bg-muted text-muted-foreground hover:text-foreground border'
                    "
                    @click="navigateToFilter('all')"
                >
                    Semua Berkas
                </button>

                <button
                    type="button"
                    class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-full px-3 text-xs font-medium transition-colors"
                    :class="
                        currentFilter === 'starred'
                            ? 'bg-amber-500 font-semibold text-white shadow-2xs dark:bg-amber-400 dark:text-zinc-950'
                            : 'bg-muted/40 hover:bg-muted text-muted-foreground hover:text-foreground border'
                    "
                    @click="navigateToFilter('starred')"
                >
                    <Star
                        class="h-3 w-3"
                        :class="
                            currentFilter === 'starred'
                                ? 'fill-white dark:fill-zinc-950'
                                : 'fill-amber-400 text-amber-500'
                        "
                    />
                    <span>Favorit</span>
                </button>

                <div
                    v-if="allLabels && allLabels.length > 0"
                    class="bg-border mx-1 hidden h-3.5 w-px sm:block"
                />

                <!-- User Labels -->
                <button
                    v-for="lbl in allLabels"
                    :key="lbl.id"
                    type="button"
                    class="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-all"
                    :class="[
                        getLabelColorClasses(lbl.color),
                        currentLabel?.id === lbl.id
                            ? 'font-bold shadow-2xs ring-2 ring-teal-500/80'
                            : 'opacity-85 hover:opacity-100',
                    ]"
                    @click="navigateToFilter('label', lbl.id)"
                >
                    <span
                        class="h-1.5 w-1.5 shrink-0 rounded-full"
                        :class="getLabelDotClass(lbl.color)"
                    />
                    <span>{{ lbl.name }}</span>
                    <span
                        v-if="
                            (lbl.files_count ?? 0) + (lbl.folders_count ?? 0) >
                            0
                        "
                        class="text-[10px] tabular-nums opacity-75"
                    >
                        {{ (lbl.files_count ?? 0) + (lbl.folders_count ?? 0) }}
                    </span>
                </button>

                <!-- Manage Labels Button -->
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="text-muted-foreground hover:text-foreground h-7 cursor-pointer gap-1.5 rounded-full px-2.5 text-xs"
                    @click="manageLabelsOpen = true"
                >
                    <Tags class="h-3.5 w-3.5" />
                    <span>Kelola Label</span>
                </Button>
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

            <!-- Zip Creation Progress Banner -->
            <div
                v-if="
                    zipJob &&
                    (zipJob.status === 'pending' ||
                        zipJob.status === 'processing')
                "
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50/50 p-3 text-xs dark:border-teal-900/60 dark:bg-teal-950/20"
                role="status"
                aria-live="polite"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <div
                        class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-teal-600 dark:bg-teal-400"
                    />
                    <span
                        class="truncate font-medium text-teal-900 dark:text-teal-200"
                    >
                        Menyiapkan arsip ZIP: {{ zipJob.name }}
                    </span>
                    <span class="text-muted-foreground tabular-nums"
                        >{{ zipJob.progress }}%</span
                    >
                </div>
                <div class="flex items-center gap-3">
                    <div
                        class="bg-muted h-1.5 w-28 overflow-hidden rounded-full sm:w-44"
                    >
                        <div
                            class="h-full bg-teal-600 transition-all duration-300 dark:bg-teal-400"
                            :style="{
                                width: `${Math.max(8, zipJob.progress)}%`,
                            }"
                        />
                    </div>
                </div>
            </div>

            <!-- Zip Creation Done Banner -->
            <div
                v-if="zipJob && zipJob.status === 'done' && showZipDoneBanner"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 text-xs dark:border-emerald-900/60 dark:bg-emerald-950/30"
                role="status"
                aria-live="polite"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <Check
                        class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                    />
                    <span
                        class="truncate font-medium text-emerald-900 dark:text-emerald-200"
                    >
                        Arsip ZIP {{ zipJob.name }} selesai disiapkan{{
                            zipJob.size ? ` (${formatBytes(zipJob.size)})` : ''
                        }}.
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        :href="`/files/zip/${zipJob.id}/download`"
                        class="inline-flex items-center gap-1.5 rounded-md bg-teal-600 px-2.5 py-1 font-medium text-white shadow-2xs hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        download
                    >
                        <Download class="h-3.5 w-3.5" />
                        <span>Unduh berkas ZIP</span>
                    </a>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground h-6 w-6"
                        aria-label="Tutup notifikasi ZIP"
                        @click="showZipDoneBanner = false"
                    >
                        <X class="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            <!-- Zip Creation Failed Banner -->
            <div
                v-if="zipJob && zipJob.status === 'failed'"
                class="flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50/50 p-3 text-xs dark:border-red-900/60 dark:bg-red-950/20"
                role="alert"
            >
                <div
                    class="flex min-w-0 items-center gap-2 text-red-700 dark:text-red-400"
                >
                    <X class="h-4 w-4 shrink-0" />
                    <span class="truncate font-medium">
                        {{ zipJob.error || 'Gagal membuat arsip ZIP.' }}
                    </span>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="text-muted-foreground hover:text-foreground h-6 w-6"
                    aria-label="Tutup notifikasi error"
                    @click="zipJob = null"
                >
                    <X class="h-3.5 w-3.5" />
                </Button>
            </div>

            <!-- Directory Summary Bar -->
            <div
                v-if="!isEmpty && !searchEmpty"
                class="text-muted-foreground flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-xs"
            >
                <div class="flex flex-wrap items-center gap-2">
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
                    <template v-if="selectedFileIds.length > 0">
                        <span>•</span>
                        <span
                            class="font-semibold text-teal-600 tabular-nums dark:text-teal-400"
                        >
                            {{ selectedFileIds.length }} dipilih
                        </span>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground cursor-pointer underline"
                            @click="toggleSelectAll"
                        >
                            {{
                                isAllSelected
                                    ? 'Batalkan pilihan'
                                    : 'Pilih semua'
                            }}
                        </button>
                    </template>
                </div>
                <div v-if="search" class="text-xs">
                    Hasil pencarian untuk:
                    <span class="text-foreground font-medium"
                        >"{{ search }}"</span
                    >
                </div>
            </div>

            <!-- Empty Starred State -->
            <div
                v-if="currentFilter === 'starred' && isEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <Star
                    class="mx-auto mb-3 h-10 w-10 fill-amber-400/20 text-amber-500/80"
                />
                <p class="text-sm font-semibold">
                    Belum ada berkas atau folder favorit
                </p>
                <p class="text-muted-foreground mx-auto mt-1 max-w-md text-xs">
                    Tandai berkas atau folder penting dengan ikon bintang agar
                    dapat dikelompokkan dan ditemukan dengan cepat di sini.
                </p>
                <div class="mt-5 flex items-center justify-center">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="navigateToFilter('all')"
                    >
                        Kembali ke Semua Berkas
                    </Button>
                </div>
            </div>

            <!-- Empty Label State -->
            <div
                v-else-if="currentFilter === 'label' && currentLabel && isEmpty"
                class="rounded-lg border border-dashed p-10 text-center"
            >
                <Tag
                    class="mx-auto mb-3 h-10 w-10 text-teal-600 opacity-70 dark:text-teal-400"
                />
                <p class="text-sm font-semibold">
                    Belum ada berkas dengan label "{{ currentLabel.name }}"
                </p>
                <p class="text-muted-foreground mx-auto mt-1 max-w-md text-xs">
                    Gunakan menu aksi pada berkas atau folder untuk memasang
                    label ini, sehingga berkas dapat dikelompokkan melintasi
                    struktur folder.
                </p>
                <div class="mt-5 flex items-center justify-center">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="navigateToFilter('all')"
                    >
                        Kembali ke Semua Berkas
                    </Button>
                </div>
            </div>

            <!-- Empty Folder State -->
            <div
                v-else-if="isEmpty"
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
                                    class="w-10 px-3 py-3 text-center"
                                    @click.stop
                                >
                                    <div
                                        class="flex items-center justify-center"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isAllSelected"
                                            :indeterminate.prop="
                                                isSomeSelected && !isAllSelected
                                            "
                                            :disabled="sortedFiles.length === 0"
                                            aria-label="Pilih semua berkas"
                                            class="border-border h-4 w-4 cursor-pointer rounded text-teal-600 accent-teal-600 focus:ring-teal-500 disabled:opacity-40 dark:accent-teal-500"
                                            @change="toggleSelectAll"
                                        />
                                    </div>
                                </th>
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
                                <td class="w-10 px-3 py-2.5 text-center">
                                    <button
                                        type="button"
                                        class="text-muted-foreground/40 cursor-pointer rounded p-1 transition-colors hover:text-amber-500 focus:outline-hidden"
                                        :class="{
                                            'text-amber-500': item.is_starred,
                                        }"
                                        :aria-label="
                                            item.is_starred
                                                ? `Hapus bintang dari folder ${item.name}`
                                                : `Bintang folder ${item.name}`
                                        "
                                        :title="
                                            item.is_starred
                                                ? 'Favorit'
                                                : 'Tandai sebagai favorit'
                                        "
                                        @click.stop="toggleStar(item, true)"
                                    >
                                        <Star
                                            class="h-4 w-4"
                                            :class="
                                                item.is_starred
                                                    ? 'fill-amber-400 text-amber-500'
                                                    : 'hover:fill-amber-100 dark:hover:fill-amber-950/40'
                                            "
                                        />
                                    </button>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div
                                        class="flex min-w-0 flex-wrap items-center gap-2 sm:flex-nowrap"
                                    >
                                        <button
                                            type="button"
                                            class="text-foreground flex min-w-0 items-center gap-2.5 text-left font-medium transition-colors hover:text-teal-600 dark:hover:text-teal-400"
                                            @click="navigateToFolder(item.id)"
                                        >
                                            <FolderIcon
                                                class="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-400"
                                            />
                                            <span class="truncate">{{
                                                item.name
                                            }}</span>
                                        </button>
                                        <span
                                            v-if="
                                                item.parent_name &&
                                                currentFilter !== 'all'
                                            "
                                            class="text-muted-foreground/75 truncate text-[10px] font-normal"
                                            :title="`Folder induk: ${item.parent_name}`"
                                        >
                                            dalam {{ item.parent_name }}
                                        </span>
                                        <div
                                            v-if="
                                                item.labels &&
                                                item.labels.length > 0
                                            "
                                            class="flex shrink-0 flex-wrap items-center gap-1"
                                        >
                                            <span
                                                v-for="lbl in item.labels"
                                                :key="lbl.id"
                                                class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium"
                                                :class="
                                                    getLabelColorClasses(
                                                        lbl.color,
                                                    )
                                                "
                                            >
                                                <span
                                                    class="h-1 w-1 shrink-0 rounded-full"
                                                    :class="
                                                        getLabelDotClass(
                                                            lbl.color,
                                                        )
                                                    "
                                                />
                                                <span>{{ lbl.name }}</span>
                                            </span>
                                        </div>
                                    </div>
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
                                            class="text-muted-foreground hover:text-foreground h-8 w-8 cursor-pointer"
                                            :aria-label="`Kelola label folder ${item.name}`"
                                            title="Kelola label"
                                            @click.stop="
                                                openItemLabels(item, true)
                                            "
                                        >
                                            <Tag class="h-3.5 w-3.5" />
                                        </Button>
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
                                v-for="(file, index) in sortedFiles"
                                :key="`file-${file.id}`"
                                class="hover:bg-muted/40 transition-colors"
                                :class="{
                                    'bg-teal-50/50 dark:bg-teal-950/30':
                                        isSelected(file.id),
                                }"
                            >
                                <td
                                    class="w-10 px-3 py-2.5 text-center"
                                    @click.stop
                                >
                                    <div
                                        class="flex items-center justify-center"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isSelected(file.id)"
                                            :aria-label="`Pilih berkas ${file.name}`"
                                            class="border-border h-4 w-4 cursor-pointer rounded text-teal-600 accent-teal-600 focus:ring-teal-500 dark:accent-teal-500"
                                            @click="
                                                handleCheckboxClick(
                                                    $event,
                                                    file.id,
                                                    index,
                                                )
                                            "
                                        />
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="text-muted-foreground/40 shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:text-amber-500 focus:outline-hidden"
                                            :class="{
                                                'text-amber-500':
                                                    file.is_starred,
                                            }"
                                            :aria-label="
                                                file.is_starred
                                                    ? `Hapus bintang dari berkas ${file.name}`
                                                    : `Bintang berkas ${file.name}`
                                            "
                                            :title="
                                                file.is_starred
                                                    ? 'Favorit'
                                                    : 'Tandai sebagai favorit'
                                            "
                                            @click.stop="
                                                toggleStar(file, false)
                                            "
                                        >
                                            <Star
                                                class="h-3.5 w-3.5"
                                                :class="
                                                    file.is_starred
                                                        ? 'fill-amber-400 text-amber-500'
                                                        : 'hover:fill-amber-100 dark:hover:fill-amber-950/40'
                                                "
                                            />
                                        </button>

                                        <template
                                            v-if="hasValidThumbnail(file)"
                                        >
                                            <button
                                                type="button"
                                                class="border-border/60 bg-muted/40 h-7 w-7 shrink-0 cursor-pointer overflow-hidden rounded border transition-transform hover:scale-105 focus-visible:ring-2 focus-visible:ring-teal-500"
                                                :aria-label="`Pratinjau ${file.name}`"
                                                @click="openPreview(file)"
                                            >
                                                <img
                                                    :src="file.thumbnail_url!"
                                                    :alt="file.name"
                                                    loading="lazy"
                                                    class="h-full w-full object-cover"
                                                    @error="
                                                        onThumbnailError(
                                                            file.id,
                                                        )
                                                    "
                                                />
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button
                                                type="button"
                                                class="hover:bg-muted flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded transition-colors focus-visible:ring-2 focus-visible:ring-teal-500"
                                                :aria-label="`Pratinjau ${file.name}`"
                                                @click="openPreview(file)"
                                            >
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
                                            </button>
                                        </template>
                                        <button
                                            type="button"
                                            class="text-foreground cursor-pointer truncate text-left font-medium transition-colors hover:text-teal-600 focus-visible:underline focus-visible:outline-hidden dark:hover:text-teal-400"
                                            :title="file.name"
                                            @click="openPreview(file)"
                                        >
                                            {{ file.name }}
                                        </button>
                                        <span
                                            v-if="
                                                file.folder_name &&
                                                currentFilter !== 'all'
                                            "
                                            class="text-muted-foreground/75 hidden truncate text-[10px] font-normal md:inline"
                                            :title="`Folder: ${file.folder_name}`"
                                        >
                                            dalam {{ file.folder_name }}
                                        </span>
                                        <div
                                            v-if="
                                                file.labels &&
                                                file.labels.length > 0
                                            "
                                            class="flex shrink-0 flex-wrap items-center gap-1"
                                        >
                                            <span
                                                v-for="lbl in file.labels"
                                                :key="lbl.id"
                                                class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium"
                                                :class="
                                                    getLabelColorClasses(
                                                        lbl.color,
                                                    )
                                                "
                                            >
                                                <span
                                                    class="h-1 w-1 shrink-0 rounded-full"
                                                    :class="
                                                        getLabelDotClass(
                                                            lbl.color,
                                                        )
                                                    "
                                                />
                                                <span>{{ lbl.name }}</span>
                                            </span>
                                        </div>
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
                                            v-if="
                                                file.accessible &&
                                                file.is_previewable
                                            "
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-8 w-8"
                                            :aria-label="`Pratinjau ${file.name}`"
                                            title="Pratinjau berkas"
                                            @click="openPreview(file)"
                                        >
                                            <Eye class="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-8 w-8 cursor-pointer"
                                            :aria-label="`Kelola label berkas ${file.name}`"
                                            title="Kelola label"
                                            @click.stop="
                                                openItemLabels(file, false)
                                            "
                                        >
                                            <Tag class="h-3.5 w-3.5" />
                                        </Button>
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
                                <div class="flex items-center gap-1.5">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400"
                                    >
                                        <FolderIcon class="h-5 w-5" />
                                    </div>
                                    <button
                                        type="button"
                                        class="text-muted-foreground/40 cursor-pointer rounded p-1 transition-colors hover:text-amber-500 focus:outline-hidden"
                                        :class="{
                                            'text-amber-500': item.is_starred,
                                        }"
                                        :aria-label="
                                            item.is_starred
                                                ? `Hapus bintang dari folder ${item.name}`
                                                : `Bintang folder ${item.name}`
                                        "
                                        :title="
                                            item.is_starred
                                                ? 'Favorit'
                                                : 'Tandai sebagai favorit'
                                        "
                                        @click.stop="toggleStar(item, true)"
                                    >
                                        <Star
                                            class="h-3.5 w-3.5"
                                            :class="
                                                item.is_starred
                                                    ? 'fill-amber-400 text-amber-500'
                                                    : 'hover:fill-amber-100 dark:hover:fill-amber-950/40'
                                            "
                                        />
                                    </button>
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
                                            @click.stop="
                                                openItemLabels(item, true)
                                            "
                                        >
                                            <Tag class="h-3.5 w-3.5" />
                                            <span>Beri Label</span>
                                        </DropdownMenuItem>
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
                                    v-if="
                                        item.parent_name &&
                                        currentFilter !== 'all'
                                    "
                                    class="text-muted-foreground/80 mt-0.5 truncate text-[10px]"
                                    :title="`Folder induk: ${item.parent_name}`"
                                >
                                    dalam {{ item.parent_name }}
                                </p>
                                <div
                                    v-if="item.labels && item.labels.length > 0"
                                    class="mt-1.5 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="lbl in item.labels"
                                        :key="lbl.id"
                                        class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[9px] font-medium"
                                        :class="getLabelColorClasses(lbl.color)"
                                    >
                                        <span
                                            class="h-1 w-1 shrink-0 rounded-full"
                                            :class="getLabelDotClass(lbl.color)"
                                        />
                                        <span class="max-w-[80px] truncate">{{
                                            lbl.name
                                        }}</span>
                                    </span>
                                </div>
                                <p
                                    class="text-muted-foreground mt-1 text-[11px] tabular-nums"
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
                            v-for="(file, index) in sortedFiles"
                            :key="`grid-file-${file.id}`"
                            role="button"
                            tabindex="0"
                            class="group bg-card hover:bg-muted/30 relative flex cursor-pointer flex-col justify-between rounded-lg border p-3 shadow-2xs transition-all hover:border-teal-500/50 focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:outline-hidden"
                            :class="{
                                'border-teal-500/80 bg-teal-50/20 ring-2 ring-teal-500/80 dark:bg-teal-950/20':
                                    isSelected(file.id),
                            }"
                            :aria-label="`Pratinjau ${file.name}`"
                            @click="openPreview(file)"
                            @keydown.enter="openPreview(file)"
                        >
                            <!-- Selection Checkbox -->
                            <div
                                class="absolute top-2 left-2 z-10 flex items-center justify-center"
                                @click.stop
                            >
                                <input
                                    type="checkbox"
                                    :checked="isSelected(file.id)"
                                    :aria-label="`Pilih berkas ${file.name}`"
                                    class="border-border bg-background/90 h-4 w-4 cursor-pointer rounded text-teal-600 accent-teal-600 shadow-xs focus:ring-teal-500 dark:accent-teal-500"
                                    @click="
                                        handleCheckboxClick(
                                            $event,
                                            file.id,
                                            index,
                                        )
                                    "
                                />
                            </div>

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
                                            getFileInfo(
                                                file.name,
                                                file.mime_type,
                                            ).icon
                                        "
                                        class="h-8 w-8"
                                        :class="
                                            getFileInfo(
                                                file.name,
                                                file.mime_type,
                                            ).iconClass
                                        "
                                    />
                                    <span
                                        class="text-muted-foreground mt-1 text-[10px] font-medium uppercase"
                                    >
                                        {{
                                            file.name.split('.').pop() || 'FILE'
                                        }}
                                    </span>
                                </template>

                                <!-- Action trigger and Star button -->
                                <div
                                    class="absolute top-1 right-1 flex items-center gap-1"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        class="bg-background/80 border-border/30 hover:bg-background flex h-6 w-6 cursor-pointer items-center justify-center rounded border backdrop-blur-xs transition-colors"
                                        :class="
                                            file.is_starred
                                                ? 'text-amber-500'
                                                : 'text-muted-foreground hover:text-amber-500'
                                        "
                                        :aria-label="
                                            file.is_starred
                                                ? `Hapus bintang dari berkas ${file.name}`
                                                : `Bintang berkas ${file.name}`
                                        "
                                        :title="
                                            file.is_starred
                                                ? 'Favorit'
                                                : 'Tandai sebagai favorit'
                                        "
                                        @click.stop="toggleStar(file, false)"
                                    >
                                        <Star
                                            class="h-3 w-3"
                                            :class="
                                                file.is_starred
                                                    ? 'fill-amber-400 text-amber-500'
                                                    : 'hover:fill-amber-100 dark:hover:fill-amber-950/40'
                                            "
                                        />
                                    </button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="text-muted-foreground hover:text-foreground bg-background/80 border-border/30 hover:bg-background h-6 w-6 rounded border backdrop-blur-xs"
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
                                                v-if="
                                                    file.accessible &&
                                                    file.is_previewable
                                                "
                                                class="flex cursor-pointer items-center gap-2"
                                                @click.stop="openPreview(file)"
                                            >
                                                <Eye class="h-3.5 w-3.5" />
                                                <span>Pratinjau</span>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="flex cursor-pointer items-center gap-2"
                                                @click.stop="
                                                    openItemLabels(file, false)
                                                "
                                            >
                                                <Tag class="h-3.5 w-3.5" />
                                                <span>Beri Label</span>
                                            </DropdownMenuItem>
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
                                <p
                                    v-if="
                                        file.folder_name &&
                                        currentFilter !== 'all'
                                    "
                                    class="text-muted-foreground/80 mt-0.5 truncate text-[10px]"
                                    :title="`Folder: ${file.folder_name}`"
                                >
                                    dalam {{ file.folder_name }}
                                </p>
                                <div
                                    v-if="file.labels && file.labels.length > 0"
                                    class="mt-1.5 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="lbl in file.labels"
                                        :key="lbl.id"
                                        class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[9px] font-medium"
                                        :class="getLabelColorClasses(lbl.color)"
                                    >
                                        <span
                                            class="h-1 w-1 shrink-0 rounded-full"
                                            :class="getLabelDotClass(lbl.color)"
                                        />
                                        <span class="max-w-[80px] truncate">{{
                                            lbl.name
                                        }}</span>
                                    </span>
                                </div>
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

        <!-- Floating Bulk Actions Toolbar -->
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-4 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-4 opacity-0"
        >
            <div
                v-if="selectedFileIds.length > 0"
                role="toolbar"
                aria-label="Aksi massal untuk berkas yang dipilih"
                class="bg-card/95 fixed bottom-6 left-1/2 z-40 flex max-w-[95vw] -translate-x-1/2 flex-wrap items-center gap-2 rounded-lg border px-3.5 py-2.5 shadow-lg backdrop-blur-xs sm:gap-3"
            >
                <div class="flex items-center gap-2 border-r pr-1 sm:pr-2">
                    <span
                        class="inline-flex h-6 min-w-6 items-center justify-center rounded-md bg-teal-50 px-1.5 text-xs font-semibold text-teal-700 tabular-nums dark:bg-teal-950/60 dark:text-teal-300"
                    >
                        {{ selectedFileIds.length }}
                    </span>
                    <span
                        class="text-foreground text-xs font-medium whitespace-nowrap"
                    >
                        berkas dipilih
                    </span>
                </div>

                <div class="flex items-center gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="isSubmittingBulkStar"
                        class="hover:bg-muted h-9 cursor-pointer gap-1.5 text-xs font-medium"
                        @click="submitBulkStar(true)"
                    >
                        <Star
                            class="h-3.5 w-3.5 fill-amber-400 text-amber-500"
                        />
                        <span>Favoritkan</span>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="hover:bg-muted h-9 cursor-pointer gap-1.5 text-xs font-medium"
                        @click="bulkLabelsOpen = true"
                    >
                        <Tag
                            class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400"
                        />
                        <span>Beri Label</span>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="hover:bg-muted h-9 cursor-pointer gap-1.5 text-xs font-medium"
                        @click="openBulkMove"
                    >
                        <FolderInput
                            class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400"
                        />
                        <span>Pindahkan</span>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="isZipping"
                        class="hover:bg-muted h-9 cursor-pointer gap-1.5 text-xs font-medium"
                        @click="startBulkZip"
                    >
                        <Download
                            class="h-3.5 w-3.5 text-teal-600 dark:text-teal-400"
                        />
                        <span>Unduh ZIP</span>
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-9 cursor-pointer gap-1.5 text-xs font-medium text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40"
                        @click="bulkDeleteOpen = true"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        <span>Hapus</span>
                    </Button>
                </div>

                <div class="border-l pl-1 sm:pl-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground h-8 w-8 cursor-pointer"
                        aria-label="Batalkan pilihan berkas"
                        title="Batalkan pilihan (Esc)"
                        @click="clearSelection"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </transition>

        <!-- Global Modals: Bulk Move Files -->
        <Dialog v-model:open="bulkMoveOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitBulkMove">
                    <DialogHeader>
                        <DialogTitle>
                            Pindahkan {{ selectedFileIds.length }} berkas
                        </DialogTitle>
                        <DialogDescription>
                            Pilih folder tujuan untuk memindahkan seluruh berkas
                            yang dipilih. Berkas di akun cloud storage tidak
                            perlu diunggah ulang.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="bulk-move-target">Folder Tujuan</Label>
                        <Select v-model="bulkMoveTarget">
                            <SelectTrigger
                                id="bulk-move-target"
                                class="text-xs"
                            >
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
                            :disabled="isSubmittingBulkMove"
                            class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        >
                            Pindahkan Berkas
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Bulk Delete Files -->
        <Dialog v-model:open="bulkDeleteOpen">
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitBulkDelete">
                    <DialogHeader>
                        <DialogTitle>
                            Hapus {{ selectedFileIds.length }} berkas?
                        </DialogTitle>
                        <DialogDescription>
                            Berkas yang dipilih akan dihapus secara permanen
                            dari akun cloud storage penyimpanannya dan dari
                            sistem. Tindakan ini tidak dapat dibatalkan.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        class="bg-muted/30 max-h-36 space-y-1 overflow-y-auto rounded-md border p-2.5 text-xs"
                    >
                        <div
                            v-for="file in selectedFilesPreview"
                            :key="file.id"
                            class="text-muted-foreground flex items-center justify-between truncate"
                        >
                            <span class="truncate">{{ file.name }}</span>
                            <span class="ml-2 shrink-0 tabular-nums">{{
                                formatBytes(file.size)
                            }}</span>
                        </div>
                        <p
                            v-if="
                                selectedFileIds.length >
                                selectedFilesPreview.length
                            "
                            class="text-muted-foreground border-t pt-1 text-[11px] italic"
                        >
                            ...dan
                            {{
                                selectedFileIds.length -
                                selectedFilesPreview.length
                            }}
                            berkas lainnya
                        </p>
                    </div>

                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="isSubmittingBulkDelete"
                        >
                            Hapus Berkas
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Single Item Labels -->
        <Dialog v-model:open="itemLabelsOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="saveItemLabels">
                    <DialogHeader>
                        <DialogTitle>
                            Label
                            {{ labelingTarget?.isFolder ? 'Folder' : 'Berkas' }}
                        </DialogTitle>
                        <DialogDescription>
                            Pilih label untuk mengelompokkan "{{
                                labelingTarget?.name
                            }}" melintasi struktur hierarkis.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        v-if="allLabels.length === 0"
                        class="rounded-md border border-dashed p-4 text-center"
                    >
                        <p class="text-muted-foreground mb-3 text-xs">
                            Anda belum memiliki label. Buat label baru atau
                            gunakan koleksi default.
                        </p>
                        <div class="flex items-center justify-center gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="h-8 text-xs"
                                @click="seedDefaultLabels"
                            >
                                Gunakan Label Default
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                class="h-8 bg-teal-600 text-xs text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                                @click="
                                    itemLabelsOpen = false;
                                    manageLabelsOpen = true;
                                "
                            >
                                Kelola Label
                            </Button>
                        </div>
                    </div>

                    <div v-else class="max-h-60 space-y-2 overflow-y-auto pr-1">
                        <div
                            v-for="lbl in allLabels"
                            :key="lbl.id"
                            class="flex cursor-pointer items-center justify-between rounded-md border p-2 transition-colors"
                            :class="
                                labelingTarget?.labelIds.includes(lbl.id)
                                    ? 'border-teal-500/50 bg-teal-50/50 dark:bg-teal-950/30'
                                    : 'hover:bg-muted/40'
                            "
                            @click="toggleTargetLabel(lbl.id)"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    class="h-2 w-2 shrink-0 rounded-full"
                                    :class="getLabelDotClass(lbl.color)"
                                />
                                <span
                                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                    :class="getLabelColorClasses(lbl.color)"
                                >
                                    {{ lbl.name }}
                                </span>
                            </div>
                            <input
                                type="checkbox"
                                :checked="
                                    labelingTarget?.labelIds.includes(lbl.id)
                                "
                                :aria-label="`Pilih label ${lbl.name}`"
                                class="border-border h-4 w-4 cursor-pointer rounded text-teal-600 accent-teal-600 focus:ring-teal-500 dark:accent-teal-500"
                                @click.stop="toggleTargetLabel(lbl.id)"
                            />
                        </div>
                    </div>

                    <DialogFooter
                        class="flex items-center justify-between sm:justify-between"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground hover:text-foreground text-xs"
                            @click="
                                itemLabelsOpen = false;
                                manageLabelsOpen = true;
                            "
                        >
                            <Tags class="mr-1 h-3.5 w-3.5" />
                            <span>Kelola Label</span>
                        </Button>
                        <div class="flex items-center gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="ghost" size="sm"
                                    >Batal</Button
                                >
                            </DialogClose>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="isSavingItemLabels"
                                class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                            >
                                Simpan Label
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Manage Labels -->
        <Dialog v-model:open="manageLabelsOpen">
            <DialogContent class="sm:max-w-lg">
                <div class="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Kelola Label</DialogTitle>
                        <DialogDescription>
                            Buat, ubah, atau hapus sistem label berwarna untuk
                            mengelompokkan berkas secara fleksibel.
                        </DialogDescription>
                    </DialogHeader>

                    <!-- Create New Label Form -->
                    <form
                        class="bg-muted/20 space-y-3 rounded-lg border p-3"
                        @submit.prevent="createLabel"
                    >
                        <div class="text-foreground text-xs font-semibold">
                            Tambah Label Baru
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <Input
                                v-model="newLabelName"
                                placeholder="Nama label (misal: Pajak, Kerja...)"
                                class="h-8 flex-1 text-xs"
                                maxlength="50"
                                aria-label="Nama label baru"
                                required
                            />
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="
                                    isCreatingLabel || !newLabelName.trim()
                                "
                                class="h-8 shrink-0 bg-teal-600 text-xs text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                            >
                                <Plus class="mr-1 h-3.5 w-3.5" />
                                <span>Tambah Label</span>
                            </Button>
                        </div>
                        <!-- Color Palette Options -->
                        <div class="space-y-1">
                            <span class="text-muted-foreground text-[11px]"
                                >Pilih warna label:</span
                            >
                            <div
                                class="flex flex-wrap items-center gap-1.5 pt-0.5"
                            >
                                <button
                                    v-for="color in PRESET_COLORS"
                                    :key="color.name"
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] transition-all"
                                    :class="[
                                        color.bg,
                                        color.text,
                                        color.border,
                                        newLabelColor === color.name
                                            ? 'scale-105 font-bold shadow-2xs ring-2 ring-teal-500'
                                            : 'opacity-70 hover:opacity-100',
                                    ]"
                                    :aria-label="`Pilih warna ${color.label}`"
                                    @click="newLabelColor = color.name"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="color.dot"
                                    />
                                    <span>{{ color.label }}</span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Existing Labels List -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <span
                                class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"
                            >
                                Daftar Label ({{ allLabels.length }})
                            </span>
                            <Button
                                v-if="allLabels.length === 0"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="h-7 text-xs"
                                @click="seedDefaultLabels"
                            >
                                Buat Label Default
                            </Button>
                        </div>

                        <div
                            v-if="allLabels.length === 0"
                            class="text-muted-foreground rounded-md border border-dashed p-6 text-center text-xs"
                        >
                            Belum ada label dibuat. Tambahkan label pertama di
                            atas atau klik "Buat Label Default".
                        </div>

                        <div
                            v-else
                            class="max-h-64 space-y-1.5 overflow-y-auto pr-1"
                        >
                            <div
                                v-for="lbl in allLabels"
                                :key="lbl.id"
                                class="bg-card flex items-center justify-between rounded-md border p-2 text-xs transition-colors"
                            >
                                <!-- If editing this label -->
                                <template v-if="editingLabel?.id === lbl.id">
                                    <div class="flex-1 space-y-2 pr-2">
                                        <Input
                                            v-model="editLabelName"
                                            class="h-7 w-full text-xs"
                                            maxlength="50"
                                            aria-label="Ubah nama label"
                                            required
                                        />
                                        <div
                                            class="flex flex-wrap items-center gap-1"
                                        >
                                            <button
                                                v-for="color in PRESET_COLORS"
                                                :key="`edit-${color.name}`"
                                                type="button"
                                                class="inline-flex cursor-pointer items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px]"
                                                :class="[
                                                    color.bg,
                                                    color.text,
                                                    color.border,
                                                    editLabelColor ===
                                                    color.name
                                                        ? 'font-bold ring-2 ring-teal-500'
                                                        : 'opacity-70 hover:opacity-100',
                                                ]"
                                                @click="
                                                    editLabelColor = color.name
                                                "
                                            >
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full"
                                                    :class="color.dot"
                                                />
                                                <span>{{ color.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-1"
                                    >
                                        <Button
                                            type="button"
                                            size="sm"
                                            class="h-7 bg-teal-600 px-2 text-xs text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                                            :disabled="
                                                isSavingEditLabel ||
                                                !editLabelName.trim()
                                            "
                                            @click="saveEditLabel"
                                        >
                                            Simpan
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="text-muted-foreground hover:text-foreground h-7 px-2 text-xs"
                                            @click="cancelEditLabel"
                                        >
                                            Batal
                                        </Button>
                                    </div>
                                </template>

                                <!-- Normal view for this label -->
                                <template v-else>
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full"
                                            :class="getLabelDotClass(lbl.color)"
                                        />
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                getLabelColorClasses(lbl.color)
                                            "
                                        >
                                            {{ lbl.name }}
                                        </span>
                                        <span
                                            class="text-muted-foreground text-[11px] tabular-nums"
                                        >
                                            ({{
                                                (lbl.files_count ?? 0) +
                                                (lbl.folders_count ?? 0)
                                            }}
                                            item)
                                        </span>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-1"
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground hover:text-foreground h-7 w-7 cursor-pointer"
                                            :aria-label="`Ubah label ${lbl.name}`"
                                            title="Ubah label"
                                            @click="startEditLabel(lbl)"
                                        >
                                            <Pencil class="h-3 w-3" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="text-muted-foreground h-7 w-7 cursor-pointer hover:text-red-600"
                                            :aria-label="`Hapus label ${lbl.name}`"
                                            title="Hapus label"
                                            @click="deletingLabel = lbl"
                                        >
                                            <Trash2 class="h-3 w-3" />
                                        </Button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="outline" size="sm"
                                >Tutup</Button
                            >
                        </DialogClose>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Delete Label Confirmation -->
        <Dialog
            :open="deletingLabel !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) deletingLabel = null;
                }
            "
        >
            <DialogContent>
                <form class="space-y-5" @submit.prevent="submitDeleteLabel">
                    <DialogHeader>
                        <DialogTitle>
                            Hapus label {{ deletingLabel?.name }}?
                        </DialogTitle>
                        <DialogDescription>
                            Label ini akan dilepas dari semua berkas dan folder
                            yang menggunakannya. Berkas dan foldernya sendiri
                            tetap aman dan tidak dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">Batal</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="isDeletingLabel"
                        >
                            Hapus Label
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Global Modals: Bulk Labels -->
        <Dialog v-model:open="bulkLabelsOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitBulkLabels">
                    <DialogHeader>
                        <DialogTitle>
                            Beri Label {{ selectedFileIds.length }} Berkas
                        </DialogTitle>
                        <DialogDescription>
                            Pilih label yang ingin disematkan ke seluruh berkas
                            yang sedang dipilih.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        v-if="allLabels.length === 0"
                        class="text-muted-foreground rounded-md border border-dashed p-4 text-center text-xs"
                    >
                        <p class="mb-2">Anda belum memiliki label.</p>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            class="h-8 text-xs"
                            @click="seedDefaultLabels"
                        >
                            Buat Label Default
                        </Button>
                    </div>

                    <div v-else class="max-h-60 space-y-2 overflow-y-auto pr-1">
                        <div
                            v-for="lbl in allLabels"
                            :key="lbl.id"
                            class="flex cursor-pointer items-center justify-between rounded-md border p-2 transition-colors"
                            :class="
                                bulkSelectedLabelIds.includes(lbl.id)
                                    ? 'border-teal-500/50 bg-teal-50/50 dark:bg-teal-950/30'
                                    : 'hover:bg-muted/40'
                            "
                            @click="toggleBulkLabelSelection(lbl.id)"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    class="h-2 w-2 shrink-0 rounded-full"
                                    :class="getLabelDotClass(lbl.color)"
                                />
                                <span
                                    class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                    :class="getLabelColorClasses(lbl.color)"
                                >
                                    {{ lbl.name }}
                                </span>
                            </div>
                            <input
                                type="checkbox"
                                :checked="bulkSelectedLabelIds.includes(lbl.id)"
                                :aria-label="`Pilih label ${lbl.name}`"
                                class="border-border h-4 w-4 cursor-pointer rounded text-teal-600 accent-teal-600 focus:ring-teal-500 dark:accent-teal-500"
                                @click.stop="toggleBulkLabelSelection(lbl.id)"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost" size="sm"
                                >Batal</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="
                                isSubmittingBulkLabels ||
                                bulkSelectedLabelIds.length === 0
                            "
                            class="bg-teal-600 text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        >
                            Terapkan Label
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- File Preview Modal (PDF, Video, Audio, Image, Text) -->
        <FilePreviewModal v-model:open="previewOpen" :file="previewFile" />
    </div>
</template>
