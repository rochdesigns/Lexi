<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';
import { store, update, destroy } from '@/actions/App/Http/Controllers/BudgetCategoryController';
import { edit as editCategories } from '@/routes/categories';

type Category = {
    id: number;
    name: string;
    group: string | null;
    icon: string;
    color: string;
    limit_amount: number;
};

const props = defineProps<{
    categories: Category[];
    editCategoryId: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Categories', href: editCategories() }],
    },
});

// ── Grouped categories ────────────────────────────────────────────────────────

const groupedCategories = computed(() => {
    const groups: Record<string, Category[]> = {};
    for (const cat of props.categories) {
        const key = cat.group ?? '';
        if (!groups[key]) { groups[key] = []; }
        groups[key].push(cat);
    }
    // Sort: named groups first (alphabetically), then ungrouped
    return Object.entries(groups).sort(([a], [b]) => {
        if (a === '' && b !== '') { return 1; }
        if (a !== '' && b === '') { return -1; }
        return a.localeCompare(b);
    });
});

// ── Category CRUD ─────────────────────────────────────────────────────────────

const ICON_OPTIONS = [
    'shopping_cart', 'restaurant', 'directions_car', 'movie', 'favorite',
    'home', 'bolt', 'phone_iphone', 'local_pharmacy', 'checkroom',
    'school', 'flight', 'fitness_center', 'pets', 'subscriptions',
    'coffee', 'spa', 'sports_esports', 'celebration', 'card_giftcard',
    'build', 'brush', 'music_note', 'child_care', 'savings',
];

const COLOR_OPTIONS = [
    '#2f7d5b', '#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355',
    '#1e7e6b', '#2e5fa3', '#7c3aad', '#b8602a', '#9b3a1f', '#4a5568',
];

const catModalOpen = ref(false);
const editingCat = ref<Category | null>(null);
const catForm = reactive({ name: '', group: '', icon: 'shopping_cart', color: '#2f7d5b', limit_amount: '' });
const deletingCatId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

function openAddCat() {
    editingCat.value = null;
    Object.assign(catForm, { name: '', group: '', icon: 'shopping_cart', color: '#2f7d5b', limit_amount: '' });
    catModalOpen.value = true;
}

function openEditCat(cat: Category) {
    editingCat.value = cat;
    Object.assign(catForm, {
        name: cat.name,
        group: cat.group ?? '',
        icon: cat.icon,
        color: cat.color,
        limit_amount: String(cat.limit_amount),
    });
    catModalOpen.value = true;
}

const catLimitEvaluated = computed(() => evaluateArithmetic(catForm.limit_amount));
const catLimitHasExpr = computed(() => hasArithmetic(catForm.limit_amount) && catLimitEvaluated.value !== null);

function submitCat() {
    const data = {
        name: catForm.name,
        group: catForm.group || null,
        icon: catForm.icon,
        color: catForm.color,
        limit_amount: (catLimitEvaluated.value ?? parseFloat(catForm.limit_amount)) || 0,
    };
    if (editingCat.value) {
        router.patch(update({ budgetCategory: editingCat.value.id }).url, data, {
            onSuccess: () => { catModalOpen.value = false; },
            preserveScroll: true,
        });
    } else {
        router.post(store().url, data, {
            onSuccess: () => { catModalOpen.value = false; },
            preserveScroll: true,
        });
    }
}

function startDeleteCat(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = id;
    deleteTimer = setTimeout(() => { deletingCatId.value = null; }, 3000);
}

function confirmDeleteCat(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = null;
    router.delete(destroy({ budgetCategory: id }).url, { preserveScroll: true });
}

function cancelDeleteCat() {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deletingCatId.value = null;
}

// ── Deep-link edit ────────────────────────────────────────────────────────────

onMounted(() => {
    if (props.editCategoryId) {
        const cat = props.categories.find((c) => c.id === props.editCategoryId);
        if (cat) {
            openEditCat(cat);
        }
    }
});
</script>

<template>
    <Head title="Categories" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Categories"
            description="Manage the budget categories used to track your spending"
        />

        <div>
            <div style="display: flex; align-items: center; justify-content: flex-end; margin-bottom: 16px">
                <button @click="openAddCat"
                    style="display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                    Add category
                </button>
            </div>

            <div v-if="!categories.length" style="display: flex; flex-direction: column; align-items: center; padding: 36px 0; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">category</span>
                <div style="font-size: 13.5px; color: #8a8172">No categories yet</div>
                <button @click="openAddCat"
                    style="display: flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fdfbf5">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add your first category
                </button>
            </div>

            <div v-else style="display: flex; flex-direction: column; gap: 24px">
                <div v-for="([groupName, cats]) in groupedCategories" :key="groupName">
                    <!-- Group label -->
                    <div v-if="groupName" style="font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a49b8a; margin-bottom: 12px">
                        {{ groupName }}
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 14px">
                        <div v-for="cat in cats" :key="cat.id" class="group/cat" style="display: flex; align-items: center; gap: 11px">
                            <span :style="{ background: cat.color + '22' }"
                                style="width: 36px; height: 36px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none">
                                <span class="msf" :style="{ color: cat.color }" style="font-size: 19px">{{ cat.icon }}</span>
                            </span>
                            <div style="flex: 1; min-width: 0">
                                <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 8px">
                                    <span style="font-weight: 600; font-size: 14px; color: #211d17">{{ cat.name }}</span>
                                    <div style="display: flex; align-items: center; gap: 6px; flex: none">
                                        <!-- Edit / delete (hover reveal) -->
                                        <template v-if="deletingCatId !== cat.id">
                                            <div class="flex items-center flex-none">
                                                <div style="display: flex; align-items: baseline; gap: 4px">
                                                    <span style="font-size: 12px; color: #8a8172">Limit ₱{{ cat.limit_amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
                                                </div>
                                                <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/cat:max-w-[76px] flex-shrink-0">
                                                    <div class="flex items-center gap-1" style="padding-left: 8px">
                                                        <button @click.stop="openEditCat(cat)"
                                                            style="width: 28px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                                            <span class="material-symbols-rounded" style="font-size: 15px">edit</span>
                                                        </button>
                                                        <button @click.stop="startDeleteCat(cat.id)"
                                                            style="width: 28px; height: 28px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                                            <span class="material-symbols-rounded" style="font-size: 15px">delete</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <!-- Delete confirm -->
                                        <template v-else>
                                            <button @click="cancelDeleteCat"
                                                style="padding: 0 10px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #6b6355">
                                                Cancel
                                            </button>
                                            <button @click="confirmDeleteCat(cat.id)"
                                                style="padding: 0 10px; height: 28px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #fff">
                                                Delete?
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / edit category modal -->
    <Dialog v-model:open="catModalOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{ editingCat ? 'Edit category' : 'Add category' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitCat" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Name + Group row -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                        <input v-model="catForm.name" required placeholder="e.g. Groceries"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Group</label>
                        <input v-model="catForm.group" placeholder="e.g. Food, Home, Fun"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Monthly limit -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Cycle limit *</label>
                    <div style="position: relative">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                        <input v-model="catForm.limit_amount" type="text" inputmode="decimal" required placeholder="0"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                        <div v-if="catLimitHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ catLimitEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                    </div>
                </div>

                <!-- Color picker -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Color</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px">
                        <button v-for="c in COLOR_OPTIONS" :key="c" type="button" @click="catForm.color = c"
                            style="width: 28px; height: 28px; border-radius: 8px; border: 2.5px solid; cursor: pointer; transition: transform .1s"
                            :style="{
                                background: c,
                                borderColor: catForm.color === c ? '#211d17' : c,
                                transform: catForm.color === c ? 'scale(1.15)' : 'scale(1)',
                            }">
                        </button>
                    </div>
                </div>

                <!-- Icon picker -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Icon</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px">
                        <button v-for="ic in ICON_OPTIONS" :key="ic" type="button" @click="catForm.icon = ic"
                            style="width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .1s"
                            :style="{
                                background: catForm.icon === ic ? catForm.color + '20' : '#f1ede3',
                                borderColor: catForm.icon === ic ? catForm.color : 'transparent',
                            }">
                            <span class="msf" style="font-size: 18px" :style="{ color: catForm.icon === ic ? catForm.color : '#8a8172' }">{{ ic }}</span>
                        </button>
                    </div>
                </div>

                <!-- Preview -->
                <div style="display: flex; align-items: center; gap: 10px; background: #f7f4ee; border-radius: 12px; padding: 12px 14px">
                    <span :style="{ background: catForm.color + '22' }" style="width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: catForm.color }" style="font-size: 21px">{{ catForm.icon }}</span>
                    </span>
                    <div>
                        <div style="font-weight: 600; font-size: 14px; color: #211d17">{{ catForm.name || 'Category name' }}</div>
                        <div style="font-size: 12px; color: #8a8172; margin-top: 1px">
                            <span v-if="catForm.group">{{ catForm.group }} · </span>
                            Limit ₱{{ catForm.limit_amount || '0' }}
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="catModalOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingCat ? 'Save changes' : 'Add category' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
