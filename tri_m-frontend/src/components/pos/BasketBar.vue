<script setup>
import PosIcon from '@/components/shared/PosIcon.vue'
import { formatPrice } from '@/utils/price'

defineProps({
  quantity: { type: Number, required: true },
  totalCentavos: { type: Number, required: true },
})
defineEmits(['checkout'])
</script>

<template>
  <footer id="basket" class="pos-column basket-footer" aria-label="Basket" tabindex="-1">
    <div class="basket-bar">
      <div class="basket-summary" role="status" aria-atomic="true">
        <span class="basket-count">
          <PosIcon name="cart" />
          <span>{{ quantity }} {{ quantity === 1 ? 'item' : 'items' }}</span>
        </span>
        <strong class="basket-total">{{ formatPrice(totalCentavos) }}</strong>
      </div>
      <button
        class="basket-checkout"
        type="button"
        :disabled="quantity === 0"
        @click="$emit('checkout')"
      >
        Checkout
        <PosIcon name="chevron" />
      </button>
    </div>
  </footer>
</template>

<style scoped>
.basket-footer {
  position: sticky;
  z-index: 1;
  inset-block-end: 0;
  padding-block-end: max(0.75rem, env(safe-area-inset-bottom));
}

.basket-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: stretch;
  gap: 0.25rem;
  padding: 0.25rem;
  border-radius: 1.75rem;
  background: var(--pos-green);
  color: white;
}

.basket-summary {
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-evenly;
  gap: 0 0.25rem;
  padding-inline: 0.25rem;
  text-align: center;
}

.basket-count {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  font-size: clamp(0.75rem, 3.65vw, 1rem);
  white-space: nowrap;
}

.basket-total {
  border-inline-start: 1px solid #ffffff80;
  padding-inline-start: clamp(0.35rem, 2.2vw, 1rem);
  font-size: clamp(0.9rem, 4.35vw, 1.375rem);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.basket-checkout {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
  min-block-size: clamp(2.75rem, 12vw, 3.5rem);
  margin-inline-start: auto;
  padding-inline: clamp(0.6rem, 2.5vw, 1.5rem);
  border-radius: 1.5rem;
  background: var(--pos-paper);
  color: var(--pos-green-deep);
  font-size: clamp(0.875rem, 4.1vw, 1.125rem);
  font-weight: 700;
}

.basket-checkout:focus-visible {
  outline-color: var(--pos-green-deep);
  outline-offset: -4px;
}

.basket-checkout:enabled:hover {
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.basket-checkout:enabled:active {
  transform: translateY(1px);
}

.basket-checkout:disabled {
  background: var(--pos-mint);
  color: var(--pos-muted);
}

@container (max-width: 16rem) {
  .basket-bar {
    flex-direction: column;
    gap: 0.125rem;
  }

  .basket-summary {
    padding: 0.25rem;
  }

  .basket-count > .pos-icon {
    inline-size: 28px;
    block-size: 28px;
  }

  .basket-total {
    padding: 0;
    border: 0;
  }

  .basket-checkout {
    inline-size: 100%;
    min-block-size: 44px;
  }
}

@media (min-width: 34rem) {
  .basket-footer {
    padding-block-end: max(1rem, env(safe-area-inset-bottom));
  }

  .basket-summary {
    justify-content: start;
    gap: 1rem;
    padding-inline: 1.25rem;
  }

  .basket-checkout {
    min-inline-size: 10rem;
  }
}
</style>
