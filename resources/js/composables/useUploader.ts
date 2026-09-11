import { router } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';

export interface UploadItem {
    id: string;
    name: string;
    size: number;
    progress: number;
    status: 'uploading' | 'done' | 'failed';
    error?: string;
}

export interface ServerJob {
    id: number;
    name: string;
    size: number;
    status: 'pending' | 'processing' | 'done' | 'failed';
    progress: number;
    error: string | null;
}

/**
 * Client-side upload queue with per-file XHR progress, plus polling of the
 * server-side job queue (the actual provider transfer happens in Horizon).
 */
export function useUploader() {
    const items = ref<UploadItem[]>([]);
    const serverJobs = ref<ServerJob[]>([]);
    let pollTimer: number | null = null;
    let reloadTimer: number | null = null;

    function add(files: File[], folderId: number | null, accountId: number | null): void {
        for (const file of files) {
            const item: UploadItem = {
                id: `${file.name}-${file.size}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
                name: file.name,
                size: file.size,
                progress: 0,
                status: 'uploading',
            };

            items.value.push(item);
            uploadOne(item, file, folderId, accountId);
        }
    }

    function uploadOne(item: UploadItem, file: File, folderId: number | null, accountId: number | null): void {
        const form = new FormData();
        form.append('file', file);

        if (folderId !== null) {
            form.append('folder_id', String(folderId));
        }

        if (accountId !== null) {
            form.append('account_id', String(accountId));
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/files/upload');
        const csrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
        if (csrf) {
            xhr.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(csrf[1]));
        }
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                item.progress = Math.round((event.loaded / event.total) * 90);
            }
        };

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                item.progress = 90;
                item.status = 'done';
            } else {
                item.status = 'failed';
                item.error = `HTTP ${xhr.status}: gagal mengunggah (cek limit ukuran file).`;
            }
            maybeRefresh();
        };

        xhr.onerror = () => {
            item.status = 'failed';
            item.error = 'Koneksi terputus saat mengunggah.';
        };

        xhr.send(form);
    }

    function maybeRefresh(): void {
        if (items.value.some((i) => i.status === 'done')) {
            if (reloadTimer) {
                window.clearTimeout(reloadTimer);
            }
            reloadTimer = window.setTimeout(() => {
                router.reload({ only: ['files', 'folders'] });
            }, 600);
        }
    }

    const uploadingCount = () => items.value.filter((i) => i.status === 'uploading').length;

    function startPolling(): void {
        if (pollTimer !== null) {
            return;
        }

        const tick = async () => {
            if (items.value.some((i) => i.status === 'uploading')) {
                return; // wait until the local XHR queue drains
            }

            try {
                const response = await fetch('/files/jobs', { headers: { Accept: 'application/json' } });
                if (response.ok) {
                    const payload = await response.json();
                    serverJobs.value = payload.jobs ?? [];
                }
            } catch {
                // polling failure is not fatal; try again next tick
            }
        };

        pollTimer = window.setInterval(tick, 2000);
    }

    function stopPolling(): void {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    onUnmounted(stopPolling);

    return {
        items,
        serverJobs,
        uploadingCount,
        add,
        startPolling,
        stopPolling,
    };
}
