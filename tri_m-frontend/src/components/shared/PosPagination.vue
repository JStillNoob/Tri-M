<script setup>
import { computed, useId } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'

const props = defineProps({
  page: { type: Number, required: true },
  pageSize: { type: Number, default: null },
  total: { type: Number, default: 0 },
  lastPage: { type: Number, required: true },
  label: { type: String, default: 'Product pages' },
  loading: Boolean,
})
const emit = defineEmits(['update:page', 'update:pageSize'])
const sizeId = useId()
const sizes = [10, 25, 50, 100]
const format = new Intl.NumberFormat('en-PH')
const firstItem = computed(() => (props.total ? (props.page - 1) * props.pageSize + 1 : 0))
const lastItem = computed(() => Math.min(props.page * props.pageSize, props.total))
const pages = computed(() => {
  const last = props.lastPage
  const current = props.page
  if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1)
  if (current <= 4) return [1, 2, 3, 4, 5, 'end', last]
  if (current >= last - 3) return [1, 'start', last - 4, last - 3, last - 2, last - 1, last]
  return [1, 'start', current - 1, current, current + 1, 'end', last]
})
</script>

<template>
  <footer class="pagination-bar" :class="{ 'pagination-bar--compact': !pageSize }">
    <p class="range" aria-live="polite">
      <template v-if="!pageSize">Page {{ page }} of {{ lastPage }}</template>
      <template v-else-if="total"
        >Showing {{ format.format(firstItem) }} to {{ format.format(lastItem) }} of
        {{ format.format(total) }}</template
      >
      <template v-else>0 products</template>
    </p>
    <nav v-if="lastPage > 1" class="page-nav" :aria-label="label">
      <button
        type="button"
        aria-label="Previous page"
        :disabled="page <= 1 || loading"
        @click="emit('update:page', page - 1)"
      >
        <ChevronLeft :size="18" aria-hidden="true" />
      </button>
      <template v-for="item in pages" :key="item">
        <button
          v-if="typeof item === 'number'"
          type="button"
          :aria-label="'Page ' + item"
          :aria-current="item === page ? 'page' : undefined"
          :disabled="loading"
          @click="emit('update:page', item)"
        >
          {{ item }}
        </button>
        <span v-else class="page-gap" aria-hidden="true">…</span>
      </template>
      <button
        type="button"
        aria-label="Next page"
        :disabled="page >= lastPage || loading"
        @click="emit('update:page', page + 1)"
      >
        <ChevronRight :size="18" aria-hidden="true" />
      </button>
    </nav>
    <div v-if="pageSize" class="page-size">
      <label :for="sizeId">Per page</label>
      <select
        :id="sizeId"
        :value="pageSize"
        @change="emit('update:pageSize', Number($event.target.value))"
      >
        <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
      </select>
    </div>
  </footer>
</template>

<style scoped>
.pagination-bar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: 0.75rem 1rem;
  padding: 0.85rem 1.1rem;
  border-block-start: 1px solid var(--pos-line);
  color: var(--pos-muted);
  font-size: 0.875rem;
  font-variant-numeric: tabular-nums;
}

.pagination-bar--compact {
  grid-template-columns: minmax(0, 1fr) auto;
}

.range {
  grid-column: 1;
}
.page-nav {
  grid-column: 2;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  align-items: center;
  gap: 0.2rem;
}

.page-nav button,
.page-gap {
  display: inline-grid;
  min-width: 2.75rem;
  min-height: 2.75rem;
  place-items: center;
  padding-inline: 0.25rem;
  border-radius: 0.55rem;
  font-weight: 600;
}

.page-nav button:is(:hover, [aria-current='page']):enabled,
.page-nav button[aria-current='page'] {
  color: var(--owner-deep, var(--pos-green-deep));
  background: var(--owner-pale, var(--pos-mint));
}

.page-nav button:disabled:not([aria-current]) {
  color: #788980;
}

.page-size {
  grid-column: 3;
  justify-self: end;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.page-size select {
  min-height: 2.75rem;
  padding: 0.3rem 0.5rem;
  border: 1px solid #a8b7ac;
  border-radius: 0.55rem;
  color: var(--pos-ink);
  background: white;
}

@media (max-width: 56rem) {
  .pagination-bar {
    grid-template-columns: minmax(0, 1fr) auto;
  }
  .page-nav {
    grid-column: 1 / -1;
    grid-row: 2;
  }
  .page-size {
    grid-column: 2;
    grid-row: 1;
  }
}

@media (max-width: 29rem) {
  .pagination-bar {
    padding-inline: 0.75rem;
  }
}
</style>
