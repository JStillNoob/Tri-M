<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import CashierPageHeader from '@/components/pos/CashierPageHeader.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { saleFromApi } from '@/services/posApi'
import { useCachedRead } from '@/services/posReadCache'
import { useSessionStore } from '@/stores/sessionStore'
import { formatPrice } from '@/utils/price'
import { paymentLabel, transactionDate } from '@/utils/sales'
import { userMessage } from '@/utils/userFeedback'

const route = useRoute()
const session = useSessionStore()
const ordersRoute = computed(() => (session.account?.role === 'owner' ? 'owner-orders' : 'orders'))
const saleResult = useCachedRead(() =>
  route.params.id
    ? {
        kind: 'receipt',
        path: '/api/sales/' + encodeURIComponent(route.params.id),
      }
    : null,
)
const receipt = computed(() =>
  saleResult.data.value ? saleFromApi(saleResult.data.value.data) : null,
)
const error = computed(() => userMessage(saleResult.error.value, 'We could not load this receipt. Try again or reopen it from Orders.'))
const status = computed(() =>
  saleResult.loading.value
    ? 'loading'
    : !receipt.value && saleResult.error.value && saleResult.error.value.status !== 404
      ? 'error'
      : 'ready',
)
const load = saleResult.refresh
const sharing = ref(false)
const shareMessage = ref('')
const manualCopy = ref('')
const copyField = ref(null)
const saleCompleted = ref(window.history.state?.saleCompleted === true)

watch(
  () => route.params.id,
  () => {
    saleCompleted.value = window.history.state?.saleCompleted === true
    shareMessage.value = ''
    manualCopy.value = ''
  },
)

async function shareReceipt() {
  if (sharing.value) return

  sharing.value = true
  shareMessage.value = ''
  manualCopy.value = ''
  const sale = receipt.value
  const paymentDetails =
    sale.payment.method === 'cash'
      ? [
          'Received ' + formatPrice(sale.payment.received),
          'Change ' + formatPrice(sale.payment.change),
        ]
      : ['Reference ' + sale.payment.reference]
  const text = [
    'TRI-M Mini-Mart receipt',
    sale.receiptNo,
    transactionDate(sale.createdAt),
    sale.cashier,
    ...sale.lines.map(
      (line) =>
        line.quantity +
        ' × ' +
        line.name +
        ' @ ' +
        formatPrice(line.unitPrice) +
        ' = ' +
        formatPrice(line.total),
    ),
    'Total ' + formatPrice(sale.total),
    paymentLabel(sale.payment),
    ...paymentDetails,
  ].join('\n')

  try {
    if (navigator.share) {
      await navigator.share({ title: 'TRI-M Mini-Mart receipt', text })
      shareMessage.value = 'Receipt shared.'
    } else if (navigator.clipboard) {
      await navigator.clipboard.writeText(text)
      shareMessage.value = 'Receipt copied.'
    } else {
      manualCopy.value = text
    }
  } catch (error) {
    if (error.name !== 'AbortError') manualCopy.value = text
  } finally {
    sharing.value = false
  }

  if (manualCopy.value) {
    await nextTick()
    copyField.value.focus()
    copyField.value.select()
  }
}
</script>

<template>
  <POSLayout>
    <template #header
      ><CashierPageHeader title="Receipt" :back="ordersRoute" back-label="Back to Orders"
    /></template>

    <p v-if="status === 'loading'" class="screen-note" role="status">Loading receipt…</p>
    <section v-else-if="status === 'error'" class="grid gap-3">
      <p class="screen-error" role="alert">{{ error }}</p>
      <button class="dialog-action justify-self-start" type="button" @click="load">
        Try again
      </button>
    </section>
    <section v-else-if="!receipt" class="grid gap-3">
      <h2 class="section-title">Receipt unavailable</h2>
      <p class="screen-note">Open Orders to look for the receipt.</p>
      <RouterLink class="dialog-action justify-self-start" :to="{ name: ordersRoute }"
        >Open Orders</RouterLink
      >
    </section>
    <div v-else class="grid gap-3">
      <p v-if="saleCompleted" class="money-value" role="status">Sale completed.</p>
      <article class="receipt-paper" aria-labelledby="receipt-title">
        <div class="receipt-heading">
          <h2 id="receipt-title" class="section-title">TRI-M Mini-Mart</h2>
          <strong class="[overflow-wrap:anywhere]">{{ receipt.receiptNo }}</strong>
        </div>
        <dl class="receipt-details">
          <div class="summary-line">
            <dt>Date</dt>
            <dd>
              <time :datetime="receipt.createdAt">{{ transactionDate(receipt.createdAt) }}</time>
            </dd>
          </div>
          <div class="summary-line">
            <dt>Cashier</dt>
            <dd>{{ receipt.cashier }}</dd>
          </div>
        </dl>
        <PosTableRegion aria-label="Purchased products">
          <table class="data-table">
            <thead>
              <tr>
                <th scope="col">Item</th>
                <th scope="col" class="number-cell">Qty</th>
                <th scope="col" class="number-cell">Unit price</th>
                <th scope="col" class="number-cell">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in receipt.lines" :key="line.id">
                <th scope="row" class="font-semibold">{{ line.name }}</th>
                <td class="number-cell">{{ line.quantity }}</td>
                <td class="number-cell">{{ formatPrice(line.unitPrice) }}</td>
                <td class="number-cell font-semibold">{{ formatPrice(line.total) }}</td>
              </tr>
            </tbody>
          </table>
        </PosTableRegion>
        <dl class="receipt-details">
          <div class="summary-line text-lg font-bold">
            <dt>Total</dt>
            <dd class="money-value">{{ formatPrice(receipt.total) }}</dd>
          </div>
          <div class="summary-line">
            <dt>Payment</dt>
            <dd>{{ paymentLabel(receipt.payment) }}</dd>
          </div>
          <template v-if="receipt.payment.method === 'cash'">
            <div class="summary-line">
              <dt>Amount received</dt>
              <dd>{{ formatPrice(receipt.payment.received) }}</dd>
            </div>
            <div class="summary-line">
              <dt>Change</dt>
              <dd>{{ formatPrice(receipt.payment.change) }}</dd>
            </div>
          </template>
          <div v-else class="summary-line">
            <dt>Reference</dt>
            <dd class="[overflow-wrap:anywhere]">{{ receipt.payment.reference }}</dd>
          </div>
        </dl>
      </article>
      <button
        class="dialog-action dialog-action--primary"
        type="button"
        :disabled="sharing"
        @click="shareReceipt"
      >
        Share receipt
      </button>
      <p v-if="shareMessage" class="screen-note" role="status">{{ shareMessage }}</p>
      <div v-if="manualCopy" class="dialog-field">
        <p class="screen-note" role="status">Copy the receipt text below to share it.</p>
        <label for="receipt-copy">Receipt text</label>
        <textarea
          id="receipt-copy"
          ref="copyField"
          class="receipt-copy"
          rows="6"
          readonly
          :value="manualCopy"
        ></textarea>
      </div>
      <div class="screen-actions">
        <RouterLink v-if="ordersRoute === 'orders'" class="dialog-action" :to="{ name: 'sell' }"
          >Return to Sell</RouterLink
        >
        <RouterLink class="dialog-action" :to="{ name: ordersRoute }">Open Orders</RouterLink>
      </div>
    </div>
  </POSLayout>
</template>

<style scoped>
.receipt-paper {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.875rem;
  padding: clamp(0.875rem, 3vw, 1.25rem);
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
}

.receipt-heading {
  display: grid;
  gap: 0.25rem;
  text-align: center;
}

.receipt-details {
  display: grid;
  gap: 0.5rem;
  border-block-start: 1px dashed #a9b2ab;
  padding-block-start: 0.875rem;
}

.receipt-copy {
  padding: 0.625rem;
  border: 1px solid #a9b2ab;
  border-radius: 0.625rem;
}
</style>
