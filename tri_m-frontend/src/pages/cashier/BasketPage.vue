<script setup>
import CashierPageHeader from '@/components/pos/CashierPageHeader.vue'
import QuantityStepper from '@/components/pos/QuantityStepper.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { useCartStore } from '@/stores/cartStore'
import { formatPrice } from '@/utils/price'
import { userMessage } from '@/utils/userFeedback'

const cart = useCartStore()
cart.revalidate()
</script>

<template>
  <POSLayout>
    <template #header><CashierPageHeader title="Basket" /></template>

    <p v-if="cart.status === 'loading'" role="status" class="screen-note">Loading the basket…</p>
    <div v-else-if="cart.status === 'error'" class="grid gap-3">
      <p class="screen-error" role="alert">{{ userMessage(cart.loadError, 'We could not load your basket. Try again.') }}</p>
      <button class="dialog-action justify-self-start" type="button" @click="cart.refresh()">
        Try again
      </button>
    </div>
    <section v-else-if="cart.unavailable.size" class="grid gap-3">
      <p role="status">Some products are no longer available. Remove them to continue.</p>
      <button
        class="dialog-action justify-self-start"
        type="button"
        @click="cart.removeUnavailable()"
      >
        Remove unavailable products
      </button>
    </section>
    <section v-else-if="!cart.lines.length" class="grid gap-3">
      <h2 class="section-title">Your basket is empty</h2>
      <p class="screen-note">Add a product from the Sell screen.</p>
      <RouterLink class="dialog-action justify-self-start" :to="{ name: 'sell' }"
        >Browse products</RouterLink
      >
    </section>
    <div v-else class="basket-review">
      <ul class="divided-list basket-list" aria-label="Basket products" role="list">
        <li
          v-for="{ product, quantity } in cart.lines"
          :key="product.id"
          class="basket-line"
        >
          <div class="min-w-0">
            <h2 class="font-semibold [overflow-wrap:anywhere]">{{ product.name }}</h2>
            <p class="screen-note">{{ formatPrice(product.priceCentavos) }} per unit</p>
          </div>
          <strong class="money-value">{{ formatPrice(product.priceCentavos * quantity) }}</strong>
          <button
            class="basket-remove"
            type="button"
            :aria-label="'Remove ' + product.name"
            @click="cart.remove(product)"
          >
            Remove
          </button>
          <QuantityStepper
            :name="product.name"
            :quantity="quantity"
            :minimum="1"
            :can-add="quantity < product.stock"
            :show-limit="quantity >= product.stock"
            :availability="quantity >= product.stock ? 'All available units are in the basket' : ''"
            @increase="cart.add(product)"
            @decrease="cart.decrease(product)"
          />
        </li>
      </ul>
      <section class="basket-summary grid gap-3 self-start" aria-labelledby="basket-summary-title">
        <h2 id="basket-summary-title" class="section-title">Order summary</h2>
        <dl class="grid gap-2" aria-live="polite">
          <div class="summary-line">
            <dt>Items</dt>
            <dd>{{ cart.quantity }}</dd>
          </div>
          <div class="summary-line">
            <dt>Subtotal</dt>
            <dd>{{ formatPrice(cart.total) }}</dd>
          </div>
          <div class="summary-line border-t border-[var(--pos-line)] pt-2 font-bold">
            <dt>Total</dt>
            <dd class="money-value">{{ formatPrice(cart.total) }}</dd>
          </div>
        </dl>
        <RouterLink class="dialog-action justify-self-start" :to="{ name: 'sell' }">Continue shopping</RouterLink>
      </section>
    </div>

    <template v-if="cart.status === 'ready' && !cart.unavailable.size && cart.lines.length" #footer>
      <footer class="pos-column cashier-action">
        <RouterLink class="dialog-action dialog-action--primary" :to="{ name: 'payment' }">
          Proceed to payment
        </RouterLink>
      </footer>
    </template>
  </POSLayout>
</template>

<style scoped>
.basket-review {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1rem;
}

.basket-list {
  align-self: start;
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
  background: var(--pos-paper);
}

.basket-list > :first-child {
  border-block-start: 0;
}

.basket-list > :last-child {
  border-block-end: 0;
}

.basket-line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.2rem 0.75rem;
  padding: 0.625rem 0.75rem;
}

.basket-summary {
  padding: clamp(0.875rem, 3vw, 1.25rem);
  border-radius: 0.625rem;
  background: #f4faf5;
}

.basket-remove {
  justify-self: start;
  min-height: 2.75rem;
  color: var(--pos-muted);
  font-size: 0.875rem;
  text-decoration: underline;
  text-underline-offset: 0.18em;
}

.basket-remove:hover {
  color: var(--pos-green-deep);
}

@media (min-width: 42rem) {
  .basket-review {
    grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
  }
}
</style>
