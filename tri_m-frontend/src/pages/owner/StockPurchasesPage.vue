<script setup>
import { onMounted, ref } from 'vue'
import PosPagination from '@/components/shared/PosPagination.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import PosToast from '@/components/shared/PosToast.vue'
import StockPurchaseDialog from '@/components/stock/StockPurchaseDialog.vue'
import { request } from '@/services/posApi'
import { formatStockMoney } from '@/utils/stockMoney'
import { userMessage } from '@/utils/userFeedback'

const history = ref(null)
const loading = ref(false)
const error = ref('')
const dialog = ref(null)
const notice = ref('')

function saved() {
  notice.value = 'Stock purchase saved.'
  void load()
}

async function load(page = 1) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    history.value = await request('get', '/api/stock-purchases', { params: { page } })
  } catch (problem) {
    error.value = userMessage(problem, 'We could not load previous purchases. Try again.')
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div class="owner-page">
    <header class="owner-heading">
      <h1 class="screen-title">Previous purchases</h1>
      <div class="flex flex-wrap gap-2">
        <RouterLink class="dialog-action" :to="{ name: 'owner-import', query: { view: 'history' } }">
          Import history
        </RouterLink>
        <button class="dialog-action dialog-action--primary" type="button" :disabled="loading" @click="dialog.open()">
          Add stock purchase
        </button>
      </div>
    </header>
    <p v-if="loading && !history" class="screen-note" role="status">Loading purchases…</p>
    <div v-if="error" class="grid gap-2">
      <p class="screen-error" role="alert">{{ error }}</p>
      <button class="dialog-action justify-self-start" type="button" @click="load()">Reload purchases</button>
    </div>
    <p v-if="history && !history.data.length" class="screen-note" role="status">
      No previous purchases.
    </p>
    <PosTableRegion v-else-if="history" aria-label="Purchase records">
      <table class="data-table purchase-table" aria-label="Previous purchases">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Date</th>
            <th scope="col">Category</th>
            <th scope="col">Supplier</th>
            <th scope="col" class="number-cell">Quantity</th>
            <th scope="col" class="number-cell">Cost per item</th>
            <th scope="col" class="number-cell">Total cost</th>
            <th scope="col" class="number-cell">Markup (%)</th>
            <th scope="col" class="number-cell">Sell price</th>
            <th scope="col" class="number-cell">Expected sales</th>
            <th scope="col" class="number-cell">Expected gross profit</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="purchase in history.data" :key="purchase.id">
            <th scope="row" class="font-semibold">{{ purchase.product_name }}</th>
            <td>{{ purchase.purchase_date }}</td>
            <td>{{ purchase.category_name }}</td>
            <td>{{ purchase.supplier.name }}</td>
            <td class="number-cell">{{ purchase.quantity }}</td>
            <td class="number-cell">{{ formatStockMoney(purchase.unit_cost) }}</td>
            <td class="number-cell">{{ formatStockMoney(purchase.total_purchase) }}</td>
            <td class="number-cell">{{ Number(purchase.markup_percentage) }}%</td>
            <td class="number-cell">{{ formatStockMoney(purchase.selling_price) }}</td>
            <td class="number-cell">{{ formatStockMoney(purchase.expected_sales) }}</td>
            <td class="number-cell">{{ formatStockMoney(purchase.expected_gross_profit) }}</td>
          </tr>
        </tbody>
      </table>
    </PosTableRegion>
    <PosPagination
      v-if="history?.meta.last_page > 1"
      label="Purchase pages"
      :page="history.meta.current_page"
      :last-page="history.meta.last_page"
      :loading="loading"
      @update:page="load"
    />
    <StockPurchaseDialog ref="dialog" @saved="saved" />
    <PosToast :message="notice" @dismiss="notice = ''" />
  </div>
</template>

<style scoped>
.purchase-table {
  min-inline-size: 63rem;
}

</style>
