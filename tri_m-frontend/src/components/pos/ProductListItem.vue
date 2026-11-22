<script setup>
import { computed } from 'vue'
import { formatPrice } from '@/utils/price'
import QuantityStepper from './QuantityStepper.vue'

const props = defineProps({
  product: { type: Object, required: true },
  quantity: { type: Number, required: true },
})
defineEmits(['add', 'remove'])

const price = computed(() => formatPrice(props.product.priceCentavos))
const soldOut = computed(() => props.product.stock <= 0)
const canAdd = computed(() => props.quantity < props.product.stock)
const availability = computed(() => {
  if (soldOut.value) {
    return 'Out of stock'
  }

  return canAdd.value ? '' : 'All available units are in the basket'
})
</script>

<template>
  <li class="product-row" :class="{ 'product-row--in-basket': quantity > 0 }">
    <button class="product-add" type="button" :disabled="!canAdd" @click="$emit('add', product)">
      <span class="product-description">
        <span class="product-name">{{ product.name }}</span>
        <span v-if="product.category" class="product-category">{{ product.category }}</span>
        <span v-if="soldOut" class="product-category product-stock-status">Out of stock</span>
      </span>
      <strong class="product-price">{{ price }}</strong>
      <span v-if="canAdd" class="sr-only">Add to basket</span>
      <span v-else-if="!soldOut" class="sr-only">{{ availability }}</span>
    </button>
    <QuantityStepper
      :name="product.name"
      :quantity="quantity"
      :can-add="canAdd"
      :availability="availability"
      :show-limit="!soldOut && !canAdd"
      @increase="$emit('add', product)"
      @decrease="$emit('remove', product)"
    />
  </li>
</template>

<style scoped>
.product-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: clamp(0.5rem, 2.4vw, 1.25rem);
  min-block-size: 4.25rem;
  padding: 0.375rem clamp(0.375rem, 1.8vw, 0.875rem);
  text-align: start;
  font-size: clamp(1rem, 4.7vw, 1.25rem);
  line-height: 1.25;
}

.product-row--in-basket {
  background: #f5fbf6;
}

.product-row:hover,
.product-row:focus-within {
  background: var(--pos-mint);
}

.product-add {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.5rem;
  min-block-size: 3.375rem;
  padding-block: 0.375rem;
  border-radius: 0.5rem;
  text-align: start;
}

.product-add:enabled:hover .product-name {
  text-decoration: underline;
  text-underline-offset: 0.18em;
}

.product-add:enabled:active {
  transform: translateY(1px);
}

.product-name {
  overflow-wrap: anywhere;
  font-weight: 625;
}

.product-description {
  display: grid;
  gap: 0.25rem;
  min-inline-size: 0;
}

.product-category {
  color: var(--pos-muted);
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}

.product-stock-status {
  font-weight: 650;
}

.product-price {
  color: var(--pos-green);
  font-size: 0.95em;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

@container (max-width: 16rem) {
  .product-row {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.375rem;
  }

  .product-add {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
