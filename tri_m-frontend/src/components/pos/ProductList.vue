<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ProductListItem from './ProductListItem.vue'

const props = defineProps({
  products: { type: Array, required: true },
  quantities: { type: Map, required: true },
  status: { type: String, required: true },
  errorMessage: { type: String, required: true },
  hasSearch: { type: Boolean, required: true },
  hasNarrowedResults: { type: Boolean, required: true },
  offset: { type: Number, default: 0 },
  total: { type: Number, default: 0 },
  hasPreviousBatch: Boolean,
  hasNextBatch: Boolean,
})
const emit = defineEmits(['add', 'remove', 'retry', 'reset', 'batch'])

const productList = ref(null)
const pagination = ref(null)
const page = ref(1)
const pageStarts = ref([0])
const measuring = ref(false)
let layoutFrame = 0
let basketObserver
let finishAtEnd = false

const pageCount = computed(() => pageStarts.value.length)
const hasBatches = computed(() => props.hasPreviousBatch || props.hasNextBatch)
const visibleProducts = computed(() => {
  if (measuring.value) {
    return props.products
  }

  return props.products.slice(pageStarts.value[page.value - 1], pageStarts.value[page.value])
})

function findPageStarts(list, controls) {
  const { top, height } = list.getBoundingClientRect()
  const basketHeight = document.getElementById('basket')?.getBoundingClientRect().height ?? 0
  const mainPadding = Number.parseFloat(getComputedStyle(list.closest('main')).paddingBlockEnd)
  const availableHeight = window.innerHeight - (top + window.scrollY) - basketHeight - mainPadding
  const starts = [0]

  if (!hasBatches.value && height <= availableHeight) {
    return starts
  }

  const controlHeight =
    controls.getBoundingClientRect().height +
    Number.parseFloat(getComputedStyle(controls).marginBlockStart)
  const pageHeight = availableHeight - controlHeight
  const rowGap = Number.parseFloat(getComputedStyle(list).rowGap)
  let filledHeight = 0

  for (const [index, row] of Array.from(list.children).entries()) {
    const rowHeight = row.getBoundingClientRect().height

    if (filledHeight > 0 && filledHeight + rowGap + rowHeight > pageHeight) {
      starts.push(index)
      filledHeight = rowHeight
    } else {
      filledHeight += filledHeight > 0 ? rowGap + rowHeight : rowHeight
    }
  }

  return starts
}

async function layoutCatalog() {
  if (props.status !== 'ready' || props.products.length < 2) {
    if (props.status === 'ready') finishAtEnd = false
    return
  }

  const firstVisibleIndex = pageStarts.value[page.value - 1] ?? 0
  measuring.value = true
  await nextTick()

  if (productList.value && pagination.value) {
    const starts = findPageStarts(productList.value, pagination.value)
    pageStarts.value = starts
    page.value = finishAtEnd
      ? starts.length
      : starts.findLastIndex((start) => start <= firstVisibleIndex) + 1
    finishAtEnd = false
  }

  measuring.value = false
}

function goBack() {
  if (page.value > 1) page.value--
  else if (props.hasPreviousBatch) {
    finishAtEnd = true
    emit('batch', -1)
  }
}

function goForward() {
  if (page.value < pageCount.value) page.value++
  else if (props.hasNextBatch) emit('batch', 1)
}

function queueLayout() {
  window.cancelAnimationFrame(layoutFrame)
  layoutFrame = window.requestAnimationFrame(layoutCatalog)
}

watch([() => props.products, () => props.status], () => {
  page.value = 1
  pageStarts.value = [0]
  queueLayout()
})

onMounted(() => {
  window.addEventListener('resize', queueLayout)
  const basket = document.getElementById('basket')

  if (basket) {
    basketObserver = new ResizeObserver(queueLayout)
    basketObserver.observe(basket)
  }

  queueLayout()
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', queueLayout)
  window.cancelAnimationFrame(layoutFrame)
  basketObserver?.disconnect()
})
</script>

<template>
  <section id="products" class="product-catalog" aria-labelledby="products-heading" tabindex="-1">
    <div class="catalog-heading">
      <h2 id="products-heading">Products</h2>
      <span v-if="status === 'ready' && products.length > 0">In basket</span>
    </div>
    <p v-if="status === 'loading'" class="catalog-state" role="status">Loading products…</p>
    <div v-else-if="status === 'error'" class="catalog-state">
      <p role="alert">{{ errorMessage }}</p>
      <button class="dialog-action" type="button" @click="$emit('retry')">Try again</button>
    </div>
    <div v-else-if="products.length === 0" class="catalog-state">
      <p role="status">
        <template v-if="hasSearch">No products match your search.</template>
        <template v-else-if="hasNarrowedResults">No products match these filters.</template>
        <template v-else>There are no products in the catalog.</template>
      </p>
      <button v-if="hasNarrowedResults" class="dialog-action" type="button" @click="$emit('reset')">
        Show all products
      </button>
    </div>
    <ul v-else ref="productList" class="divided-list grid gap-0" role="list">
      <ProductListItem
        v-for="product in visibleProducts"
        :key="product.id"
        :product="product"
        :quantity="quantities.get(product.id) ?? 0"
        @add="$emit('add', $event)"
        @remove="$emit('remove', $event)"
      />
    </ul>
    <nav
      v-if="status === 'ready' && (pageCount > 1 || measuring || hasBatches)"
      ref="pagination"
      class="catalog-pagination"
      :class="{ 'catalog-pagination--measuring': measuring }"
      aria-label="Product pages"
    >
      <button
        class="catalog-page-button"
        type="button"
        :disabled="page <= 1 && !hasPreviousBatch"
        @click="goBack"
      >
        Previous
      </button>
      <p v-if="hasBatches" class="catalog-page-number" role="status">
        {{ offset + pageStarts[page - 1] + 1 }}–{{
          offset + (pageStarts[page] ?? products.length)
        }}
        of {{ total }}
      </p>
      <p v-else class="catalog-page-number" role="status">Page {{ page }} of {{ pageCount }}</p>
      <button
        class="catalog-page-button"
        type="button"
        :disabled="page >= pageCount && !hasNextBatch"
        @click="goForward"
      >
        Next
      </button>
    </nav>
  </section>
</template>

<style scoped>
.product-catalog {
  position: relative;
}

.catalog-heading {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.25rem 0.5rem;
  margin-block-end: 0.45rem;
  color: var(--pos-muted);
  font-size: 0.8125rem;
  font-weight: 650;
}

.catalog-heading span {
  padding-inline-end: clamp(0.6875rem, 3.4vw, 1.5rem);
}

.catalog-state {
  display: grid;
  justify-items: start;
  gap: 0.75rem;
  padding-block: 1rem;
  border-block-start: 1px solid var(--pos-line);
  color: var(--pos-muted);
}

.catalog-pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.375rem 0.625rem;
  margin-block-start: 0.75rem;
}

.catalog-pagination--measuring {
  position: absolute;
  inset: 0 0 auto;
  visibility: hidden;
}

.catalog-page-button {
  min-inline-size: 2.75rem;
  min-block-size: 2.75rem;
  padding: 0.375rem 0.75rem;
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
  background: var(--pos-paper);
  color: var(--pos-green-deep);
  font-size: 0.875rem;
  font-weight: 650;
}

.catalog-page-button:enabled:hover {
  border-color: var(--pos-green);
  text-decoration: underline;
  text-underline-offset: 0.18em;
}

.catalog-page-button:enabled:active {
  transform: translateY(1px);
}

.catalog-page-button:disabled {
  background: var(--pos-control);
  color: var(--pos-muted);
  opacity: 0.65;
}

.catalog-page-number {
  color: var(--pos-muted);
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
}
</style>
