<template>
  <div>
    <h1>Ledger</h1>
    <div v-if="accounts.length === 0">
      No accounts
    </div>
    <div v-for="account in accounts" :key="account.id">
      <h2>{{ account.name }}</h2>
      <p>Balance: {{ account.balance }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  accounts: Array<{
    id: number;
    name: string;
    institution: string | null;
    type: 'checking' | 'savings' | 'credit' | 'cash' | 'investment';
    is_liability: boolean;
    balance: number;
    recent_transactions: Array<{
      id: number;
      type: 'income' | 'expense' | 'transfer';
      amount: number;
      description: string | null;
      occurred_at: string;
      direction: 'in' | 'out';
      counterpart: string | null;
    }>;
  }>;
}>();
</script>
