<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';

const { open: openConfirm } = useConfirm();
const toast = useToast();

const props = defineProps({
    files: {
        type: Array,
        default: () => [],
    },
    folders: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({
            total: 0,
            queued: 0,
            completed: 0,
            failed: 0,
        }),
    },
    availableModels: {
        type: Array,
        default: () => ['small'],
    },
    filter: {
        type: String,
        default: 'recent',
    },
    activeFolderId: {
        type: [Number, null],
        default: null,
    },
    activeFolder: {
        type: [Object, null],
        default: null,
    },
});

const creatingFolderFor = ref(undefined);
const folderNameInput = ref(null);

const LAST_MODEL_KEY = 'escribelo_last_model';
const LAST_LANGUAGE_KEY = 'escribelo_last_language';

const initialModel = (() => {
    const saved = localStorage.getItem(LAST_MODEL_KEY);
    return saved && props.availableModels.includes(saved) ? saved : 'small';
})();
const initialLanguage = localStorage.getItem(LAST_LANGUAGE_KEY) || 'es';

const form = useForm({
    paths: [],
    transcription_folder_id: '',
    model: initialModel,
    language: initialLanguage,
    clean_audio: false,
});

watch(() => form.model, (value) => {
    if (value) {
        localStorage.setItem(LAST_MODEL_KEY, value);
    }
});
watch(() => form.language, (value) => {
    if (value) {
        localStorage.setItem(LAST_LANGUAGE_KEY, value);
    }
});

const selectedFiles = ref([]);
const libraryOpen = ref(false);
const libraryLoading = ref(false);
const libraryError = ref(null);
const libraryListing = ref({
    path: null,
    parent: null,
    directories: [],
    files: [],
});

const LIBRARY_LAST_PATH_KEY = 'escribelo_library_last_path';

const dirnameOf = (path) => {
    if (! path) {
        return null;
    }
    const idx = Math.max(path.lastIndexOf('\\'), path.lastIndexOf('/'));
    return idx > 0 ? path.slice(0, idx) : null;
};

const openLibrary = async () => {
    libraryOpen.value = true;
    const remembered = libraryListing.value.path
        || localStorage.getItem(LIBRARY_LAST_PATH_KEY);
    await loadLibrary(remembered, { fallbackToDrives: true });
};

const closeLibrary = () => {
    libraryOpen.value = false;
};

const loadLibrary = async (path, { fallbackToDrives = false } = {}) => {
    libraryLoading.value = true;
    libraryError.value = null;
    try {
        const url = new URL(route('library.browse'), window.location.origin);
        if (path) {
            url.searchParams.set('path', path);
        }
        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (! response.ok) {
            if (fallbackToDrives && path) {
                localStorage.removeItem(LIBRARY_LAST_PATH_KEY);
                await loadLibrary(null);
                return;
            }
            const data = await response.json().catch(() => ({}));
            libraryError.value = data.message || 'No se pudo abrir la carpeta.';
            return;
        }
        libraryListing.value = await response.json();
    } catch (err) {
        libraryError.value = err.message || 'Error al cargar la biblioteca.';
    } finally {
        libraryLoading.value = false;
    }
};

const isFileSelected = (path) => selectedFiles.value.some((f) => f.path === path);

const togglePathSelection = (file) => {
    const idx = selectedFiles.value.findIndex((f) => f.path === file.path);
    if (idx >= 0) {
        selectedFiles.value.splice(idx, 1);
    } else {
        selectedFiles.value.push(file);
        const dir = dirnameOf(file.path);
        if (dir) {
            localStorage.setItem(LIBRARY_LAST_PATH_KEY, dir);
        }
    }
    form.paths = selectedFiles.value.map((f) => f.path);
};

const removeSelected = (path) => {
    selectedFiles.value = selectedFiles.value.filter((f) => f.path !== path);
    form.paths = selectedFiles.value.map((f) => f.path);
};

const confirmLibrarySelection = () => {
    libraryOpen.value = false;
};

const folderForm = useForm({
    name: '',
    parent_id: null,
});

const startCreatingFolder = async (parentId = null) => {
    creatingFolderFor.value = parentId;
    folderForm.reset();
    folderForm.parent_id = parentId;
    folderForm.clearErrors();
    await nextTick();
    folderNameInput.value?.focus();
};

const cancelCreatingFolder = () => {
    creatingFolderFor.value = undefined;
    folderForm.reset();
    folderForm.clearErrors();
};

const submitFolder = () => {
    folderForm.post(route('folders.store'), {
        preserveScroll: true,
        onSuccess: () => {
            creatingFolderFor.value = undefined;
            folderForm.reset();
        },
    });
};

const draggingFileId = ref(null);
const dragOverFolderId = ref(undefined);

const onFileDragStart = (event, fileId) => {
    draggingFileId.value = fileId;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(fileId));
};

const onFileDragEnd = () => {
    draggingFileId.value = null;
    dragOverFolderId.value = undefined;
};

const onFolderDragOver = (event, folderId) => {
    if (draggingFileId.value === null) {
        return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    dragOverFolderId.value = folderId;
};

const onFolderDragLeave = (folderId) => {
    if (dragOverFolderId.value === folderId) {
        dragOverFolderId.value = undefined;
    }
};

const onFolderDrop = (event, folderId) => {
    event.preventDefault();
    const fileId = draggingFileId.value ?? Number(event.dataTransfer.getData('text/plain'));
    dragOverFolderId.value = undefined;
    draggingFileId.value = null;

    if (! fileId) {
        return;
    }

    router.patch(
        route('transcriptions.folder', fileId),
        { transcription_folder_id: folderId },
        { preserveScroll: true },
    );
};

const hasFiles = computed(() => props.files.length > 0);

const hasInFlight = computed(() =>
    props.files.some((f) => f.status === 'queued' || f.status === 'processing'),
);

let pollTimer = null;
let lastNavigationAt = 0;

const stopPoll = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

const startPoll = () => {
    stopPoll();
    pollTimer = setInterval(() => {
        // Skip ticks that fall within the settling window of a real navigation
        // so an in-flight poll can't stomp the just-navigated content.
        if (Date.now() - lastNavigationAt < 1500) {
            return;
        }
        router.reload({
            only: ['files', 'stats'],
            preserveUrl: true,
            preserveScroll: true,
            preserveState: true,
        });
    }, 2000);
};

const removeBeforeHook = router.on('before', (event) => {
    const visit = event.detail?.visit;
    if (! visit) {
        return;
    }
    // Real navigations don't preserveUrl; our polls do. Cancel any in-flight
    // poll and arm a short skip window.
    if (visit.preserveUrl !== true) {
        lastNavigationAt = Date.now();
        try {
            router.cancel();
        } catch (e) {
            // best-effort cancel
        }
    }
});

watch(
    hasInFlight,
    (active) => {
        if (active) {
            startPoll();
        } else {
            stopPoll();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopPoll();
    if (typeof removeBeforeHook === 'function') {
        removeBeforeHook();
    }
});

const submit = () => {
    form.post(route('transcriptions.fromPaths'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('paths');
            selectedFiles.value = [];
        },
    });
};

const addExistingOpen = ref(false);
const addExistingLoading = ref(false);
const addExistingError = ref(null);
const unfiledFiles = ref([]);
const selectedToMove = ref(new Set());

const openAddExisting = async () => {
    addExistingOpen.value = true;
    selectedToMove.value = new Set();
    addExistingError.value = null;
    addExistingLoading.value = true;
    try {
        const response = await fetch(route('transcriptions.unfiled'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (! response.ok) {
            addExistingError.value = 'No se pudo cargar el listado.';
            return;
        }
        const data = await response.json();
        unfiledFiles.value = data.files || [];
    } catch (err) {
        addExistingError.value = err.message || 'Error al cargar el listado.';
    } finally {
        addExistingLoading.value = false;
    }
};

const closeAddExisting = () => {
    addExistingOpen.value = false;
    selectedToMove.value = new Set();
};

const toggleMoveSelection = (id) => {
    const next = new Set(selectedToMove.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedToMove.value = next;
};

const toggleAllMoveSelection = () => {
    if (selectedToMove.value.size === unfiledFiles.value.length) {
        selectedToMove.value = new Set();
    } else {
        selectedToMove.value = new Set(unfiledFiles.value.map((f) => f.id));
    }
};

const finalizeMove = () => {
    if (! props.activeFolder || selectedToMove.value.size === 0) {
        return;
    }
    router.post(
        route('transcriptions.moveBulk'),
        {
            transcription_folder_id: props.activeFolder.id,
            ids: Array.from(selectedToMove.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                addExistingOpen.value = false;
                selectedToMove.value = new Set();
            },
            onError: () => toast.error('No se pudieron mover los archivos.'),
        },
    );
};

const deleteTranscription = async (file) => {
    const ok = await openConfirm({
        title: 'Eliminar transcripción',
        message: `¿Eliminar la transcripción de "${file.original_name}"? El audio original no se va a borrar.`,
        confirmText: 'Eliminar',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) {
        return;
    }
    router.delete(route('transcriptions.destroy', file.id), {
        preserveScroll: true,
        onError: () => toast.error('No se pudo eliminar la transcripción.'),
    });
};

const statusLabel = (status) => ({
    queued: 'En cola',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Error',
    cancelled: 'Cancelado',
}[status] || status);

const modelIcon = (model) => ({
    small: '🐇',
    medium: '🦊',
    'large-v3': '🐋',
}[model] || '');

const statusClass = (status) => ({
    queued: 'bg-amber-100 text-amber-700',
    processing: 'bg-blue-100 text-blue-700',
    completed: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-rose-100 text-rose-700',
    cancelled: 'bg-gray-100 text-gray-600',
}[status] || 'bg-gray-100 text-gray-600');

const formatDuration = (seconds) => {
    if (!seconds) {
        return '-';
    }

    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m ${rest}s`;
};

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
</script>

<template>
    <Head title="Archivos recientes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="max-w-2xl">
                    <h2 class="text-xl font-semibold leading-tight text-gray-900 sm:text-2xl">
                        Transcribe tus audios con IA
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Subí tus grabaciones y obtené el texto en minutos. Elegí
                        el modelo según la velocidad y precisión que necesités.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 shadow-sm"
                        >
                            <span class="text-base" aria-hidden="true">🐇</span>
                            <span class="font-semibold">small</span>
                            <span class="text-gray-500">· Rápido</span>
                        </span>
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 shadow-sm"
                        >
                            <span class="text-base" aria-hidden="true">🦊</span>
                            <span class="font-semibold">medium</span>
                            <span class="text-gray-500">· Equilibrado</span>
                        </span>
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 shadow-sm"
                        >
                            <span class="text-base" aria-hidden="true">🐋</span>
                            <span class="font-semibold">large-v3</span>
                            <span class="text-gray-500">· Máxima precisión</span>
                        </span>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    @click="openLibrary"
                >
                    Elegir de la biblioteca
                </button>
            </div>
        </template>

        <div class="bg-slate-50 py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-[240px_1fr] lg:px-8">
                <aside class="space-y-4">
                    <section class="rounded-md bg-slate-900 p-4 text-white shadow-sm">
                        <p class="text-sm font-semibold">
                            {{ stats.queued }} en proceso
                        </p>
                        <div class="mt-3 h-2 rounded-full bg-slate-700">
                            <div
                                class="h-2 rounded-full bg-blue-500"
                                :style="{ width: stats.total ? `${Math.min((stats.completed / stats.total) * 100, 100)}%` : '0%' }"
                            />
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-slate-300">Total</dt>
                                <dd class="font-semibold">{{ stats.total }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-300">Listos</dt>
                                <dd class="font-semibold">{{ stats.completed }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="space-y-2">
                        <h3 class="px-2 text-xs font-bold uppercase tracking-wide text-gray-500">
                            Accesos directos
                        </h3>
                        <nav class="rounded-md border border-gray-200 bg-white p-1 shadow-sm">
                            <Link
                                :href="route('dashboard')"
                                class="flex items-center gap-2 rounded px-2 py-2 text-sm transition"
                                :class="filter === 'recent' ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-700 hover:bg-gray-50'"
                            >
                                <svg
                                    class="h-4 w-4"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M4 6h4v4H4zM10 6h4v4h-4zM16 6h4v4h-4zM4 14h4v4H4zM10 14h4v4h-4zM16 14h4v4h-4z"
                                    />
                                </svg>
                                Recientes
                            </Link>
                            <Link
                                :href="route('dashboard', { filter: 'unfiled' })"
                                class="flex items-center gap-2 rounded px-2 py-2 text-sm transition"
                                :class="filter === 'unfiled' ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-700 hover:bg-gray-50'"
                            >
                                <svg
                                    class="h-4 w-4"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                </svg>
                                Sin ordenar
                            </Link>
                        </nav>
                    </section>

                    <section class="space-y-2">
                        <div class="flex items-center justify-between px-2">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500">
                                Carpetas
                            </h3>
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                title="Nuevo tema principal"
                                aria-label="Nuevo tema principal"
                                @click="startCreatingFolder(null)"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M12 4.5v15m7.5-7.5h-15"
                                    />
                                </svg>
                            </button>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-white p-2 shadow-sm">
                            <form
                                v-if="creatingFolderFor === null"
                                class="space-y-2 p-1"
                                @submit.prevent="submitFolder"
                            >
                                <input
                                    ref="folderNameInput"
                                    v-model="folderForm.name"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Nombre del tema principal"
                                    class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    @keydown.esc="cancelCreatingFolder"
                                >
                                <p
                                    v-if="folderForm.errors.name"
                                    class="text-xs text-rose-600"
                                >
                                    {{ folderForm.errors.name }}
                                </p>
                                <div class="flex gap-2">
                                    <button
                                        type="submit"
                                        class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-2 py-1 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="folderForm.processing || folderForm.name.trim() === ''"
                                    >
                                        Crear
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex flex-1 items-center justify-center rounded-md border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-600 transition hover:bg-gray-50"
                                        @click="cancelCreatingFolder"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                            <p
                                v-if="creatingFolderFor === undefined && folders.length === 0"
                                class="px-2 py-2 text-sm text-gray-500"
                            >
                                Sin carpetas
                            </p>
                            <template
                                v-for="folder in folders"
                                :key="folder.id"
                            >
                                <div
                                    class="group flex items-center justify-between rounded text-sm transition"
                                    :class="[
                                        activeFolderId === folder.id ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-700 hover:bg-gray-50',
                                        { 'ring-2 ring-blue-400 bg-blue-100': dragOverFolderId === folder.id },
                                    ]"
                                    @dragover="onFolderDragOver($event, folder.id)"
                                    @dragleave="onFolderDragLeave(folder.id)"
                                    @drop="onFolderDrop($event, folder.id)"
                                >
                                    <Link
                                        :href="route('dashboard', { folder: folder.id })"
                                        class="flex flex-1 items-center gap-2 truncate px-2 py-2"
                                        :title="folder.name"
                                    >
                                        <span aria-hidden="true">📁</span>
                                        <span class="truncate">{{ folder.name }}</span>
                                    </Link>
                                    <div class="flex items-center gap-1 pr-2">
                                        <button
                                            type="button"
                                            class="flex h-5 w-5 items-center justify-center rounded-full text-gray-400 opacity-0 transition hover:bg-gray-200 hover:text-gray-700 focus:opacity-100 group-hover:opacity-100"
                                            title="Nuevo tema secundario"
                                            aria-label="Nuevo tema secundario"
                                            @click="startCreatingFolder(folder.id)"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="h-3.5 w-3.5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                stroke-width="2.5"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M12 4.5v15m7.5-7.5h-15"
                                                />
                                            </svg>
                                        </button>
                                        <span class="text-xs text-gray-400">{{ folder.files_count }}</span>
                                    </div>
                                </div>
                                <form
                                    v-if="creatingFolderFor === folder.id"
                                    class="ml-4 space-y-2 border-l-2 border-blue-200 p-2"
                                    @submit.prevent="submitFolder"
                                >
                                    <input
                                        ref="folderNameInput"
                                        v-model="folderForm.name"
                                        type="text"
                                        maxlength="255"
                                        placeholder="Nombre del tema secundario"
                                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        @keydown.esc="cancelCreatingFolder"
                                    >
                                    <p
                                        v-if="folderForm.errors.name"
                                        class="text-xs text-rose-600"
                                    >
                                        {{ folderForm.errors.name }}
                                    </p>
                                    <div class="flex gap-2">
                                        <button
                                            type="submit"
                                            class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-2 py-1 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                            :disabled="folderForm.processing || folderForm.name.trim() === ''"
                                        >
                                            Crear
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex flex-1 items-center justify-center rounded-md border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-600 transition hover:bg-gray-50"
                                            @click="cancelCreatingFolder"
                                        >
                                            Cancelar
                                        </button>
                                    </div>
                                </form>
                                <div
                                    v-for="child in folder.children"
                                    :key="child.id"
                                    class="ml-4 flex items-center justify-between border-l-2 border-gray-100 text-sm transition"
                                    :class="[
                                        activeFolderId === child.id ? 'bg-gray-100 font-semibold text-gray-900' : 'text-gray-600 hover:bg-gray-50',
                                        { '!border-blue-400 ring-2 ring-blue-400 bg-blue-100': dragOverFolderId === child.id },
                                    ]"
                                    @dragover="onFolderDragOver($event, child.id)"
                                    @dragleave="onFolderDragLeave(child.id)"
                                    @drop="onFolderDrop($event, child.id)"
                                >
                                    <Link
                                        :href="route('dashboard', { folder: child.id })"
                                        class="flex flex-1 items-center gap-1 truncate px-2 py-1.5"
                                        :title="child.name"
                                    >
                                        <span class="truncate">↳ {{ child.name }}</span>
                                    </Link>
                                    <span class="pr-2 text-xs text-gray-400">{{ child.files_count }}</span>
                                </div>
                            </template>
                        </div>
                    </section>
                </aside>

                <main class="space-y-5">
                    <form
                        class="rounded-md border border-gray-200 bg-white p-4 shadow-sm"
                        @submit.prevent="submit"
                    >
                        <div class="grid gap-4 md:grid-cols-[1fr_180px_140px_auto] md:items-end">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Audios
                                </label>
                                <button
                                    type="button"
                                    class="mt-1 flex min-h-10 w-full items-center rounded-md border border-dashed border-gray-300 px-3 py-2 text-left text-sm text-gray-600 hover:border-blue-400 hover:text-blue-700"
                                    @click="openLibrary"
                                >
                                    <span class="truncate">
                                        {{ selectedFiles.length ? `${selectedFiles.length} archivo${selectedFiles.length === 1 ? '' : 's'} seleccionado${selectedFiles.length === 1 ? '' : 's'}` : 'Elegir de la biblioteca' }}
                                    </span>
                                </button>
                                <p
                                    v-if="form.errors.paths || form.errors['paths.0']"
                                    class="mt-1 text-sm text-rose-600"
                                >
                                    {{ form.errors.paths || form.errors['paths.0'] }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Modelo
                                </label>
                                <select
                                    v-model="form.model"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option
                                        v-for="model in availableModels"
                                        :key="model"
                                        :value="model"
                                    >
                                        {{ modelIcon(model) }} {{ model }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Idioma
                                </label>
                                <select
                                    v-model="form.language"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="es">Español</option>
                                    <option value="en">Inglés</option>
                                    <option value="pt">Portugués</option>
                                    <option value="fr">Francés</option>
                                    <option value="de">Alemán</option>
                                    <option value="it">Italiano</option>
                                    <option value="zh">Chino (mandarín)</option>
                                    <option value="ja">Japonés</option>
                                    <option value="ko">Coreano</option>
                                    <option value="ru">Ruso</option>
                                </select>
                            </div>

                            <button
                                type="submit"
                                class="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="form.processing || selectedFiles.length === 0"
                            >
                                Enviar
                            </button>
                        </div>

                        <label class="mt-3 flex cursor-pointer select-none items-center gap-2 text-sm text-gray-700">
                            <input
                                v-model="form.clean_audio"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                            <span>Reducir ruido antes de transcribir</span>
                            <span class="text-xs text-gray-500">
                                (DeepFilterNet · agrega ~2-3× tiempo de proceso)
                            </span>
                        </label>

                        <ul
                            v-if="selectedFiles.length"
                            class="mt-3 divide-y divide-gray-100 rounded-md border border-gray-200"
                        >
                            <li
                                v-for="file in selectedFiles"
                                :key="file.path"
                                class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-gray-800">{{ file.name }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ file.path }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 text-xs text-rose-600 hover:underline"
                                    @click="removeSelected(file.path)"
                                >
                                    Quitar
                                </button>
                            </li>
                        </ul>
                    </form>

                    <div
                        v-if="libraryOpen"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                        @click.self="closeLibrary"
                    >
                        <div class="flex h-[80vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">Biblioteca local</p>
                                    <p class="truncate text-xs text-gray-500">
                                        {{ libraryListing.path || 'Seleccioná una unidad' }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="text-gray-400 hover:text-gray-700"
                                    @click="closeLibrary"
                                    aria-label="Cerrar"
                                >
                                    ✕
                                </button>
                            </div>
                            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2 text-xs">
                                <button
                                    type="button"
                                    class="rounded border border-gray-200 px-2 py-1 text-gray-600 hover:bg-gray-50 disabled:opacity-40"
                                    :disabled="!libraryListing.parent"
                                    @click="loadLibrary(libraryListing.parent)"
                                >
                                    ← Subir
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-gray-200 px-2 py-1 text-gray-600 hover:bg-gray-50"
                                    @click="loadLibrary(null)"
                                >
                                    Unidades
                                </button>
                                <span
                                    v-if="libraryLoading"
                                    class="text-gray-400"
                                >Cargando...</span>
                                <span
                                    v-if="libraryError"
                                    class="text-rose-600"
                                >{{ libraryError }}</span>
                            </div>
                            <div class="flex-1 overflow-y-auto">
                                <ul class="divide-y divide-gray-100">
                                    <li
                                        v-for="dir in libraryListing.directories"
                                        :key="dir.path"
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50"
                                        @click="loadLibrary(dir.path)"
                                    >
                                        <span class="text-lg" aria-hidden="true">📁</span>
                                        <span class="truncate text-gray-800">{{ dir.name }}</span>
                                    </li>
                                    <li
                                        v-for="file in libraryListing.files"
                                        :key="file.path"
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50"
                                        :class="{ 'bg-blue-50': isFileSelected(file.path) }"
                                        @click="togglePathSelection(file)"
                                    >
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            :checked="isFileSelected(file.path)"
                                            @click.stop="togglePathSelection(file)"
                                        >
                                        <span class="text-lg" aria-hidden="true">🎵</span>
                                        <span class="truncate text-gray-800">{{ file.name }}</span>
                                    </li>
                                    <li
                                        v-if="!libraryLoading && libraryListing.directories.length === 0 && libraryListing.files.length === 0"
                                        class="px-4 py-6 text-center text-sm text-gray-500"
                                    >
                                        Sin carpetas ni audios.
                                    </li>
                                </ul>
                            </div>
                            <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3">
                                <p class="text-xs text-gray-500">
                                    {{ selectedFiles.length }} archivo(s) seleccionado(s)
                                </p>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                        @click="closeLibrary"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                                        :disabled="selectedFiles.length === 0"
                                        @click="confirmLibrarySelection"
                                    >
                                        Listo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 shadow-sm">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Estás viendo
                            </p>
                            <p class="mt-0.5 truncate text-base font-semibold text-gray-900">
                                <template v-if="filter === 'folder' && activeFolder">
                                    📁
                                    <template v-if="activeFolder.parent">
                                        <span class="font-normal text-gray-500">{{ activeFolder.parent.name }} /</span>
                                    </template>
                                    {{ activeFolder.name }}
                                </template>
                                <template v-else-if="filter === 'unfiled'">
                                    Sin ordenar
                                </template>
                                <template v-else>
                                    Recientes
                                </template>
                                <span class="ml-2 text-sm font-normal text-gray-500">
                                    ({{ files.length }})
                                </span>
                            </p>
                        </div>
                        <button
                            v-if="filter === 'folder' && activeFolder"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            @click="openAddExisting"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 4.5v15m7.5-7.5h-15"
                                />
                            </svg>
                            Agregar
                        </button>
                    </div>

                    <div
                        v-if="addExistingOpen"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                        @click.self="closeAddExisting"
                    >
                        <div class="flex h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-800">
                                        Agregar transcripciones a "{{ activeFolder?.name }}"
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        Listado de transcripciones sin carpeta. Marcá las que querés mover.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="text-gray-400 hover:text-gray-700"
                                    aria-label="Cerrar"
                                    @click="closeAddExisting"
                                >
                                    ✕
                                </button>
                            </div>

                            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-2 text-xs text-gray-600">
                                <button
                                    v-if="unfiledFiles.length > 0"
                                    type="button"
                                    class="rounded border border-gray-200 px-2 py-1 hover:bg-gray-50"
                                    @click="toggleAllMoveSelection"
                                >
                                    {{ selectedToMove.size === unfiledFiles.length ? 'Desmarcar todos' : 'Seleccionar todos' }}
                                </button>
                                <span>
                                    {{ selectedToMove.size }} de {{ unfiledFiles.length }} seleccionado(s)
                                </span>
                            </div>

                            <div class="flex-1 overflow-y-auto">
                                <p
                                    v-if="addExistingLoading"
                                    class="p-6 text-center text-sm text-gray-500"
                                >
                                    Cargando...
                                </p>
                                <p
                                    v-else-if="addExistingError"
                                    class="p-6 text-center text-sm text-rose-600"
                                >
                                    {{ addExistingError }}
                                </p>
                                <p
                                    v-else-if="unfiledFiles.length === 0"
                                    class="p-6 text-center text-sm text-gray-500"
                                >
                                    No hay nada que ordenar!
                                </p>
                                <ul
                                    v-else
                                    class="divide-y divide-gray-100"
                                >
                                    <li
                                        v-for="f in unfiledFiles"
                                        :key="f.id"
                                        class="flex cursor-pointer items-center gap-3 px-5 py-3 text-sm hover:bg-gray-50"
                                        :class="{ 'bg-blue-50': selectedToMove.has(f.id) }"
                                        @click="toggleMoveSelection(f.id)"
                                    >
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            :checked="selectedToMove.has(f.id)"
                                            @click.stop="toggleMoveSelection(f.id)"
                                        >
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-medium text-gray-800">
                                                {{ f.original_name }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                {{ formatDate(f.created_at) }}
                                                <span class="mx-1">·</span>
                                                {{ statusLabel(f.status) }}
                                            </p>
                                        </div>
                                    </li>
                                </ul>
                            </div>

                            <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-3">
                                <button
                                    type="button"
                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                    @click="closeAddExisting"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                                    :disabled="selectedToMove.size === 0"
                                    @click="finalizeMove"
                                >
                                    Finalizar
                                </button>
                            </div>
                        </div>
                    </div>

                    <section class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Nombre
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Subido
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Duracion
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Modelo
                                        </th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Estado
                                        </th>
                                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <span class="sr-only">Acciones</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-if="!hasFiles">
                                        <td
                                            colspan="6"
                                            class="px-5 py-12 text-center text-sm text-gray-500"
                                        >
                                            Sin archivos
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="file in files"
                                        :key="file.id"
                                        class="cursor-grab hover:bg-gray-50 active:cursor-grabbing"
                                        :class="{ 'opacity-50': draggingFileId === file.id }"
                                        draggable="true"
                                        @dragstart="onFileDragStart($event, file.id)"
                                        @dragend="onFileDragEnd"
                                    >
                                        <td class="max-w-md px-5 py-4">
                                            <Link
                                                class="block truncate text-sm font-medium text-gray-900 hover:text-blue-700"
                                                :href="route('transcriptions.show', file.id)"
                                                draggable="false"
                                            >
                                                {{ file.original_name }}
                                            </Link>
                                            <p class="mt-1 truncate text-xs text-gray-500">
                                                <template v-if="file.folder">
                                                    📁
                                                    <template v-if="file.folder.parent">
                                                        {{ file.folder.parent.name }} / </template>
                                                    {{ file.folder.name }}
                                                </template>
                                                <span
                                                    v-else
                                                    class="italic text-gray-400"
                                                >
                                                    Sin ordenar
                                                </span>
                                            </p>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                            {{ formatDate(file.created_at) }}
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                            {{ formatDuration(file.duration_seconds) }}
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                            {{ file.model }}
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <div class="flex flex-col gap-1.5">
                                                <span
                                                    class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold"
                                                    :class="statusClass(file.status)"
                                                >
                                                    {{ statusLabel(file.status) }}
                                                </span>
                                                <div
                                                    v-if="file.status === 'queued' || file.status === 'processing'"
                                                    class="flex items-center gap-2"
                                                >
                                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200">
                                                        <div
                                                            class="h-full rounded-full bg-blue-500 transition-all duration-300"
                                                            :style="{ width: `${file.progress || 0}%` }"
                                                        />
                                                    </div>
                                                    <span class="text-xs font-medium tabular-nums text-gray-600">
                                                        {{ file.progress || 0 }}%
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right">
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-400 transition hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-500"
                                                title="Eliminar transcripción"
                                                aria-label="Eliminar transcripción"
                                                @click="deleteTranscription(file)"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                    />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
