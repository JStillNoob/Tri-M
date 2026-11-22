<script setup>
import PosIcon from '@/components/shared/PosIcon.vue'

defineProps({
  title: { type: String, required: true },
  back: { type: String, default: 'sell' },
  backLabel: { type: String, default: 'Back to Sell' },
})
</script>

<template>
  <header class="pos-column cashier-heading">
    <RouterLink class="icon-button" :to="{ name: back }" :aria-label="backLabel">
      <PosIcon name="back" />
    </RouterLink>
    <h1 class="screen-title">{{ title }}</h1>
  </header>
</template>

<style scoped>
.cashier-heading {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.5rem;
  border-block-end: 1px solid var(--pos-line);
  padding-block-end: 0.75rem;
}

.cashier-heading h1 {
  text-align: center;
}

.cashier-heading::after {
  width: 2.75rem;
  content: '';
}
</style>
