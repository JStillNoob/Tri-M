<script setup>
import { computed, ref } from 'vue'
import CashierPageHeader from '@/components/pos/CashierPageHeader.vue'
import PosPagination from '@/components/shared/PosPagination.vue'
import PosSearchInput from '@/components/shared/PosSearchInput.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { saleFromApi } from '@/services/posApi'
import { useCachedRead } from '@/services/posReadCache'
import { useSessionStore } from '@/stores/sessionStore'
import { formatPrice } from '@/utils/price'
import { paymentLabel, transactionDate } from '@/utils/sales'
import { userMessage } from '@/utils/userFeedback'

const session = useSessionStore()
const owner = computed(() => session.account?.role === 'owner')
const query = ref('')
const period = ref('today')
const method = ref('')
const page = ref(1)
const applied = ref({ period: 'today' })
const result = useCachedRead(() => ({
  kind: 'orders',
  path: '/api/sales',
  params: {
    ...applied.value,
    period: owner.value ? applied.value.period : undefined,
    page: page.value,
  },
}))
const orders = computed(() => result.data.value?.data.map(saleFromApi) ?? [])
const lastPage = computed(() => result.data.value?.meta.last_page ?? 1)
const status = computed(() =>
  result.data.value ? 'ready' : result.error.value ? 'error' : 'loading',
)
const error = computed(() => userMessage(result.error.value, 'We could not load orders. Try again.'))
const load = result.refresh

function search() {
  page.value = 1
  applied.value = {
    receipt_no: query.value.trim(),
    period: period.value,
    payment_method: method.value,
  }
}
</script>

<template>
  <component :is="owner ? 'div' : POSLayout">
    <template v-if="!owner" #header><CashierPageHeader title="Orders" /></template>

    <div class="owner-page">
      <h1 v-if="owner" class="screen-title">Orders</h1>
      <h2 v-else class="section-title">Today’s sales</h2>
      <form class="order-filters" @submit.prevent="search">
        <PosSearchInput id="order-search" v-model="query" label="Receipt number" :maxlength="50" />
        <label v-if="owner" class="dialog-field">
          Date range
          <select v-model="period">
            <option value="today">Today</option>
            <option value="7d">Last 7 days</option>
            <option value="30d">Last 30 days</option>
          </select>
        </label>
        <label class="dialog-field">
          Payment method
          <select v-model="method">
            <option value="">All methods</option>
            <option value="cash">Cash</option>
            <option value="ewallet">E-wallet</option>
          </select>
        </label>
        <button type="submit" class="dialog-action justify-self-start">Search</button>
      </form>
      <p v-if="status === 'loading'" class="screen-note" role="status">Loading orders…</p>
      <div v-else-if="status === 'error'" class="grid gap-3">
        <p class="screen-error" role="alert">{{ error }}</p>
        <button class="dialog-action justify-self-start" type="button" @click="load">
          Try again
        </button>
      </div>
      <p v-else-if="!orders.length" class="screen-note" role="status">
        No sales match these filters.
      </p>
      <template v-else>
        <PosTableRegion aria-label="Sales history">
          <table class="data-table">
            <caption class="sr-only">Sales history</caption>
            <thead>
              <tr>
                <th scope="col">Receipt</th>
                <th scope="col">Payment</th>
                <th scope="col" class="number-cell">Total</th>
                <th scope="col">Date</th>
                <th scope="col">Cashier</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sale in orders" :key="sale.id">
                <th scope="row">
                  <RouterLink class="record-link" :to="{ name: 'receipt', params: { id: sale.id } }">
                    {{ sale.receiptNo }}
                  </RouterLink>
                </th>
                <td>{{ paymentLabel(sale.payment) }}</td>
                <td class="number-cell money-value">{{ formatPrice(sale.total) }}</td>
                <td><time :datetime="sale.createdAt">{{ transactionDate(sale.createdAt) }}</time></td>
                <td>{{ sale.cashier }}</td>
              </tr>
            </tbody>
          </table>
        </PosTableRegion>
        <PosPagination v-if="lastPage > 1" v-model:page="page" :last-page="lastPage" label="Order pages" />
      </template>
    </div>
  </component>
</template>

<style scoped>
.order-filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 10rem), 1fr));
  align-items: end;
  gap: 0.75rem;
}

.order-filters > * {
  min-width: 0;
}

</style>
