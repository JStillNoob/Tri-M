<script setup>
defineProps({
  categories: { type: Array, required: true },
})

const selected = defineModel({ type: String, required: true })

function revealCategory(event) {
  event.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' })
}
</script>

<template>
  <div class="category-chips" role="group" aria-label="Product categories">
    <button
      v-for="category in categories"
      :key="category"
      class="category-chip"
      type="button"
      :aria-pressed="selected === category"
      @focus="revealCategory"
      @click="selected = category"
    >
      {{ category }}
    </button>
  </div>
</template>

<style scoped>
.category-chips {
  display: flex;
  gap: clamp(0.25rem, 1.4vw, 0.75rem);
  min-inline-size: 0;
  overflow-x: auto;
  padding: 0.375rem 0.375rem 0.75rem;
  border-block-end: 1px solid var(--pos-line);
  scroll-padding-inline: 0.375rem;
}

.category-chip {
  position: relative;
  flex: 1 0 auto;
  min-inline-size: 2.75rem;
  max-inline-size: min(16rem, 70vw);
  min-block-size: 2.75rem;
  padding: 0.25rem clamp(0.375rem, 1.55vw, 0.875rem);
  border-radius: 1.5rem;
  background: var(--pos-control);
  font-size: clamp(0.8125rem, 3.55vw, 1rem);
  font-weight: 550;
  overflow-wrap: anywhere;
}

.category-chip:hover {
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.category-chip:active {
  transform: translateY(1px);
}

.category-chip[aria-pressed='true'] {
  background: var(--pos-green);
  color: white;
  font-weight: 700;
}

.category-chip[aria-pressed='true']::after {
  position: absolute;
  inset: auto 0 -0.5rem;
  block-size: 2px;
  background: var(--pos-green);
  content: '';
}

@container (max-width: 16rem) {
  .category-chip {
    min-block-size: max(44px, 1.5em);
  }
}

@media (min-width: 34rem) {
  .category-chip {
    flex-grow: 0;
    min-inline-size: 5.25rem;
  }
}
</style>
