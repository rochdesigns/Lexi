<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy } from '@/actions/App/Http/Controllers/AccountController';

type AccountType = 'cash' | 'credit' | 'investment';

type Account = {
    id: number;
    name: string;
    institution: string | null;
    last4: string | null;
    balance: string;
    type: AccountType;
};

type AccountGroup = {
    type: AccountType;
    label: string;
    subtotal: number;
    accounts: Account[];
};

const props = defineProps<{
    netWorth: number;
    groups: AccountGroup[];
    cycleLabel: string;
}>();

const typeIcons: Record<AccountType, string> = {
    cash: 'savings',
    credit: 'credit_card',
    investment: 'candlestick_chart',
};

const typeColors: Record<AccountType, { color: string; bg: string }> = {
    cash: { color: '#2f7d5b', bg: '#dff3e6' },
    credit: { color: '#c0512c', bg: '#fbe2d6' },
    investment: { color: '#3f7fb0', bg: '#dcebf5' },
};

const typeOptions: { value: AccountType; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'credit', label: 'Credit' },
    { value: 'investment', label: 'Investment' },
];

// Modal state
const modalOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const form = reactive({ name: '', institution: '', last4: '', balance: '', type: 'cash' as AccountType });

// Two-step delete
const deleteConfirmId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

const netWorthFormatted = computed(() => formatMoney(props.netWorth));

function formatMoney(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    return n < 0 ? '-' + formatted : formatted;
}

function openAdd() {
    editingAccount.value = null;
    Object.assign(form, { name: '', institution: '', last4: '', balance: '', type: 'cash' });
    modalOpen.value = true;
}

function openEdit(account: Account) {
    editingAccount.value = account;
    Object.assign(form, {
        name: account.name,
        institution: account.institution ?? '',
        last4: account.last4 ?? '',
        balance: account.balance,
        type: account.type,
    });
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    const data = {
        name: form.name,
        institution: form.institution || null,
        last4: form.last4 || null,
        balance: parseFloat(form.balance),
        type: form.type,
    };
    if (editingAccount.value) {
        router.patch(update({ account: editingAccount.value.id }).url, data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    } else {
        router.post(store().url, data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    }
}

function startDelete(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = id;
    deleteTimer = setTimeout(() => { deleteConfirmId.value = null; }, 3000);
}

function confirmDelete(id: number) {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = null;
    router.delete(destroy({ account: id }).url, { preserveScroll: true });
}

function cancelDelete() {
    if (deleteTimer) { clearTimeout(deleteTimer); }
    deleteConfirmId.value = null;
}
</script>

<template>
    <Head title="Accounts" />

    <div style="padding: 24px 30px 46px; display: flex; flex-direction: column; gap: 20px; max-width: 1180px; width: 100%; margin: 0 auto">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Accounts</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Everything you own and owe, in one place</p>
            </div>
            <button @click="openAdd" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Net worth hero + cash flow row -->
        <div style="display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start">

            <!-- Net worth hero -->
            <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
                <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
                <div style="position: relative; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap">
                    <div>
                        <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Total net worth</div>
                        <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em; margin-top: 6px">
                            {{ netWorthFormatted }}
                        </div>
                        <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 6px 12px; border-radius: 20px; font-size: 13px">
                            <span class="msf" style="font-size: 16px; color: #fff">trending_flat</span>
                            <span>₱0 · 0% this month</span>
                        </div>
                    </div>
                    <!-- Flat sparkline (phase 1 — no transaction history) -->
                    <div style="display: flex; align-items: flex-end; gap: 6px; height: 74px; padding-top: 6px">
                        <div v-for="(h, i) in [30, 35, 28, 32, 30, 30]" :key="i"
                            style="width: 16px; border-radius: 5px; background: rgba(255,255,255,.25)"
                            :style="{ height: h + '%' }">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cash flow card (zero state) -->
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; margin-bottom: 16px">
                    Cash flow · {{ cycleLabel }}
                </div>
                <div style="display: flex; flex-direction: column; gap: 13px">
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #dff3e6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #2f7d5b">south_west</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money in</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #2f7d5b">₱0</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #f8ecd6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #a06a2b">north_east</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money out</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #211d17">₱0</span>
                    </div>
                    <div style="height: 9px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 2px">
                        <div style="height: 100%; width: 0; background: #d98a2b; border-radius: 20px"></div>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 11px; border-top: 1px solid #efe9dc">
                        <span style="font-size: 13.5px; font-weight: 600">Net saved</span>
                        <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; color: var(--hab-accent)">₱0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 56px 32px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">account_balance</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 18px; margin-top: 12px; color: #211d17">No accounts yet</div>
            <div style="color: #8a8172; font-size: 14px; margin-top: 4px">Add your first account to start tracking your net worth</div>
            <button @click="openAdd" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Grouped accounts card -->
        <div v-if="groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 10px 26px 16px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <div v-for="group in groups" :key="group.type" style="padding: 16px 0 4px">
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 4px">
                    <div style="font-size: 11.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #a49b8a">
                        {{ group.label }}
                    </div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 14px;" :style="{ color: group.subtotal < 0 ? '#c0512c' : '#4a4436' }">
                        {{ formatMoney(group.subtotal) }}
                    </div>
                </div>

                <div v-for="account in group.accounts" :key="account.id" class="group/account"
                    style="display: flex; align-items: center; gap: 13px; padding: 13px 0; border-top: 1px solid #efe9dc">
                    <span :style="{ background: typeColors[account.type].bg }"
                        style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: typeColors[account.type].color }" style="font-size: 21px">
                            {{ typeIcons[account.type] }}
                        </span>
                    </span>
                    <div style="min-width: 0; flex: 1">
                        <div style="font-weight: 600; font-size: 14.5px">{{ account.name }}</div>
                        <div style="font-size: 12.5px; color: #8a8172; margin-top: 1px">
                            {{ account.institution || '—' }}{{ account.last4 ? ' •• ' + account.last4 : '' }}
                        </div>
                    </div>

                    <!-- Normal state: balance + hover actions -->
                    <template v-if="deleteConfirmId !== account.id">
                        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px"
                            :style="{ color: parseFloat(account.balance) < 0 ? '#c0512c' : '#211d17' }">
                            {{ formatMoney(account.balance) }}
                        </div>
                        <div class="hidden group-hover/account:flex items-center gap-1">
                            <button @click.stop="openEdit(account)"
                                style="width: 32px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                            </button>
                            <button @click.stop="startDelete(account.id)"
                                style="width: 32px; height: 32px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                            </button>
                        </div>
                        <span class="material-symbols-rounded group-hover/account:hidden" style="font-size: 20px; color: #c3bbab">chevron_right</span>
                    </template>

                    <!-- Delete confirm state -->
                    <template v-else>
                        <div style="display: flex; align-items: center; gap: 6px; margin-left: auto">
                            <button @click="cancelDelete"
                                style="padding: 0 12px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                                Cancel
                            </button>
                            <button @click="confirmDelete(account.id)"
                                style="padding: 0 12px; height: 32px; border: none; background: #c0512c; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fff">
                                Confirm?
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    <!-- Add / Edit modal -->
    <Dialog v-model:open="modalOpen">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>{{ editingAccount ? 'Edit account' : 'Add account' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                    <input v-model="form.name" required placeholder="e.g. BDO Checking"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Institution</label>
                        <input v-model="form.institution" placeholder="e.g. BDO"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Last 4 digits</label>
                        <input v-model="form.last4" maxlength="4" placeholder="1234"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Balance *</label>
                    <input v-model="form.balance" type="number" step="0.01" required placeholder="e.g. 15000 (or -612 for debt)"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    <div style="font-size: 12px; color: #8a8172; margin-top: 4px">Enter a negative number for debt you owe</div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Type *</label>
                    <div style="display: flex; gap: 8px">
                        <button v-for="t in typeOptions" :key="t.value" type="button" @click="form.type = t.value"
                            style="flex: 1; height: 38px; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; border: 1px solid"
                            :style="{
                                borderColor: form.type === t.value ? 'var(--hab-accent)' : '#e0dacd',
                                background: form.type === t.value ? 'color-mix(in srgb, var(--hab-accent) 12%, transparent)' : '#fffdf8',
                                color: form.type === t.value ? 'var(--hab-accent-deep)' : '#6b6355',
                                fontWeight: form.type === t.value ? 600 : 500,
                            }">
                            {{ t.label }}
                        </button>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="closeModal"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingAccount ? 'Save changes' : 'Add account' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
    </div>
</template>
