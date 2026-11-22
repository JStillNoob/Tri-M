<script setup>
import PosIcon from '@/components/shared/PosIcon.vue'

defineProps({
  name: { type: String, required: true },
  quantity: { type: Number, required: true },
  minimum: { type: Number, default: 0 },
  canAdd: { type: Boolean, required: true },
  availability: { type: String, default: '' },
  showLimit: { type: Boolean, default: false },
})
defineEmits(['increase', 'decrease'])
</script>

<template>
  <div class="product-quantity" role="group" :aria-label="name + ' basket quantity'">
    <button
      class="quantity-button"
      type="button"
      :aria-label="'Remove one ' + name"
      :disabled="quantity <= minimum"
      @click="$emit('decrease')"
    >
      <PosIcon name="minus" />
    </button>
    <span class="quantity-value">
      {{ quantity }}
      <small v-if="showLimit">Max</small>
    </span>
    <button
      class="quantity-button quantity-button--add"
      type="button"
      :disabled="!canAdd"
      :aria-label="['Add one ' + name, availability].filter(Boolean).join('. ')"
      :title="availability || undefined"
      @click="$emit('increase')"
    >
      <PosIcon name="plus" />
    </button>
  </div>
</template>

<style scoped>
.product-quantity {
  display: inline-flex;
  align-items: center;
  justify-self: end;
  gap: 0.125rem;
}

.quantity-button {
  display: grid;
  place-items: center;
  inline-size: 44px;
  block-size: 44px;
  border-radius: 0.625rem;
  background: var(--pos-control);
  color: var(--pos-green-deep);
}

.quantity-button--add {
  background: transparent;
}

.quantity-button:enabled:hover {
  outline: 1px solid var(--pos-green);
  outline-offset: -2px;
}

.quantity-button:focus-visible {
  outline: 3px solid var(--pos-green-deep);
  outline-offset: 2px;
}

.quantity-button:enabled:active {
  transform: translateY(1px);
}

.quantity-button:disabled {
  background: transparent;
  color: var(--pos-muted);
  opacity: 0.55;
}

.quantity-button .pos-icon {
  inline-size: 1rem;
  block-size: 1rem;
}

.quantity-value {
  display: grid;
  min-inline-size: 2ch;
  font-size: 0.875em;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
  text-align: center;
}

.quantity-value small {
  color: var(--pos-muted);
  font-size: 0.625rem;
}
</style>
