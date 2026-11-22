<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import BasketBar from '@/components/pos/BasketBar.vue'
import CategoryChips from '@/components/pos/CategoryChips.vue'
import POSHeader from '@/components/pos/POSHeader.vue'
import ProductList from '@/components/pos/ProductList.vue'
import ProductSearch from '@/components/pos/ProductSearch.vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { useCartStore } from '@/stores/cartStore'
import { productFromApi, useCatalogStore } from '@/stores/catalogStore'
import { useCachedRead } from '@/services/posReadCache'
import { useSessionStore } from '@/stores/sessionStore'
import { userMessage } from '@/utils/userFeedback'

const router = useRouter()
const cart = useCartStore()
const catalog = useCatalogStore()
const session = useSessionStore()
const categories = computed(() => ['All', ...catalog.categories])
const query = ref('')
const searchTerm = ref('')
const category = ref('All')
const sortOrder = ref('catalog')
const maximumPrice = ref('')
const inStockOnly = ref(false)
const draftSortOrder = ref('catalog')
const draftMaximumPrice = ref('')
const draftInStockOnly = ref(false)
const menuDialog = ref(null)
const filterDialog = ref(null)
const signOutError = ref('')
const catalogPage = ref(1)
const catalogPageSize = 24

const hasSearch = computed(() => searchTerm.value.length > 0)
const hasFilters = computed(
  () => inStockOnly.value || maximumPrice.value !== '' || sortOrder.value !== 'catalog',
)
const hasNarrowedResults = computed(
  () =>
    hasSearch.value || category.value !== 'All' || inStockOnly.value || maximumPrice.value !== '',
)

watch(query, (value, _, onCleanup) => {
  const timer = setTimeout(() => {
    searchTerm.value = value.trim()
  }, 250)
  onCleanup(() => clearTimeout(timer))
})
watch(
  () => catalog.records,
  (records) => {
    if (
      catalog.status === 'ready' &&
      category.value !== 'All' &&
      !records.some((record) => record.name === category.value)
    )
      category.value = 'All'
  },
)
watch(
  [searchTerm, category, sortOrder, maximumPrice, inStockOnly],
  () => {
    catalogPage.value = 1
  },
  { flush: 'sync' },
)
const products = useCachedRead(() => ({
  kind: 'products',
  path: '/api/products',
  params: {
    search: searchTerm.value,
    category_id: catalog.categoryId(category.value),
    availability: inStockOnly.value ? 'in_stock' : undefined,
    max_price: maximumPrice.value,
    sort: sortOrder.value === 'catalog' ? undefined : sortOrder.value,
    page: catalogPage.value,
    per_page: catalogPageSize,
  },
}))
const filteredProducts = computed(() => products.data.value?.data.map(productFromApi) ?? [])
const productStatus = computed(() =>
  products.data.value ? 'ready' : products.error.value ? 'error' : 'loading',
)
const productTotal = computed(() => products.data.value?.meta.total ?? 0)
const lastCatalogPage = computed(() => products.data.value?.meta.last_page ?? 1)
watch(products.data, (response) => {
  if (response && catalogPage.value > response.meta.last_page)
    catalogPage.value = response.meta.last_page
})

function retryProducts() {
  void products.refresh()
  if (catalog.status === 'error') void catalog.load()
}

function refreshFromMenu() {
  retryProducts()
  menuDialog.value.close()
}

function openFilters() {
  draftSortOrder.value = sortOrder.value
  draftMaximumPrice.value = maximumPrice.value
  draftInStockOnly.value = inStockOnly.value
  filterDialog.value.open()
}

function applyFilters() {
  sortOrder.value = draftSortOrder.value
  maximumPrice.value = draftMaximumPrice.value
  inStockOnly.value = draftInStockOnly.value
  filterDialog.value.close()
}

function clearFilters() {
  draftSortOrder.value = 'catalog'
  draftMaximumPrice.value = ''
  draftInStockOnly.value = false
  applyFilters()
}

function resetResults() {
  query.value = ''
  category.value = 'All'
  sortOrder.value = 'catalog'
  maximumPrice.value = ''
  inStockOnly.value = false
}

async function signOut() {
  signOutError.value = ''
  try {
    await session.signOut()
    menuDialog.value.close()
    await router.replace({ name: 'login' })
  } catch (problem) {
    signOutError.value = userMessage(problem, 'We could not sign you out. Try again.')
  }
}
</script>

<template>
  <POSLayout>
    <template #header>
      <POSHeader @menu="menuDialog.open()" @history="router.push({ name: 'orders' })" />
    </template>

    <div class="sell-content">
      <ProductSearch v-model="query" :has-filters="hasFilters" @filters="openFilters" />
      <CategoryChips v-model="category" :categories="categories" />
      <ProductList
        :products="filteredProducts"
        :quantities="cart.quantities"
        :status="productStatus"
        :error-message="userMessage(products.error.value, 'We could not load products. Try again.')"
        :offset="(catalogPage - 1) * catalogPageSize"
        :total="productTotal"
        :has-previous-batch="catalogPage > 1"
        :has-next-batch="catalogPage < lastCatalogPage"
        :has-search="hasSearch"
        :has-narrowed-results="hasNarrowedResults"
        @add="cart.add($event, products.updated.value)"
        @remove="cart.decrease"
        @batch="catalogPage += $event"
        @retry="retryProducts"
        @reset="resetResults"
      />
    </div>

    <template #footer>
      <BasketBar
        :quantity="cart.quantity"
        :total-centavos="cart.total"
        @checkout="router.push({ name: 'basket' })"
      />
    </template>
  </POSLayout>

  <PosDialog ref="menuDialog" title="Menu">
    <nav class="menu-links" aria-label="Sell screen">
      <a href="#product-search" @click="menuDialog.close()">Search products</a>
      <a href="#products" @click="menuDialog.close()">Browse products</a>
      <a href="#basket" @click="menuDialog.close()">Basket summary</a>
      <RouterLink :to="{ name: 'orders' }" @click="menuDialog.close()">Orders</RouterLink>
    </nav>
    <p class="screen-note">{{ session.account?.email }}</p>
    <p v-if="signOutError" class="screen-error" role="alert">{{ signOutError }}</p>
    <div class="screen-actions">
      <button class="dialog-action" type="button" @click="refreshFromMenu">Refresh products</button>
      <button class="dialog-action" type="button" @click="signOut">Sign out</button>
    </div>
  </PosDialog>

  <PosDialog ref="filterDialog" title="Product filters">
    <form class="grid gap-4" @submit.prevent="applyFilters">
      <label class="dialog-field" for="sort-order">
        Sort by
        <select id="sort-order" v-model="draftSortOrder">
          <option value="catalog">Catalog order</option>
          <option value="price-low">Price, low to high</option>
          <option value="price-high">Price, high to low</option>
        </select>
      </label>
      <label class="dialog-field" for="maximum-price">
        Maximum price (₱)
        <input
          id="maximum-price"
          v-model.number="draftMaximumPrice"
          type="number"
          min="0"
          step="0.01"
        />
      </label>
      <label class="availability-filter">
        <input v-model="draftInStockOnly" type="checkbox" />
        In-stock products only
      </label>
      <div class="flex flex-wrap justify-end gap-2">
        <button class="dialog-action" type="button" @click="clearFilters">Clear filters</button>
        <button class="dialog-action dialog-action--primary" type="submit">Apply filters</button>
      </div>
    </form>
  </PosDialog>
</template>

<style scoped>
.sell-content {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: clamp(0.875rem, 3.8vw, 1.5rem);
}

.availability-filter {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  min-height: 2.75rem;
  padding-inline: 0.75rem;
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
}

.availability-filter input {
  width: 1.125rem;
  height: 1.125rem;
  accent-color: var(--pos-green);
}

.menu-links {
  display: grid;
  gap: 0.25rem;
}

.menu-links a {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 2.75rem;
  padding: 0.5rem 0.75rem;
  border-radius: 0.625rem;
}

.menu-links a:hover {
  background: var(--pos-control);
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.menu-links a:active {
  transform: translateY(1px);
}
</style>
