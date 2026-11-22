<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import CashierPageHeader from '@/components/pos/CashierPageHeader.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { completeCheckout } from '@/services/checkout'
import { invalidateReads } from '@/services/posReadCache'
import { useCartStore } from '@/stores/cartStore'
import { useSessionStore } from '@/stores/sessionStore'
import { formatPrice, parsePrice } from '@/utils/price'
import { walletProviders } from '@/utils/sales'
import { PosError, useFormFeedback, userMessage } from '@/utils/userFeedback'

const router = useRouter()
const cart = useCartStore()
cart.revalidate()
const session = useSessionStore()
const method = ref('cash')
const cash = ref('')
const provider = ref('')
const reference = ref('')
const submitting = ref(false)
const feedback = useFormFeedback(['payment.amount_tendered', 'payment.wallet_provider', 'payment.reference_no'])
const { alert: error } = feedback
const amountReceived = computed(() => parsePrice(cash.value))
const change = computed(() =>
  amountReceived.value !== null && amountReceived.value >= cart.total
    ? amountReceived.value - cart.total
    : null,
)

watch([method, cash, provider, reference], feedback.clear)

async function completeSale() {
  if (submitting.value || cart.status !== 'ready' || cart.unavailable.size) return

  feedback.clear()
  if (method.value === 'cash' && change.value === null) {
    feedback.show(new PosError('', { fields: { 'payment.amount_tendered': ['Enter enough cash to cover the total.'] } }))
    return
  }
  submitting.value = true
  await nextTick()

  try {
    const products = [...cart.quantities.keys()].map((id) => 'product:' + id)
    const payment =
      method.value === 'cash'
        ? { method: 'cash', amount_tendered: (amountReceived.value / 100).toFixed(2) }
        : {
            method: 'ewallet',
            wallet_provider: provider.value.toLowerCase(),
            reference_no: reference.value.trim(),
          }
    const sale = await completeCheckout(session.account.id, cart.lines, payment)
    cart.clear()
    invalidateReads([
      'products',
      'sales-overview',
      'recent-orders',
      'sales-targets',
      'orders',
      'low-stock',
      ...products,
    ])
    await router.replace({ name: 'receipt', params: { id: sale.id }, state: { saleCompleted: true } })
  } catch (problem) {
    feedback.show(problem, 'We could not confirm the sale. Your basket was kept. Check Orders before trying again.')
    if ([409, 422].includes(problem.status)) {
      invalidateReads(
        ['products', ...[...cart.quantities.keys()].map((id) => 'product:' + id)],
        false,
      )
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <POSLayout>
    <template #header>
      <CashierPageHeader title="Payment" back="basket" back-label="Back to basket" />
    </template>

    <p v-if="cart.status === 'loading'" class="screen-note" role="status">Loading payment…</p>
    <section v-else-if="cart.status === 'error'" class="grid gap-3">
      <p class="screen-error" role="alert">{{ userMessage(cart.loadError, 'We could not load your basket. Try again.') }}</p>
      <button class="dialog-action justify-self-start" type="button" @click="cart.refresh()">
        Try again
      </button>
    </section>
    <section v-else-if="cart.unavailable.size" class="grid gap-3">
      <p role="status">Review unavailable products in the basket before completing the sale.</p>
      <RouterLink class="dialog-action justify-self-start" :to="{ name: 'basket' }"
        >Back to basket</RouterLink
      >
    </section>
    <section v-else-if="!cart.lines.length" class="grid gap-3">
      <h2 class="section-title">Your basket is empty</h2>
      <RouterLink class="dialog-action justify-self-start" :to="{ name: 'sell' }"
        >Browse products</RouterLink
      >
    </section>
    <form v-else id="payment-form" class="grid gap-5" :aria-busy="submitting" @submit.prevent="completeSale">
      <dl class="payment-total">
        <div class="summary-line items-center">
          <dt>Amount to collect</dt>
          <dd class="money-value">{{ formatPrice(cart.total) }}</dd>
        </div>
      </dl>

      <div class="grid gap-4">
        <fieldset class="payment-methods">
          <legend class="section-title">Payment method</legend>
          <label>
            <span>Cash</span>
            <input v-model="method" name="payment-method" type="radio" value="cash" />
          </label>
          <label>
            <span>E-wallet</span>
            <input v-model="method" name="payment-method" type="radio" value="ewallet" />
          </label>
        </fieldset>

        <template v-if="method === 'cash'">
          <div class="dialog-field">
            <label for="cash-received">Amount received (₱)</label>
            <input
              id="cash-received"
              v-model="cash"
              inputmode="decimal"
              autocomplete="off"
              v-bind="feedback.bind('payment.amount_tendered')"
              required
            />
            <PosFieldError :id="feedback.fieldId('payment.amount_tendered')" :message="feedback.inline('payment.amount_tendered')" />
          </div>
          <div class="summary-line border-t border-[var(--pos-line)] pt-3">
            <span>Change</span>
            <output class="money-value" for="cash-received" aria-live="polite">
              {{ change === null ? '–' : formatPrice(change) }}
            </output>
          </div>
        </template>
        <template v-else>
          <div class="dialog-field">
            <label for="payment-wallet">Wallet provider</label>
            <select id="payment-wallet" v-model="provider" required v-bind="feedback.bind('payment.wallet_provider')">
              <option value="" disabled>Choose a wallet</option>
              <option v-for="wallet in walletProviders" :key="wallet" :value="wallet">
                {{ wallet }}
              </option>
            </select>
            <PosFieldError :id="feedback.fieldId('payment.wallet_provider')" :message="feedback.inline('payment.wallet_provider')" />
          </div>
          <div class="dialog-field">
            <label for="payment-reference">Reference number</label>
            <input
              id="payment-reference"
              v-model.trim="reference"
              maxlength="100"
              autocomplete="off"
              v-bind="feedback.bind('payment.reference_no')"
              required
            />
            <PosFieldError :id="feedback.fieldId('payment.reference_no')" :message="feedback.inline('payment.reference_no')" />
          </div>
          <p class="screen-note">
            Confirm receipt in the wallet app before recording the reference.
          </p>
        </template>
      </div>
      <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
    </form>

    <template v-if="cart.status === 'ready' && !cart.unavailable.size && cart.lines.length" #footer>
      <footer class="pos-column cashier-action">
        <button
          class="dialog-action dialog-action--primary"
          type="submit"
          form="payment-form"
          :disabled="submitting"
        >
          {{ submitting ? 'Recording sale…' : 'Complete sale' }}
        </button>
      </footer>
    </template>
  </POSLayout>
</template>

<style scoped>
.payment-total {
  padding-block-end: 1rem;
  border-block-end: 1px solid var(--pos-line);
}

.payment-total dd {
  font-size: clamp(1.75rem, 7vw, 2.5rem);
  line-height: 1.15;
}

.payment-methods {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.payment-methods legend {
  margin-block-end: 0.5rem;
}

.payment-methods label {
  display: flex;
  flex: 1 1 7rem;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  min-height: 2.75rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid #a9b2ab;
  border-radius: 0.625rem;
  cursor: pointer;
}

.payment-methods label:has(:focus-visible) {
  outline: 3px solid var(--pos-green-deep);
  outline-offset: 2px;
}

.payment-methods label:has(:checked) {
  border-color: var(--pos-green);
  background: var(--pos-mint);
  font-weight: 700;
}

.payment-methods input {
  inline-size: 1rem;
  block-size: 1rem;
  accent-color: var(--pos-green);
}
</style>
