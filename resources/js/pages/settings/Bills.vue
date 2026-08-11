<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import { onClickOutside } from '@vueuse/core';
import Heading from '@/components/Heading.vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy } from '@/actions/App/Http/Controllers/RecurringBillController';
import { edit as editBills } from '@/routes/bills';

type BudgetCategory = {
    id: number;
    name: string;
    icon: string;
    color: string;
};

type Bill = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    amount: number;
    day_of_month: number;
    budget_category_id: number | null;
    category: { id: number; name: string; color: string } | null;
};

const props = defineProps<{
    bills: Bill[];
    budgetCategories: BudgetCategory[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bills', href: editBills() }],
    },
});

// ── Formatters ────────────────────────────────────────────────────────────────

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? '-' + formatted : formatted;
}

function ordinal(n: number): string {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return s[(v - 20) % 10] || s[v] || s[0];
}

// ── Recurring bill CRUD ───────────────────────────────────────────────────────

const billModalOpen = ref(false);
const editingBill = ref<Bill | null>(null);
const billForm = reactive({ name: '', amount: '', day_of_month: '1', budget_category_id: null as number | null });

// Category searchable dropdown for bill modal
const billCatSearch = ref('');
const billCatOpen = ref(false);
const billCatContainerRef = ref<HTMLElement | null>(null);
const billCatInputRef = ref<HTMLInputElement | null>(null);

const billSelectedCategory = computed(() =>
    billForm.budget_category_id ? (props.budgetCategories.find((c) => c.id === billForm.budget_category_id) ?? null) : null,
);

const billFilteredCategories = computed(() => {
    const q = billCatSearch.value.toLowerCase().trim();
    if (!q) { return props.budgetCategories; }
    return props.budgetCategories.filter((c) => c.name.toLowerCase().includes(q));
});

function openBillCatDropdown() {
    billCatSearch.value = '';
    billCatOpen.value = true;
    nextTick(() => billCatInputRef.value?.focus());
}

function selectBillCategory(cat: BudgetCategory) {
    billForm.budget_category_id = cat.id;
    billCatSearch.value = cat.name;
    billCatOpen.value = false;
}

function clearBillCategory() {
    billForm.budget_category_id = null;
    billCatSearch.value = '';
    billCatOpen.value = false;
}

onClickOutside(billCatContainerRef, () => {
    billCatOpen.value = false;
    billCatSearch.value = billSelectedCategory.value ? billSelectedCategory.value.name : '';
});

function openAddBill() {
    editingBill.value = null;
    Object.assign(billForm, { name: '', amount: '', day_of_month: '1', budget_category_id: null });
    billCatSearch.value = '';
    billCatOpen.value = false;
    billModalOpen.value = true;
}

function openEditBill(bill: Bill) {
    editingBill.value = bill;
    const initialCat = props.budgetCategories.find((c) => c.id === bill.budget_category_id) ?? null;
    Object.assign(billForm, {
        name: bill.name,
        amount: String(bill.amount),
        day_of_month: String(bill.day_of_month),
        budget_category_id: bill.budget_category_id,
    });
    billCatSearch.value = initialCat ? initialCat.name : '';
    billCatOpen.value = false;
    billModalOpen.value = true;
}

function submitBill() {
    const data = {
        name: billForm.name,
        amount: parseFloat(billForm.amount) || 0,
        day_of_month: parseInt(String(billForm.day_of_month)) || 1,
        budget_category_id: billForm.budget_category_id,
    };
    if (editingBill.value) {
        router.patch(update({ recurringBill: editingBill.value.id }).url, data, {
            onSuccess: () => { billModalOpen.value = false; },
            preserveScroll: true,
        });
    } else {
        router.post(store().url, data, {
            onSuccess: () => { billModalOpen.value = false; },
            preserveScroll: true,
        });
    }
}

const deletingBillId = ref<number | null>(null);
let deleteBillTimer: ReturnType<typeof setTimeout> | null = null;

function startDeleteBill(id: number) {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = id;
    deleteBillTimer = setTimeout(() => { deletingBillId.value = null; }, 3000);
}

function confirmDeleteBill(id: number) {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = null;
    router.delete(destroy({ recurringBill: id }).url, { preserveScroll: true });
}

function cancelDeleteBill() {
    if (deleteBillTimer) { clearTimeout(deleteBillTimer); }
    deletingBillId.value = null;
}
</script>

<template>
    <Head title="Bills" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Bills"
            description="Manage the recurring bills tracked in your budget"
        />

        <div>
            <div style="display: flex; align-items: center; justify-content: flex-end; margin-bottom: 16px">
                <button @click="openAddBill"
                    style="display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span>
                    Add bill
                </button>
            </div>

            <div v-if="!bills.length" style="display: flex; flex-direction: column; align-items: center; padding: 36px 0; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 36px; color: #c3bbab">receipt_long</span>
                <div style="font-size: 13.5px; color: #8a8172">No recurring bills yet</div>
                <button @click="openAddBill"
                    style="display: flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fdfbf5">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add your first bill
                </button>
            </div>

            <div v-else style="display: flex; flex-direction: column">
                <div v-for="bill in bills" :key="bill.id" class="group/rbill"
                    style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-top: 1px solid #efe9dc">
                    <div :style="{ background: bill.bg }"
                        style="width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: bill.color }" style="font-size: 21px">{{ bill.icon }}</span>
                    </div>
                    <div style="flex: 1; min-width: 0">
                        <div style="font-weight: 600; font-size: 14px; color: #211d17; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ bill.name }}</div>
                        <div style="display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap">
                            <span style="font-size: 12px; color: #8a8172">{{ bill.day_of_month }}{{ ordinal(bill.day_of_month) }} of each month</span>
                            <span v-if="bill.category" style="font-size: 11px; font-weight: 600; border-radius: 5px; padding: 1px 6px"
                                :style="{ background: bill.category.color + '22', color: bill.category.color }">
                                {{ bill.category.name }}
                            </span>
                        </div>
                    </div>
                    <template v-if="deletingBillId !== bill.id">
                        <div class="flex items-center flex-none">
                            <div style="font-weight: 700; font-size: 14px; font-family: 'Bricolage Grotesque'; color: #211d17">{{ formatMoney(bill.amount) }}</div>
                            <div class="max-w-0 overflow-hidden transition-[max-width] duration-200 ease-out group-hover/rbill:max-w-[68px] flex-shrink-0">
                                <div class="flex items-center gap-1" style="padding-left: 8px">
                                    <button @click.stop="openEditBill(bill)"
                                        style="width: 28px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                        <span class="material-symbols-rounded" style="font-size: 15px">edit</span>
                                    </button>
                                    <button @click.stop="startDeleteBill(bill.id)"
                                        style="width: 28px; height: 28px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                        <span class="material-symbols-rounded" style="font-size: 15px">delete</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div style="display: flex; align-items: center; gap: 6px; flex: none">
                            <button @click="cancelDeleteBill"
                                style="padding: 0 10px; height: 28px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #6b6355">
                                Cancel
                            </button>
                            <button @click="confirmDeleteBill(bill.id)"
                                style="padding: 0 10px; height: 28px; border: none; background: #c0512c; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 12px; font-weight: 600; color: #fff">
                                Delete?
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / edit recurring bill modal -->
    <Dialog v-model:open="billModalOpen">
        <DialogContent style="max-width: 460px">
            <DialogHeader>
                <DialogTitle>{{ editingBill ? 'Edit bill' : 'Add recurring bill' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitBill" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Name + Day of month -->
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: end">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                        <input v-model="billForm.name" required placeholder="e.g. Netflix, Rent"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Due day *</label>
                        <div style="display: flex; align-items: center; gap: 6px">
                            <input v-model="billForm.day_of_month" type="number" min="1" max="31" required
                                style="width: 72px; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 10px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17; text-align: center" />
                            <span style="font-size: 13px; color: #8a8172; white-space: nowrap">of month</span>
                        </div>
                    </div>
                </div>

                <!-- Amount -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Amount *</label>
                    <div style="position: relative">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #6b6355; font-weight: 600">₱</span>
                        <input v-model="billForm.amount" type="text" inputmode="decimal" required placeholder="0.00"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 24px; font-family: 'Bricolage Grotesque'; font-size: 16px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>

                <!-- Budget category -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Budget category</label>
                    <div v-if="!budgetCategories.length" style="font-size: 13px; color: #8a8172; padding: 10px 0">
                        No categories yet — add some on the <a href="/settings/categories" style="color: var(--hab-accent)">Categories page</a>
                    </div>
                    <div v-else ref="billCatContainerRef" style="position: relative">
                        <!-- Trigger -->
                        <div
                            style="display: flex; align-items: center; gap: 8px; width: 100%; height: 40px; border: 1px solid; border-radius: 10px; padding: 0 10px 0 12px; background: #fffdf8; cursor: text; transition: border-color .12s"
                            :style="{ borderColor: billCatOpen ? '#211d17' : '#e0dacd' }"
                            @click="openBillCatDropdown"
                        >
                            <span v-if="billSelectedCategory" class="msf" :style="{ color: billSelectedCategory.color }" style="font-size: 16px; flex-shrink: 0">{{ billSelectedCategory.icon }}</span>
                            <input
                                ref="billCatInputRef"
                                v-model="billCatSearch"
                                type="text"
                                :placeholder="billSelectedCategory ? billSelectedCategory.name : 'Search categories…'"
                                style="flex: 1; min-width: 0; background: transparent; border: none; outline: none; font-family: 'Instrument Sans'; font-size: 14px; color: #211d17"
                                @focus="openBillCatDropdown"
                                @input="billCatOpen = true"
                            />
                            <button v-if="billSelectedCategory" type="button" @click.stop="clearBillCategory"
                                style="display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 20px; height: 20px; border: none; background: none; cursor: pointer; padding: 0; color: #8a8172">
                                <span class="material-symbols-rounded" style="font-size: 16px">close</span>
                            </button>
                            <span v-else class="material-symbols-rounded" style="font-size: 16px; color: #8a8172; flex-shrink: 0; pointer-events: none">expand_more</span>
                        </div>
                        <!-- Dropdown list -->
                        <div v-if="billCatOpen && billFilteredCategories.length > 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,.08)">
                            <div style="max-height: 200px; overflow-y: auto">
                                <button v-for="cat in billFilteredCategories" :key="cat.id" type="button"
                                    @mousedown.prevent="selectBillCategory(cat)"
                                    style="display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 14px; border: none; cursor: pointer; font-family: 'Instrument Sans'; font-size: 14px; text-align: left; transition: background .08s"
                                    :style="{ background: billForm.budget_category_id === cat.id ? '#f5f0e8' : 'transparent', color: '#4a4436' }"
                                    @mouseenter="($event.currentTarget as HTMLElement).style.background = '#f5f0e8'"
                                    @mouseleave="($event.currentTarget as HTMLElement).style.background = billForm.budget_category_id === cat.id ? '#f5f0e8' : 'transparent'"
                                >
                                    <span class="msf" :style="{ color: cat.color }" style="font-size: 16px; flex-shrink: 0">{{ cat.icon }}</span>
                                    <span style="flex: 1">{{ cat.name }}</span>
                                    <span v-if="billForm.budget_category_id === cat.id" class="material-symbols-rounded" style="font-size: 16px; color: #211d17">check</span>
                                </button>
                            </div>
                        </div>
                        <!-- No results -->
                        <div v-if="billCatOpen && billCatSearch && billFilteredCategories.length === 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; padding: 12px 14px; font-family: 'Instrument Sans'; font-size: 13px; color: #8a8172">
                            No matching categories
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="billModalOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingBill ? 'Save changes' : 'Add bill' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
