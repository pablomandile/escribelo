<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';

const { open: openConfirm } = useConfirm();
const toast = useToast();

defineProps({
    users: { type: Array, default: () => [] },
});

const limitTarget = ref(null);
const limitValue = ref('');
const limitUnlimited = ref(false);

const formatDate = (value) => {
    if (! value) return '-';
    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const approve = (user) => {
    router.post(route('admin.users.approve', user.id), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo aprobar.'),
    });
};

const revoke = async (user) => {
    const ok = await openConfirm({
        title: 'Marcar como pendiente',
        message: `¿Sacar el acceso de "${user.name}"? Va a tener que esperar aprobación de nuevo.`,
        confirmText: 'Sí, marcar pendiente',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    router.post(route('admin.users.revoke', user.id), {}, {
        preserveScroll: true,
        onError: () => toast.error('No se pudo cambiar.'),
    });
};

const togglePromote = async (user) => {
    const becomingAdmin = user.role !== 'admin';
    const ok = await openConfirm({
        title: becomingAdmin ? 'Promover a admin' : 'Degradar a usuario',
        message: becomingAdmin
            ? `Otorgar permisos de administrador a "${user.name}". Tendrá acceso total.`
            : `Quitar permisos de admin a "${user.name}". Solo podrá ver sus propias transcripciones.`,
        confirmText: becomingAdmin ? 'Promover' : 'Degradar',
        cancelText: 'Cancelar',
        danger: ! becomingAdmin,
    });
    if (! ok) return;
    router.patch(route('admin.users.role', user.id), {
        role: becomingAdmin ? 'admin' : 'user',
    }, {
        preserveScroll: true,
        onError: (errors) => toast.error(errors.role || errors.user || 'No se pudo cambiar el rol.'),
    });
};

const openLimit = (user) => {
    limitTarget.value = user;
    limitValue.value = user.audio_limit !== null ? user.audio_limit : '';
    limitUnlimited.value = user.audio_limit === null;
};

const closeLimit = () => {
    limitTarget.value = null;
};

const submitLimit = () => {
    if (! limitTarget.value) return;
    const payload = {
        audio_limit: limitUnlimited.value ? null : Number(limitValue.value || 0),
    };
    router.patch(route('admin.users.limit', limitTarget.value.id), payload, {
        preserveScroll: true,
        onSuccess: closeLimit,
        onError: () => toast.error('No se pudo guardar el límite.'),
    });
};

const remove = async (user) => {
    const ok = await openConfirm({
        title: 'Eliminar usuario',
        message: `¿Eliminar a "${user.name}"? Se borran sus transcripciones, carpetas y resúmenes. Acción irreversible.`,
        confirmText: 'Eliminar',
        cancelText: 'Cancelar',
        danger: true,
    });
    if (! ok) return;
    router.delete(route('admin.users.destroy', user.id), {
        preserveScroll: true,
        onError: (errors) => toast.error(errors.user || 'No se pudo eliminar.'),
    });
};

const statusBadge = (status) => ({
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-200',
    approved: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200',
}[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200');

const statusLabel = (status) => ({
    pending: 'Pendiente',
    approved: 'Aprobado',
}[status] || status);
</script>

<template>
    <Head title="Usuarios" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-900 dark:text-gray-100 sm:text-2xl">
                    Usuarios
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Aprobá registros, ajustá cuotas y administrá roles.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-3">Usuario</th>
                            <th class="px-5 py-3">Rol</th>
                            <th class="px-5 py-3">Estado</th>
                            <th class="px-5 py-3">Cuota</th>
                            <th class="px-5 py-3">Registro</th>
                            <th class="px-5 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="user in users" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-5 py-4">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ user.name }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ user.email }}</div>
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="user.role === 'admin' ? 'bg-purple-100 text-purple-700 dark:bg-violet-900/30 dark:text-violet-200' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200'"
                                >
                                    {{ user.role === 'admin' ? 'Admin' : 'Usuario' }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusBadge(user.approval_status)">
                                    {{ statusLabel(user.approval_status) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 tabular-nums text-gray-700 dark:text-gray-200">
                                <template v-if="user.audio_limit === null">
                                    {{ user.audio_usage }} / ∞
                                </template>
                                <template v-else>
                                    {{ user.audio_usage }} / {{ user.audio_limit }}
                                </template>
                            </td>
                            <td class="px-5 py-4 text-xs text-gray-500 dark:text-gray-400">
                                {{ formatDate(user.created_at) }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <button
                                        v-if="user.approval_status === 'pending'"
                                        type="button"
                                        class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700"
                                        @click="approve(user)"
                                    >
                                        Aprobar
                                    </button>
                                    <button
                                        v-else-if="! user.is_self"
                                        type="button"
                                        class="rounded-md border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/50"
                                        @click="revoke(user)"
                                    >
                                        Marcar pendiente
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                                        @click="openLimit(user)"
                                    >
                                        Cuota
                                    </button>
                                    <button
                                        v-if="! user.is_self"
                                        type="button"
                                        class="rounded-md border border-purple-300 dark:border-violet-800 bg-purple-50 dark:bg-violet-900/30 px-2.5 py-1 text-xs font-semibold text-purple-700 dark:text-violet-200 hover:bg-purple-100 dark:hover:bg-violet-900/50"
                                        @click="togglePromote(user)"
                                    >
                                        {{ user.role === 'admin' ? 'Degradar' : 'Promover' }}
                                    </button>
                                    <button
                                        v-if="! user.is_self"
                                        type="button"
                                        class="rounded-md border border-rose-300 dark:border-rose-800 bg-white dark:bg-gray-800 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:text-rose-200 hover:bg-rose-50 dark:hover:bg-rose-900/30"
                                        @click="remove(user)"
                                    >
                                        Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :show="limitTarget !== null" max-width="md" @close="closeLimit">
            <div class="p-6 dark:bg-gray-800">
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Editar cuota de audios</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Usuario: <strong>{{ limitTarget?.name }}</strong>
                </p>

                <div class="mt-5 space-y-4">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="limitUnlimited" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-blue-600 focus:ring-blue-500" />
                        Sin límite (ilimitado)
                    </label>

                    <div v-if="! limitUnlimited">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Cantidad máxima de audios</label>
                        <input
                            v-model="limitValue"
                            type="number"
                            min="0"
                            max="10000"
                            class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="closeLimit">
                        Cancelar
                    </button>
                    <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700" @click="submitLimit">
                        Guardar
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
