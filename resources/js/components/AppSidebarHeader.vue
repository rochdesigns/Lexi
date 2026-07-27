<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import { onClickOutside } from '@vueuse/core';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { BreadcrumbItem } from '@/types';
import { store } from '@/actions/App/Http/Controllers/TransactionController';
import { evaluateArithmetic, hasArithmetic } from '@/utils/arithmetic';

// Prop accepted for layout compatibility but not used — header derives state from URL.
withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const { currentUrl } = useCurrentUrl();
const page = usePage();

const budgetCategories = computed(() => (page.props.budgetCategories as any[]) ?? []);
const spendingAccounts = computed(() => (page.props.spendingAccounts as any[]) ?? []);

type Section = { icon: string; label: string; paths: string[] };

const sections: Section[] = [
    { icon: 'wb_sunny', label: 'Day', paths: ['/today', '/calendar', '/tasks'] },
    {
        icon: 'interests',
        label: 'Life',
        paths: ['/health', '/habits', '/notes', '/journal', '/meals', '/groceries', '/pets'],
    },
    {
        icon: 'account_balance_wallet',
        label: 'Money',
        paths: ['/goals', '/accounts', '/budgets', '/set-asides', '/debt', '/photos'],
    },
];

const pageLabels: Record<string, string> = {
    '/today': 'Today',
    '/calendar': 'Calendar',
    '/tasks': 'Tasks',
    '/health': 'Health',
    '/habits': 'Habits',
    '/notes': 'Notes',
    '/journal': 'Journal',
    '/meals': 'Meals',
    '/groceries': 'Groceries',
    '/pets': 'Pets',
    '/goals': 'Goals',
    '/accounts': 'Accounts',
    '/budgets': 'Budgets',
    '/set-asides': 'Set-asides',
    '/debt': 'Debt',
    '/photos': 'Photos',
};

const activeSection = computed(
    (): Section | null =>
        sections.find((s) => s.paths.some((p) => currentUrl.value.startsWith(p))) ?? null,
);

const pageLabel = computed((): string => {
    const matched = Object.keys(pageLabels).find((p) => currentUrl.value.startsWith(p));
    if (matched) {
        return pageLabels[matched];
    }
    const segment = currentUrl.value.split('/').filter(Boolean).pop() ?? '';
    return segment.charAt(0).toUpperCase() + segment.slice(1);
});

const notificationCount = 1;

// Log spending modal
const modalOpen = ref(false);
const submitting = ref(false);
const errors = reactive<Record<string, string>>({});

type TransactionType = 'expense' | 'income' | 'transfer';
const today = new Date().toISOString().slice(0, 10);
const form = reactive({
    type: 'expense' as TransactionType,
    amount: '',
    budget_category_id: '' as string | number,
    merchant: '',
    account_id: '' as string | number,
    to_account_id: '' as string | number,
    transacted_at: today,
});

function openModal() {
    form.type = 'expense';
    form.amount = '';
    form.budget_category_id = '';
    form.merchant = '';
    form.account_id = spendingAccounts.value[0]?.id ?? '';
    form.to_account_id = '';
    form.transacted_at = today;
    Object.keys(errors).forEach((k) => delete errors[k]);
    categorySearch.value = '';
    categoryDropdownOpen.value = false;
    modalOpen.value = true;
}

function setType(t: TransactionType) {
    form.type = t;
    form.budget_category_id = '';
    categorySearch.value = '';
    categoryDropdownOpen.value = false;
    Object.keys(errors).forEach((k) => delete errors[k]);
}

// Category searchable dropdown
const categorySearch = ref('');
const categoryDropdownOpen = ref(false);
const categoryContainerRef = ref<HTMLElement | null>(null);
const categoryInputRef = ref<HTMLInputElement | null>(null);

const selectedCategory = computed(() =>
    form.budget_category_id ? (budgetCategories.value.find((c: any) => c.id === form.budget_category_id) ?? null) : null,
);

const filteredCategories = computed(() => {
    const q = categorySearch.value.toLowerCase().trim();
    if (!q) { return budgetCategories.value; }
    return budgetCategories.value.filter((c: any) => c.name.toLowerCase().includes(q));
});

function openCategoryDropdown() {
    categorySearch.value = '';
    categoryDropdownOpen.value = true;
    nextTick(() => categoryInputRef.value?.focus());
}

function selectCategory(cat: any) {
    form.budget_category_id = cat.id;
    categorySearch.value = cat.name;
    categoryDropdownOpen.value = false;
}

function clearCategory() {
    form.budget_category_id = '';
    categorySearch.value = '';
    categoryDropdownOpen.value = false;
}

onClickOutside(categoryContainerRef, () => {
    categoryDropdownOpen.value = false;
    categorySearch.value = selectedCategory.value ? selectedCategory.value.name : '';
});

const amountEvaluated = computed(() => evaluateArithmetic(form.amount));
const amountHasExpr = computed(() => hasArithmetic(form.amount) && amountEvaluated.value !== null);

const modalTitle = computed(() => {
    if (form.type === 'income') { return 'Log income'; }
    if (form.type === 'transfer') { return 'Log transfer'; }
    return 'Log spending';
});

function submitSpending() {
    if (submitting.value) { return; }
    Object.keys(errors).forEach((k) => delete errors[k]);

    const payload: Record<string, unknown> = {
        type: form.type,
        amount: amountEvaluated.value ?? parseFloat(form.amount),
        merchant: form.merchant || null,
        account_id: form.account_id,
        transacted_at: form.transacted_at,
    };

    if (form.type !== 'transfer') {
        payload.budget_category_id = form.budget_category_id || null;
    }

    if (form.type === 'transfer') {
        payload.to_account_id = form.to_account_id;
    }

    submitting.value = true;
    router.post(store().url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            submitting.value = false;
        },
        onError: (errs) => {
            Object.assign(errors, errs);
            submitting.value = false;
        },
    });
}
</script>

<template>
    <header
        class="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b px-4 md:px-6"
        style="background: rgba(239, 236, 228, 0.86); backdrop-filter: blur(12px); border-color: #e0dacd"
    >
        <!-- Mobile sidebar trigger -->
        <SidebarTrigger class="md:hidden -ml-1 shrink-0" />

        <!-- Breadcrumb -->
        <div class="flex items-center gap-1.5 text-[13px]">
            <template v-if="activeSection">
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)">
                    {{ activeSection.icon }}
                </span>
                <span style="color: var(--hab-faint)">{{ activeSection.label }}</span>
                <span style="color: var(--hab-faint)">/</span>
            </template>
            <span class="font-semibold" style="color: var(--hab-ink)">{{ pageLabel }}</span>
        </div>

        <!-- Right actions -->
        <div class="ml-auto flex items-center gap-2">
            <!-- Search -->
            <div
                class="hidden sm:flex w-[200px] lg:w-[250px] items-center gap-2 rounded-[11px] border px-3 py-1.5"
                style="border-color: #e0dacd; background: var(--hab-card-bg)"
            >
                <span class="material-symbols-rounded shrink-0" style="font-size: 16px; color: var(--hab-faint)">search</span>
                <input
                    type="text"
                    placeholder="Search anything in your life…"
                    class="min-w-0 flex-1 bg-transparent text-[13px] outline-none"
                    style="color: var(--hab-ink)"
                    readonly
                />
                <kbd
                    class="rounded-[6px] px-1.5 py-0.5 text-[10px] font-medium"
                    style="background: var(--hab-chip-fill); color: var(--hab-faint)"
                    >⌘K</kbd
                >
            </div>

            <!-- Notification bell -->
            <button class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-black/5">
                <span class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-ink-secondary)">
                    notifications
                </span>
                <span
                    v-if="notificationCount > 0"
                    class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full"
                    style="background: #c0512c"
                ></span>
            </button>

            <!-- Log spending button -->
            <button
                @click="openModal"
                class="flex items-center gap-1.5 rounded-[11px] px-3 sm:px-4 py-2 text-[13px] font-semibold"
                style="background: var(--hab-btn-dark-bg); color: var(--hab-btn-dark-ink)"
            >
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-btn-dark-ink)">add</span>
                <span class="hidden sm:inline">Log spending</span>
            </button>
        </div>
    </header>

    <!-- Log spending modal -->
    <Dialog v-model:open="modalOpen">
        <DialogContent style="max-width: 420px">
            <DialogHeader>
                <DialogTitle>{{ modalTitle }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submitSpending" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">

                <!-- Type toggle -->
                <div style="display: flex; background: #f5f0e8; border-radius: 10px; padding: 3px; gap: 2px">
                    <button
                        v-for="t in (['expense', 'income', 'transfer'] as TransactionType[])"
                        :key="t"
                        type="button"
                        @click="setType(t)"
                        style="flex: 1; height: 34px; border: none; border-radius: 8px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; transition: all .12s; text-transform: capitalize"
                        :style="{
                            background: form.type === t ? '#fffdf8' : 'transparent',
                            color: form.type === t ? '#211d17' : '#8a8172',
                            boxShadow: form.type === t ? '0 1px 3px rgba(0,0,0,.08)' : 'none',
                        }"
                    >{{ t }}</button>
                </div>

                <!-- Amount -->
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Amount *</label>
                    <div style="position: relative">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 15px; color: #6b6355; font-weight: 600">₱</span>
                        <input
                            v-model="form.amount"
                            type="text"
                            inputmode="decimal"
                            required
                            placeholder="0.00"
                            style="width: 100%; height: 44px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px 0 26px; font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700; background: #fffdf8; outline: none; color: #211d17"
                            :style="{ borderColor: errors.amount ? '#c0512c' : '#e0dacd' }"
                        />
                    </div>
                    <div v-if="amountHasExpr" style="font-size: 12px; color: #8a8172; margin-top: 3px">= ₱{{ amountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
                    <div v-if="errors.amount" style="font-size: 12px; color: #c0512c; margin-top: 4px">{{ errors.amount }}</div>
                </div>

                <!-- Category (expense only, optional for income) -->
                <div v-if="form.type !== 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">
                        Category{{ form.type === 'expense' ? ' *' : '' }}
                    </label>
                    <div v-if="!budgetCategories.length" style="font-size: 13px; color: #8a8172; padding: 10px 0">
                        No categories yet — add some on the <a href="/budgets" style="color: var(--hab-accent)">Budgets page</a>
                    </div>
                    <div v-else ref="categoryContainerRef" style="position: relative">
                        <!-- Trigger input -->
                        <div
                            style="display: flex; align-items: center; gap: 8px; width: 100%; height: 40px; border: 1px solid; border-radius: 10px; padding: 0 10px 0 12px; background: #fffdf8; cursor: text; transition: border-color .12s"
                            :style="{ borderColor: errors.budget_category_id ? '#c0512c' : (categoryDropdownOpen ? '#211d17' : '#e0dacd') }"
                            @click="openCategoryDropdown"
                        >
                            <span v-if="selectedCategory" class="msf" :style="{ color: selectedCategory.color, flexShrink: 0 }" style="font-size: 16px">{{ selectedCategory.icon }}</span>
                            <input
                                ref="categoryInputRef"
                                v-model="categorySearch"
                                type="text"
                                :placeholder="selectedCategory ? selectedCategory.name : 'Search categories…'"
                                style="flex: 1; min-width: 0; background: transparent; border: none; outline: none; font-family: 'Instrument Sans'; font-size: 14px; color: #211d17"
                                @focus="openCategoryDropdown"
                                @input="categoryDropdownOpen = true"
                            />
                            <button
                                v-if="selectedCategory"
                                type="button"
                                @click.stop="clearCategory"
                                style="display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 20px; height: 20px; border: none; background: none; cursor: pointer; padding: 0; color: #8a8172"
                            >
                                <span class="material-symbols-rounded" style="font-size: 16px">close</span>
                            </button>
                            <span v-else class="material-symbols-rounded" style="font-size: 16px; color: #8a8172; flex-shrink: 0; pointer-events: none">expand_more</span>
                        </div>
                        <!-- Dropdown list -->
                        <div
                            v-if="categoryDropdownOpen && filteredCategories.length > 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,.08)"
                        >
                            <div style="max-height: 200px; overflow-y: auto">
                                <button
                                    v-for="cat in filteredCategories"
                                    :key="cat.id"
                                    type="button"
                                    @mousedown.prevent="selectCategory(cat)"
                                    style="display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 14px; border: none; cursor: pointer; font-family: 'Instrument Sans'; font-size: 14px; text-align: left; transition: background .08s"
                                    :style="{
                                        background: form.budget_category_id === cat.id ? '#f5f0e8' : 'transparent',
                                        color: '#4a4436',
                                    }"
                                    @mouseenter="($event.currentTarget as HTMLElement).style.background = '#f5f0e8'"
                                    @mouseleave="($event.currentTarget as HTMLElement).style.background = form.budget_category_id === cat.id ? '#f5f0e8' : 'transparent'"
                                >
                                    <span class="msf" :style="{ color: cat.color }" style="font-size: 16px; flex-shrink: 0">{{ cat.icon }}</span>
                                    <span style="flex: 1">{{ cat.name }}</span>
                                    <span v-if="form.budget_category_id === cat.id" class="material-symbols-rounded" style="font-size: 16px; color: #211d17">check</span>
                                </button>
                            </div>
                        </div>
                        <!-- No results -->
                        <div
                            v-if="categoryDropdownOpen && categorySearch && filteredCategories.length === 0"
                            style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fffdf8; border: 1px solid #e0dacd; border-radius: 10px; z-index: 100; padding: 12px 14px; font-family: 'Instrument Sans'; font-size: 13px; color: #8a8172"
                        >
                            No matching categories
                        </div>
                    </div>
                    <div v-if="errors.budget_category_id" style="font-size: 12px; color: #c0512c; margin-top: 4px">{{ errors.budget_category_id }}</div>
                </div>

                <!-- Merchant (not for transfer) -->
                <div v-if="form.type !== 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">{{ form.type === 'income' ? 'Source' : 'Merchant' }}</label>
                    <input
                        v-model="form.merchant"
                        type="text"
                        :placeholder="form.type === 'income' ? 'e.g. Salary, Freelance' : 'e.g. Jollibee, Shopee'"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17"
                    />
                </div>

                <!-- Transfer: From + To account -->
                <template v-if="form.type === 'transfer'">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div>
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">From *</label>
                            <select
                                v-model="form.account_id"
                                required
                                style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none"
                                :style="{ borderColor: errors.account_id ? '#c0512c' : '#e0dacd' }"
                            >
                                <option value="" disabled>Select</option>
                                <option v-for="acc in spendingAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                            </select>
                            <div v-if="errors.account_id" style="font-size: 12px; color: #c0512c; margin-top: 4px">{{ errors.account_id }}</div>
                        </div>
                        <div>
                            <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">To *</label>
                            <select
                                v-model="form.to_account_id"
                                required
                                style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none"
                                :style="{ borderColor: errors.to_account_id ? '#c0512c' : '#e0dacd' }"
                            >
                                <option value="" disabled>Select</option>
                                <option v-for="acc in spendingAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                            </select>
                            <div v-if="errors.to_account_id" style="font-size: 12px; color: #c0512c; margin-top: 4px">{{ errors.to_account_id }}</div>
                        </div>
                    </div>
                </template>

                <!-- Account + Date row (non-transfer) -->
                <div v-else style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Account *</label>
                        <select
                            v-model="form.account_id"
                            required
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none"
                            :style="{ borderColor: errors.account_id ? '#c0512c' : '#e0dacd' }"
                        >
                            <option value="" disabled>Select</option>
                            <option v-for="acc in spendingAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                        </select>
                        <div v-if="errors.account_id" style="font-size: 12px; color: #c0512c; margin-top: 4px">{{ errors.account_id }}</div>
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Date *</label>
                        <input
                            v-model="form.transacted_at"
                            type="date"
                            required
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17"
                        />
                    </div>
                </div>

                <!-- Date (transfer) -->
                <div v-if="form.type === 'transfer'">
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Date *</label>
                    <input
                        v-model="form.transacted_at"
                        type="date"
                        required
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17"
                    />
                </div>

                <!-- Actions -->
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button
                        type="button"
                        @click="modalOpen = false"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="submitting"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5"
                        :style="{ opacity: submitting ? 0.6 : 1 }"
                    >
                        {{ submitting ? 'Logging…' : modalTitle }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
