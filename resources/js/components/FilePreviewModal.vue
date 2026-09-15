<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    AlertCircle,
    Check,
    Copy,
    Download,
    ExternalLink,
    FileAudio,
    FileCode,
    FileImage,
    FileText,
    FileVideo,
    Loader2,
    Maximize2,
    Minimize2,
    Music,
    Pause,
    Play,
    Repeat,
    RotateCcw,
    RotateCw,
    Volume2,
    VolumeX,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { formatBytes } from '@/lib/format';

export interface PreviewFile {
    id: number;
    name: string;
    size: number;
    mime_type: string | null;
    account_label: string;
    accessible: boolean;
    is_chunked?: boolean;
    preview_type?: string;
    preview_url?: string | null;
}

const props = defineProps<{
    open: boolean;
    file: PreviewFile | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', val: boolean): void;
}>();

// Type Detection
const fileType = computed(() => {
    if (!props.file) return 'unsupported';
    if (props.file.preview_type) return props.file.preview_type;

    const ext = props.file.name.split('.').pop()?.toLowerCase() || '';
    const mime = (props.file.mime_type || '').toLowerCase();

    if (ext === 'pdf' || mime === 'application/pdf') return 'pdf';
    if (
        ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'].includes(
            ext,
        ) ||
        mime.startsWith('image/')
    )
        return 'image';
    if (
        ['mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v', 'ogv'].includes(ext) ||
        mime.startsWith('video/')
    )
        return 'video';
    if (
        ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'weba', 'opus'].includes(
            ext,
        ) ||
        mime.startsWith('audio/')
    )
        return 'audio';
    if (
        [
            'txt',
            'md',
            'json',
            'csv',
            'log',
            'js',
            'ts',
            'vue',
            'php',
            'py',
            'html',
            'css',
            'xml',
            'yaml',
            'yml',
            'sql',
        ].includes(ext) ||
        mime.startsWith('text/')
    )
        return 'text';

    return 'unsupported';
});

// Preparation & Polling State
const isPreparing = ref(false);
const preparationError = ref<string | null>(null);
let pollTimer: number | null = null;

// Media References
const videoRef = ref<HTMLVideoElement | null>(null);
const audioRef = ref<HTMLAudioElement | null>(null);

// Audio Player States
const isAudioPlaying = ref(false);
const audioCurrentTime = ref(0);
const audioDuration = ref(0);
const audioVolume = ref(0.85);
const isAudioMuted = ref(false);
const audioPlaybackRate = ref(1);
const isAudioLooping = ref(false);

// Image Controls State
const imageZoom = ref(1);
const imageRotation = ref(0);
const imageNaturalWidth = ref(0);
const imageNaturalHeight = ref(0);

// Text Content State
const textContent = ref<string | null>(null);
const isTextLoading = ref(false);
const isTextCopied = ref(false);

// Formatting Helper
function formatTime(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

// Check Preparation Status (for chunked or on-demand cached files)
async function checkStatus(): Promise<boolean> {
    if (!props.file) return false;

    try {
        const response = await fetch(`/files/${props.file.id}/preview-status`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            preparationError.value = 'Gagal memeriksa kesiapan berkas.';
            isPreparing.value = false;
            return false;
        }

        const data = await response.json();
        if (data.ready) {
            isPreparing.value = false;
            preparationError.value = null;
            return true;
        }

        return false;
    } catch {
        preparationError.value =
            'Terjadi kendala jaringan saat memeriksa berkas.';
        isPreparing.value = false;
        return false;
    }
}

function startPollingStatus() {
    stopPollingStatus();
    isPreparing.value = true;
    preparationError.value = null;

    pollTimer = window.setInterval(async () => {
        const ready = await checkStatus();
        if (ready) {
            stopPollingStatus();
            onFileReady();
        }
    }, 1500);
}

function stopPollingStatus() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// Reset States when Modal Opens or Changes File
async function resetViewer() {
    stopPollingStatus();
    isPreparing.value = false;
    preparationError.value = null;

    // Reset Audio
    if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value.currentTime = 0;
    }
    isAudioPlaying.value = false;
    audioCurrentTime.value = 0;
    audioDuration.value = 0;

    // Reset Image
    imageZoom.value = 1;
    imageRotation.value = 0;
    imageNaturalWidth.value = 0;
    imageNaturalHeight.value = 0;

    // Reset Text
    textContent.value = null;
    isTextLoading.value = false;
    isTextCopied.value = false;

    if (!props.file || !props.open) return;

    // If chunked file, verify readiness
    if (props.file.is_chunked) {
        isPreparing.value = true;
        const ready = await checkStatus();
        if (!ready) {
            startPollingStatus();
            return;
        }
    }

    onFileReady();
}

function onFileReady() {
    if (fileType.value === 'text') {
        loadTextContent();
    }
}

async function loadTextContent() {
    if (!props.file) return;
    isTextLoading.value = true;
    try {
        const res = await fetch(`/files/${props.file.id}/preview`);
        if (res.ok) {
            textContent.value = await res.text();
        } else {
            preparationError.value = 'Gagal memuat teks berkas.';
        }
    } catch {
        preparationError.value = 'Gagal mengambil isi teks berkas.';
    } finally {
        isTextLoading.value = false;
    }
}

async function copyTextContent() {
    if (!textContent.value) return;
    try {
        await navigator.clipboard.writeText(textContent.value);
        isTextCopied.value = true;
        setTimeout(() => {
            isTextCopied.value = false;
        }, 2000);
    } catch {
        // clipboard fallback
    }
}

// Audio Controls
function toggleAudioPlay() {
    if (!audioRef.value) return;
    if (audioRef.value.paused) {
        audioRef.value.play();
    } else {
        audioRef.value.pause();
    }
}

function onAudioTimeUpdate() {
    if (!audioRef.value) return;
    audioCurrentTime.value = audioRef.value.currentTime;
}

function onAudioLoadedMetadata() {
    if (!audioRef.value) return;
    audioDuration.value = audioRef.value.duration || 0;
    audioRef.value.volume = isAudioMuted.value ? 0 : audioVolume.value;
}

function seekAudio(event: MouseEvent) {
    if (!audioRef.value || !audioDuration.value) return;
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const ratio = Math.max(
        0,
        Math.min(1, (event.clientX - rect.left) / rect.width),
    );
    const newTime = ratio * audioDuration.value;
    audioRef.value.currentTime = newTime;
    audioCurrentTime.value = newTime;
}

function skipAudio(seconds: number) {
    if (!audioRef.value) return;
    const newTime = Math.max(
        0,
        Math.min(audioDuration.value, audioRef.value.currentTime + seconds),
    );
    audioRef.value.currentTime = newTime;
}

function toggleAudioMute() {
    isAudioMuted.value = !isAudioMuted.value;
    if (audioRef.value) {
        audioRef.value.muted = isAudioMuted.value;
    }
}

function onVolumeChange(event: Event) {
    const val = Number((event.target as HTMLInputElement).value);
    audioVolume.value = val;
    isAudioMuted.value = val === 0;
    if (audioRef.value) {
        audioRef.value.volume = val;
        audioRef.value.muted = isAudioMuted.value;
    }
}

function cycleAudioRate() {
    const rates = [1, 1.25, 1.5, 2];
    const currentIndex = rates.indexOf(audioPlaybackRate.value);
    const nextRate = rates[(currentIndex + 1) % rates.length] || 1;
    audioPlaybackRate.value = nextRate;
    if (audioRef.value) {
        audioRef.value.playbackRate = nextRate;
    }
}

function toggleAudioLoop() {
    isAudioLooping.value = !isAudioLooping.value;
    if (audioRef.value) {
        audioRef.value.loop = isAudioLooping.value;
    }
}

// Image Controls
function zoomIn() {
    imageZoom.value = Math.min(4, Number((imageZoom.value + 0.25).toFixed(2)));
}

function zoomOut() {
    imageZoom.value = Math.max(
        0.25,
        Number((imageZoom.value - 0.25).toFixed(2)),
    );
}

function resetZoom() {
    imageZoom.value = 1;
    imageRotation.value = 0;
}

function rotateImage() {
    imageRotation.value = (imageRotation.value + 90) % 360;
}

function onImageLoaded(event: Event) {
    const img = event.target as HTMLImageElement;
    imageNaturalWidth.value = img.naturalWidth;
    imageNaturalHeight.value = img.naturalHeight;
}

// Keyboard shortcuts
function handleKeyDown(event: KeyboardEvent) {
    if (!props.open) return;

    // Esc is handled by Dialog itself
    if (
        event.key === ' ' &&
        (fileType.value === 'audio' || fileType.value === 'video')
    ) {
        const targetTag = (event.target as HTMLElement)?.tagName?.toLowerCase();
        if (targetTag !== 'input' && targetTag !== 'textarea') {
            event.preventDefault();
            if (fileType.value === 'audio') {
                toggleAudioPlay();
            } else if (videoRef.value) {
                if (videoRef.value.paused) {
                    videoRef.value.play();
                } else {
                    videoRef.value.pause();
                }
            }
        }
    } else if (event.key === 'ArrowRight' && fileType.value === 'audio') {
        skipAudio(5);
    } else if (event.key === 'ArrowLeft' && fileType.value === 'audio') {
        skipAudio(-5);
    } else if (event.key === '+' || event.key === '=') {
        if (fileType.value === 'image') zoomIn();
    } else if (event.key === '-') {
        if (fileType.value === 'image') zoomOut();
    } else if (event.key === '0') {
        if (fileType.value === 'image') resetZoom();
    } else if (event.key.toLowerCase() === 'r') {
        if (fileType.value === 'image') rotateImage();
    }
}

watch(
    () => [props.open, props.file?.id],
    () => {
        resetViewer();
    },
    { immediate: true },
);

onMounted(() => {
    window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    stopPollingStatus();
});

const fileTypeTitle = computed(() => {
    switch (fileType.value) {
        case 'pdf':
            return 'Dokumen PDF';
        case 'image':
            return 'Pratinjau Gambar';
        case 'video':
            return 'Pemutar Video';
        case 'audio':
            return 'Pemutar Musik';
        case 'text':
            return 'Isi Berkas Teks';
        default:
            return 'Pratinjau Berkas';
    }
});
</script>

<template>
    <Dialog
        :open="open"
        @update:open="
            (val: boolean) => {
                emit('update:open', val);
            }
        "
    >
        <DialogContent
            class="border-border/80 bg-background flex h-[85vh] max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden p-0 shadow-xl sm:max-w-4xl"
            :show-close-button="false"
        >
            <!-- Header Bar -->
            <DialogHeader
                class="bg-muted/30 flex shrink-0 flex-row items-center justify-between border-b px-4 py-3"
            >
                <div class="flex min-w-0 items-center gap-2.5 pr-2">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-teal-50 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400"
                    >
                        <FileImage
                            v-if="fileType === 'image'"
                            class="h-4 w-4"
                        />
                        <FileVideo
                            v-else-if="fileType === 'video'"
                            class="h-4 w-4"
                        />
                        <FileAudio
                            v-else-if="fileType === 'audio'"
                            class="h-4 w-4"
                        />
                        <FileText
                            v-else-if="fileType === 'pdf'"
                            class="h-4 w-4"
                        />
                        <FileCode
                            v-else-if="fileType === 'text'"
                            class="h-4 w-4"
                        />
                        <FileText v-else class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <DialogTitle
                            class="text-foreground truncate text-sm font-semibold"
                            :title="file?.name"
                        >
                            {{ file?.name || 'Pratinjau Berkas' }}
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            Pratinjau konten berkas {{ file?.name || 'berkas' }}
                        </DialogDescription>
                        <div
                            class="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]"
                        >
                            <span>{{ fileTypeTitle }}</span>
                            <span>•</span>
                            <span class="font-medium tabular-nums">
                                {{ formatBytes(file?.size || 0) }}
                            </span>
                            <template v-if="file?.account_label">
                                <span>•</span>
                                <span
                                    class="max-w-[150px] truncate sm:max-w-[220px]"
                                >
                                    {{ file.account_label }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Right Action Buttons -->
                <div class="flex shrink-0 items-center gap-1">
                    <Button
                        v-if="file?.accessible"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground h-8 w-8"
                        as-child
                    >
                        <a
                            :href="`/files/${file.id}/preview`"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Buka berkas di tab baru"
                            title="Buka di tab baru"
                        >
                            <ExternalLink class="h-4 w-4" />
                        </a>
                    </Button>

                    <Button
                        v-if="file?.accessible"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground h-8 w-8"
                        as-child
                    >
                        <a
                            :href="`/files/${file.id}/download`"
                            aria-label="Unduh berkas"
                            title="Unduh berkas"
                        >
                            <Download class="h-4 w-4" />
                        </a>
                    </Button>

                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-foreground h-8 w-8"
                        aria-label="Tutup pratinjau"
                        @click="emit('update:open', false)"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </DialogHeader>

            <!-- Main Viewer Area -->
            <div
                class="bg-muted/10 relative flex min-h-0 flex-1 flex-col items-center justify-center overflow-hidden"
            >
                <!-- 1. Preparing / Background Merge State -->
                <div
                    v-if="isPreparing"
                    class="flex flex-col items-center justify-center space-y-3 p-8 text-center"
                    role="status"
                    aria-live="polite"
                >
                    <Loader2
                        class="h-8 w-8 animate-spin text-teal-600 dark:text-teal-400"
                    />
                    <p class="text-foreground text-sm font-semibold">
                        Menyiapkan berkas dari cloud storage...
                    </p>
                    <p class="text-muted-foreground max-w-sm text-xs">
                        Berkas sedang disatukan atau disiapkan untuk pemutaran
                        langsung tanpa perlu diunduh terlebih dahulu.
                    </p>
                </div>

                <!-- 2. Preparation / Loading Error -->
                <div
                    v-else-if="preparationError"
                    class="flex flex-col items-center justify-center space-y-3 p-8 text-center"
                    role="alert"
                >
                    <AlertCircle
                        class="h-8 w-8 text-red-600 dark:text-red-400"
                    />
                    <p class="text-foreground text-sm font-semibold">
                        {{ preparationError }}
                    </p>
                    <p class="text-muted-foreground max-w-sm text-xs">
                        Silakan coba muat ulang atau gunakan opsi unduh berkas
                        secara langsung.
                    </p>
                    <div class="mt-2 flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            class="h-8 text-xs"
                            @click="resetViewer"
                        >
                            <RotateCw class="mr-1 h-3.5 w-3.5" />
                            Coba Lagi
                        </Button>
                        <Button
                            v-if="file?.accessible"
                            size="sm"
                            class="h-8 bg-teal-600 text-xs text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                            as-child
                        >
                            <a :href="`/files/${file.id}/download`">
                                <Download class="mr-1 h-3.5 w-3.5" />
                                Unduh Berkas
                            </a>
                        </Button>
                    </div>
                </div>

                <!-- 3. Image Viewer -->
                <div
                    v-else-if="fileType === 'image' && file"
                    class="relative flex h-full w-full flex-col items-center justify-center overflow-hidden bg-zinc-950/95"
                >
                    <div
                        class="flex h-full w-full items-center justify-center overflow-auto p-4 select-none"
                    >
                        <img
                            :src="`/files/${file.id}/preview`"
                            :alt="file.name"
                            class="max-h-full max-w-full object-contain transition-transform duration-200 ease-out"
                            :style="{
                                transform: `scale(${imageZoom}) rotate(${imageRotation}deg)`,
                            }"
                            @load="onImageLoaded"
                            @error="
                                preparationError =
                                    'Gagal memuat gambar dari server.'
                            "
                        />
                    </div>

                    <!-- Image floating toolbar -->
                    <div
                        class="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-zinc-800 bg-zinc-900/90 px-3 py-1.5 text-zinc-200 shadow-lg backdrop-blur-xs"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 text-zinc-300 hover:bg-zinc-800 hover:text-white"
                            aria-label="Perkecil gambar"
                            @click="zoomOut"
                        >
                            <ZoomOut class="h-3.5 w-3.5" />
                        </Button>
                        <span
                            class="cursor-pointer px-1.5 text-xs font-semibold text-zinc-200 tabular-nums select-none hover:text-teal-400"
                            title="Klik untuk reset zoom"
                            @click="resetZoom"
                        >
                            {{ Math.round(imageZoom * 100) }}%
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 text-zinc-300 hover:bg-zinc-800 hover:text-white"
                            aria-label="Perbesar gambar"
                            @click="zoomIn"
                        >
                            <ZoomIn class="h-3.5 w-3.5" />
                        </Button>
                        <div class="mx-0.5 h-3.5 w-px bg-zinc-800" />
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 text-zinc-300 hover:bg-zinc-800 hover:text-white"
                            aria-label="Putar gambar 90 derajat"
                            title="Putar 90° (R)"
                            @click="rotateImage"
                        >
                            <RotateCw class="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7 text-zinc-300 hover:bg-zinc-800 hover:text-white"
                            aria-label="Kembalikan ukuran normal"
                            title="Reset ukuran (0)"
                            @click="resetZoom"
                        >
                            <Maximize2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>

                    <!-- Image natural resolution badge -->
                    <div
                        v-if="imageNaturalWidth && imageNaturalHeight"
                        class="absolute bottom-4 left-4 hidden items-center rounded-sm border border-zinc-800 bg-zinc-900/80 px-2 py-1 text-[10px] text-zinc-400 tabular-nums sm:inline-flex"
                    >
                        {{ imageNaturalWidth }} × {{ imageNaturalHeight }} px
                    </div>
                </div>

                <!-- 4. Video Stream Player -->
                <div
                    v-else-if="fileType === 'video' && file"
                    class="relative flex h-full w-full items-center justify-center bg-black"
                >
                    <video
                        ref="videoRef"
                        :src="`/files/${file.id}/preview`"
                        controls
                        autoplay
                        playsinline
                        controlsList="nodownload"
                        class="h-full max-h-full w-full object-contain"
                        @error="
                            preparationError = 'Gagal memutar stream video.'
                        "
                    >
                        Browser Anda tidak mendukung pemutaran video HTML5
                        langsung.
                    </video>
                </div>

                <!-- 5. Audio Player (Pemutar Musik) -->
                <div
                    v-else-if="fileType === 'audio' && file"
                    class="from-background to-muted/30 flex h-full w-full flex-col items-center justify-center bg-gradient-to-b p-6"
                >
                    <audio
                        ref="audioRef"
                        :src="`/files/${file.id}/preview`"
                        @play="isAudioPlaying = true"
                        @pause="isAudioPlaying = false"
                        @timeupdate="onAudioTimeUpdate"
                        @loadedmetadata="onAudioLoadedMetadata"
                        @ended="isAudioPlaying = false"
                        @error="preparationError = 'Gagal memutar audio.'"
                    />

                    <div
                        class="bg-card w-full max-w-md space-y-6 rounded-xl border p-6 shadow-sm"
                    >
                        <!-- Cover / Visual Accent -->
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-teal-500/10 text-teal-600 ring-1 ring-teal-500/20 dark:bg-teal-500/20 dark:text-teal-400"
                            >
                                <Music
                                    class="h-8 w-8"
                                    :class="{ 'animate-pulse': isAudioPlaying }"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3
                                    class="text-foreground truncate text-sm font-semibold"
                                    :title="file.name"
                                >
                                    {{ file.name }}
                                </h3>
                                <p class="text-muted-foreground mt-0.5 text-xs">
                                    {{
                                        file.account_label ||
                                        'Penyimpanan Cloud'
                                    }}
                                </p>
                                <Badge
                                    variant="secondary"
                                    class="mt-2 text-[10px] font-normal"
                                >
                                    {{
                                        file.name
                                            .split('.')
                                            .pop()
                                            ?.toUpperCase() || 'AUDIO'
                                    }}
                                </Badge>
                            </div>
                        </div>

                        <!-- Progress Bar and Timers -->
                        <div class="space-y-1.5">
                            <div
                                class="bg-muted group relative h-2 w-full cursor-pointer overflow-hidden rounded-full"
                                role="slider"
                                :aria-valuenow="audioCurrentTime"
                                :aria-valuemin="0"
                                :aria-valuemax="audioDuration"
                                aria-label="Waktu pemutaran audio"
                                @click="seekAudio"
                            >
                                <div
                                    class="h-full rounded-full bg-teal-600 transition-all duration-100 group-hover:bg-teal-500 dark:bg-teal-400"
                                    :style="{
                                        width: `${audioDuration ? (audioCurrentTime / audioDuration) * 100 : 0}%`,
                                    }"
                                />
                            </div>
                            <div
                                class="text-muted-foreground flex items-center justify-between text-xs tabular-nums"
                            >
                                <span>{{ formatTime(audioCurrentTime) }}</span>
                                <span>{{ formatTime(audioDuration) }}</span>
                            </div>
                        </div>

                        <!-- Control Buttons -->
                        <div class="flex items-center justify-between pt-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="text-muted-foreground hover:text-foreground h-8 w-8"
                                :class="{
                                    'font-bold text-teal-600 dark:text-teal-400':
                                        isAudioLooping,
                                }"
                                :aria-pressed="isAudioLooping"
                                aria-label="Putar berulang"
                                title="Ulangi lagu"
                                @click="toggleAudioLoop"
                            >
                                <Repeat class="h-4 w-4" />
                            </Button>

                            <div class="flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-foreground h-9 w-9"
                                    aria-label="Mundur 10 detik"
                                    title="Mundur 10 detik"
                                    @click="skipAudio(-10)"
                                >
                                    <RotateCcw class="h-4 w-4" />
                                </Button>

                                <Button
                                    size="icon"
                                    class="h-12 w-12 rounded-full bg-teal-600 text-white shadow-md hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                                    :aria-label="
                                        isAudioPlaying ? 'Jeda' : 'Putar'
                                    "
                                    @click="toggleAudioPlay"
                                >
                                    <Pause
                                        v-if="isAudioPlaying"
                                        class="h-5 w-5"
                                    />
                                    <Play
                                        v-else
                                        class="ml-0.5 h-5 w-5 fill-current"
                                    />
                                </Button>

                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-foreground h-9 w-9"
                                    aria-label="Maju 10 detik"
                                    title="Maju 10 detik"
                                    @click="skipAudio(10)"
                                >
                                    <RotateCw class="h-4 w-4" />
                                </Button>
                            </div>

                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground hover:text-foreground h-8 px-2 text-xs font-semibold tabular-nums"
                                aria-label="Kecepatan pemutaran"
                                title="Ubah kecepatan pemutaran"
                                @click="cycleAudioRate"
                            >
                                {{ audioPlaybackRate }}x
                            </Button>
                        </div>

                        <!-- Volume Slider -->
                        <div
                            class="text-muted-foreground flex items-center gap-2.5 border-t pt-2"
                        >
                            <button
                                type="button"
                                class="hover:text-foreground transition-colors"
                                :aria-label="
                                    isAudioMuted ? 'Bunyikan' : 'Bisukan'
                                "
                                @click="toggleAudioMute"
                            >
                                <VolumeX
                                    v-if="isAudioMuted || audioVolume === 0"
                                    class="h-4 w-4"
                                />
                                <Volume2 v-else class="h-4 w-4" />
                            </button>
                            <input
                                type="range"
                                min="0"
                                max="1"
                                step="0.05"
                                :value="isAudioMuted ? 0 : audioVolume"
                                class="bg-muted h-1.5 flex-1 cursor-pointer appearance-none rounded-full accent-teal-600 dark:accent-teal-400"
                                aria-label="Volume audio"
                                @input="onVolumeChange"
                            />
                            <span
                                class="w-8 text-right text-[11px] tabular-nums"
                            >
                                {{
                                    isAudioMuted
                                        ? '0%'
                                        : `${Math.round(audioVolume * 100)}%`
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 6. PDF Reader -->
                <div
                    v-else-if="fileType === 'pdf' && file"
                    class="relative h-full w-full"
                >
                    <iframe
                        :src="`/files/${file.id}/preview`"
                        class="h-full w-full border-0 bg-white"
                        title="Pratinjau Dokumen PDF"
                    />
                </div>

                <!-- 7. Text / Code Viewer -->
                <div
                    v-else-if="fileType === 'text' && file"
                    class="bg-card flex h-full w-full flex-col"
                >
                    <div
                        class="text-muted-foreground bg-muted/20 flex shrink-0 items-center justify-between border-b px-4 py-2 text-xs"
                    >
                        <span class="font-mono text-[11px]">
                            {{ file.name }}
                        </span>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 gap-1 text-xs"
                                @click="copyTextContent"
                            >
                                <Check
                                    v-if="isTextCopied"
                                    class="h-3.5 w-3.5 text-teal-500"
                                />
                                <Copy v-else class="h-3.5 w-3.5" />
                                <span>{{
                                    isTextCopied ? 'Tersalin' : 'Salin'
                                }}</span>
                            </Button>
                        </div>
                    </div>
                    <div
                        class="flex-1 overflow-auto p-4 font-mono text-xs leading-relaxed"
                    >
                        <div
                            v-if="isTextLoading"
                            class="text-muted-foreground flex items-center gap-2"
                        >
                            <Loader2
                                class="h-4 w-4 animate-spin text-teal-600"
                            />
                            <span>Memuat isi berkas...</span>
                        </div>
                        <pre
                            v-else
                            class="text-foreground whitespace-pre-wrap select-text"
                        ><code>{{ textContent }}</code></pre>
                    </div>
                </div>

                <!-- 8. Unsupported Fallback -->
                <div
                    v-else
                    class="flex flex-col items-center justify-center space-y-3 p-8 text-center"
                >
                    <FileText class="text-muted-foreground/60 h-10 w-10" />
                    <p class="text-foreground text-sm font-semibold">
                        Pratinjau langsung tidak tersedia untuk format berkas
                        ini
                    </p>
                    <p class="text-muted-foreground max-w-sm text-xs">
                        Format ini dapat diunduh untuk dibuka dengan aplikasi
                        yang sesuai di perangkat Anda.
                    </p>
                    <Button
                        v-if="file?.accessible"
                        size="sm"
                        class="mt-2 h-8 bg-teal-600 text-xs text-white hover:bg-teal-700 dark:bg-teal-500 dark:text-zinc-950 dark:hover:bg-teal-400"
                        as-child
                    >
                        <a :href="`/files/${file.id}/download`">
                            <Download class="mr-1.5 h-3.5 w-3.5" />
                            Unduh Berkas Sekarang
                        </a>
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
