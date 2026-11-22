<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Ellipsis, Eye, Pencil, Plus, RefreshCw, RotateCcw, Trash2 } from '@lucide/vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import PosSearchInput from '@/components/shared/PosSearchInput.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import PosToast from '@/components/shared/PosToast.vue'
import PosPagination from '@/components/shared/PosPagination.vue'
import { useCachedRead } from '@/services/posReadCache'
import { productFromApi, useCatalogStore } from '@/stores/catalogStore'
import { formatPrice, parsePrice } from '@/utils/price'
import { formatStockMoney, markupPattern, suggestedPrice } from '@/utils/stockMoney'
import { useFormFeedback, userMessage } from '@/utils/userFeedback'

const catalog = useCatalogStore()
const query = ref('')
const search = ref('')
const category = ref('')
const status = ref('active')
const page = ref(1)
const pageSize = ref(10)
const notice = ref('')
const feedback = useFormFeedback(['name', 'category_id', 'cost', 'price', 'markup_percentage', 'stock'])
const { alert: error } = feedback
const pending = ref(false)
const current = ref(null)
const productDialog = ref(null)
const detailDialog = ref(null)
const archiveDialog = ref(null)
const menu = ref(null)
const menuPanel = ref(null)
const menuStyle = ref({})
const fields = reactive({ name: '', category: '', cost: '', price: '', markup: '', stock: '0' })
const priceSuggestion = computed(() => suggestedPrice(fields.cost, fields.markup))
const quantities = new Intl.NumberFormat('en-PH')

watch(query, (value, _, onCleanup) => {
  const timer = setTimeout(() => {
    search.value = value.trim()
  }, 250)
  onCleanup(() => clearTimeout(timer))
})
watch(
  () => catalog.records,
  (records) => {
    if (
      catalog.status === 'ready' &&
      category.value &&
      !records.some((record) => record.name === category.value)
    ) {
      category.value = ''
    }
  },
)
watch(
  [search, category, status, pageSize],
  () => {
    page.value = 1
  },
  { flush: 'sync' },
)

const products = useCachedRead(() => ({
  kind: 'products',
  path: '/api/products',
  params: {
    search: search.value,
    category_id: catalog.categoryId(category.value),
    status: status.value,
    page: page.value,
    per_page: pageSize.value,
    include_supplier: 1,
  },
}))
const results = computed(() => products.data.value?.data.map(productFromApi) ?? [])
const meta = computed(() => products.data.value?.meta)
const loading = products.loading
const updating = computed(() => loading.value || products.refreshing.value)
const refresh = products.refresh
const loadingError = computed(() => products.error.value ? userMessage(products.error.value, 'We could not load products. Try refreshing the list.') : '')
watch(products.data, (response) => {
  if (response && page.value > response.meta.last_page) page.value = response.meta.last_page
})
watch([search, category, status, page, pageSize], () => closeActions())

function closeActions(restoreFocus = false) {
  const anchor = menu.value?.anchor
  menu.value = null
  if (restoreFocus) anchor?.focus({ preventScroll: true })
}

function dismissOnPointer(event) {
  if (
    menu.value &&
    !menu.value.anchor.contains(event.target) &&
    !menuPanel.value?.contains(event.target)
  ) {
    closeActions()
  }
}

function dismissOnScroll(event) {
  if (menu.value && !menuPanel.value?.contains(event.target)) closeActions()
}

function dismissOnResize() {
  closeActions()
}

async function openActions(event, product) {
  const anchor = event.currentTarget
  if (menu.value?.product.id === product.id) {
    closeActions(true)
    return
  }
  menuStyle.value = { visibility: 'hidden' }
  menu.value = { anchor, product }
  await nextTick()
  if (menu.value?.anchor !== anchor || !menuPanel.value) return

  const rect = anchor.getBoundingClientRect()
  const { offsetWidth: width, offsetHeight: height } = menuPanel.value
  const viewport = document.documentElement
  const left = Math.max(8, Math.min(rect.right - width, viewport.clientWidth - width - 8))
  const below = rect.bottom + height + 14 <= viewport.clientHeight
  const top = Math.max(8, below ? rect.bottom + 6 : rect.top - height - 6)
  menuStyle.value = { left: left + 'px', top: top + 'px' }
  await nextTick()
  if (menu.value?.anchor === anchor)
    menuPanel.value?.querySelector('button')?.focus({ preventScroll: true })
}

function onMenuFocusOut(event) {
  if (
    !menuPanel.value?.contains(event.relatedTarget) &&
    event.relatedTarget !== menu.value?.anchor
  ) {
    closeActions()
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', dismissOnPointer)
  document.addEventListener('scroll', dismissOnScroll, true)
  window.addEventListener('resize', dismissOnResize)
})
onUnmounted(() => {
  document.removeEventListener('pointerdown', dismissOnPointer)
  document.removeEventListener('scroll', dismissOnScroll, true)
  window.removeEventListener('resize', dismissOnResize)
})

function edit(product = null) {
  closeActions(true)
  notice.value = ''
  feedback.clear()
  current.value = product
  Object.assign(fields, {
    name: product?.name ?? '',
    category: product?.category ?? '',
    cost: product ? (product.costCentavos / 100).toFixed(2) : '',
    price: product ? (product.priceCentavos / 100).toFixed(2) : '',
    markup: product?.markup ?? (product ? '' : '0'),
    stock: product?.stock ?? '0',
  })
  productDialog.value.open()
}

function viewProduct(product) {
  closeActions(true)
  current.value = product
  detailDialog.value.open()
}

function askArchive(product) {
  closeActions(true)
  feedback.clear()
  notice.value = ''
  current.value = product
  archiveDialog.value.open()
}

async function save() {
  if (pending.value) return
  pending.value = true
  feedback.clear()
  try {
    const cost = current.value && parsePrice(fields.cost) === current.value.costCentavos ? undefined : fields.cost
    await catalog.saveProduct({ ...fields, cost }, current.value?.id)
    notice.value = current.value ? 'Product updated.' : 'Product added.'
    if (!current.value) {
      status.value = 'active'
      query.value = ''
      category.value = ''
      page.value = 1
    }
    productDialog.value.close()
  } catch (problem) {
    feedback.show(problem, 'We could not save this product. Check your entries and try again.')
  } finally {
    pending.value = false
  }
}

async function toggleArchive() {
  if (pending.value || !current.value) return
  pending.value = true
  error.value = ''
  const archived = !current.value.archived
  try {
    await catalog.setArchived(current.value.id, archived)
    notice.value = archived ? 'Product moved to Archived.' : 'Product restored.'
    archiveDialog.value.close()
  } catch (problem) {
    error.value = userMessage(problem, 'We could not update this product. Try again.')
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="owner-page products-page">
    <header class="products-heading">
      <div>
        <h1 class="screen-title">Products</h1>
        <p class="screen-note">Manage and monitor your inventory.</p>
      </div>
      <div class="screen-actions catalog-actions">
        <button
          class="dialog-action icon-action"
          type="button"
          :disabled="updating"
          @click="refresh"
        >
          <RefreshCw :size="17" aria-hidden="true" />
          {{ updating ? 'Refreshing…' : 'Refresh products' }}
        </button>
        <button
          class="dialog-action dialog-action--primary icon-action"
          type="button"
          @click="edit()"
        >
          <Plus :size="18" aria-hidden="true" />
          Add product
        </button>
      </div>
    </header>

    <form class="product-filters" @submit.prevent>
      <PosSearchInput id="owner-product-search" v-model="query" label="Search products" placeholder="Product name" />
      <div class="dialog-field">
        <label for="owner-product-category">Category</label>
        <select
          id="owner-product-category"
          v-model="category"
          :disabled="catalog.status !== 'ready'"
        >
          <option value="">All categories</option>
          <option v-for="name in catalog.categories" :key="name">{{ name }}</option>
        </select>
        <button
          v-if="catalog.status === 'error'"
          class="dialog-action justify-self-start"
          type="button"
          @click="catalog.load()"
        >
          Retry categories
        </button>
      </div>
      <div class="dialog-field">
        <label for="owner-product-status">Status</label>
        <select id="owner-product-status" v-model="status">
          <option value="active">Active</option>
          <option value="archived">Archived</option>
          <option value="all">All products</option>
        </select>
      </div>
    </form>

    <p v-if="loadingError" class="screen-error" role="alert">{{ loadingError }}</p>
    <section aria-label="Product catalog" :aria-busy="updating">
      <PosTableRegion class="catalog-scroll" aria-label="Products table">
        <table class="data-table catalog-table">
          <thead>
            <tr>
              <th scope="col">Item name</th>
              <th scope="col">Category</th>
              <th scope="col">Supplier</th>
              <th scope="col" class="numeric">QTY</th>
              <th scope="col" class="numeric">Unit cost</th>
              <th scope="col" class="numeric">Sell price</th>
              <th scope="col" class="action-cell">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="product in results" :key="product.id">
              <th scope="row" class="product-name">
                <span>{{ product.name }}</span>
                <span v-if="product.archived" class="product-status archived-status">Archived</span>
                <span v-else-if="product.stock === 0" class="product-status empty-status"
                  >Out of stock</span
                >
              </th>
              <td>{{ product.category || 'Uncategorized' }}</td>
              <td :class="{ 'muted-cell': !product.supplier }">
                {{ product.supplier || 'None recorded' }}
              </td>
              <td class="numeric">{{ quantities.format(product.stock) }}</td>
              <td class="numeric">{{ formatPrice(product.costCentavos) }}</td>
              <td class="numeric sell-price">{{ formatPrice(product.priceCentavos) }}</td>
              <td class="action-cell">
                <button
                  class="icon-button row-actions"
                  type="button"
                  :aria-label="'Actions for ' + product.name"
                  :aria-expanded="menu?.product.id === product.id"
                  :aria-controls="
                    menu?.product.id === product.id ? 'product-action-panel' : undefined
                  "
                  @click="openActions($event, product)"
                  @keydown.esc="closeActions(true)"
                >
                  <Ellipsis :size="22" aria-hidden="true" />
                </button>
              </td>
            </tr>
            <tr v-if="!results.length">
              <td colspan="7" class="empty-row">
                <p v-if="loading" role="status">Loading products…</p>
                <button
                  v-else-if="loadingError"
                  class="dialog-action"
                  type="button"
                  @click="refresh"
                >
                  Try again
                </button>
                <p v-else role="status">No products match these filters.</p>
              </td>
            </tr>
          </tbody>
        </table>
      </PosTableRegion>
      <PosPagination
        v-model:page="page"
        v-model:page-size="pageSize"
        :last-page="meta?.last_page ?? 1"
        :total="meta?.total ?? 0"
        :loading="loading"
      />
    </section>
    <PosToast :message="notice" @dismiss="notice = ''" />

    <Teleport to="body">
      <div
        v-if="menu"
        id="product-action-panel"
        ref="menuPanel"
        class="action-panel"
        role="group"
        :aria-label="'Actions for ' + menu.product.name"
        :style="menuStyle"
        @keydown.esc.stop.prevent="closeActions(true)"
        @focusout="onMenuFocusOut"
      >
        <button type="button" @click="viewProduct(menu.product)">
          <Eye :size="17" aria-hidden="true" /> View
        </button>
        <button v-if="!menu.product.archived" type="button" @click="edit(menu.product)">
          <Pencil :size="17" aria-hidden="true" /> Edit
        </button>
        <button v-if="menu.product.archived" type="button" @click="askArchive(menu.product)">
          <RotateCcw :size="17" aria-hidden="true" /> Restore
        </button>
        <button v-else type="button" class="delete-action" @click="askArchive(menu.product)">
          <Trash2 :size="17" aria-hidden="true" /> Delete
        </button>
      </div>
    </Teleport>

    <PosDialog ref="detailDialog" title="Product details">
      <template v-if="current">
        <p class="font-semibold [overflow-wrap:anywhere]">{{ current.name }}</p>
        <dl class="detail-fields">
          <div>
            <dt>Category</dt>
            <dd>{{ current.category || 'Uncategorized' }}</dd>
          </div>
          <div>
            <dt>Supplier</dt>
            <dd>{{ current.supplier || 'None recorded' }}</dd>
          </div>
          <div>
            <dt>Quantity</dt>
            <dd>{{ quantities.format(current.stock) }}</dd>
          </div>
          <div>
            <dt>Status</dt>
            <dd>{{ current.archived ? 'Archived' : 'Active' }}</dd>
          </div>
          <div>
            <dt>Average unit cost</dt>
            <dd>{{ formatPrice(current.costCentavos) }}</dd>
          </div>
          <div>
            <dt>Sell price</dt>
            <dd>{{ formatPrice(current.priceCentavos) }}</dd>
          </div>
          <div>
            <dt>Markup</dt>
            <dd>{{ current.markup == null ? 'Unassigned' : Number(current.markup) + '%' }}</dd>
          </div>
        </dl>
        <button
          class="dialog-action justify-self-start"
          type="button"
          @click="detailDialog.close()"
        >
          Done
        </button>
      </template>
    </PosDialog>

    <PosDialog ref="productDialog" :title="current ? 'Edit product' : 'Add product'">
      <form class="grid gap-3" @submit.prevent="save" @input="feedback.clear()">
        <fieldset class="grid min-w-0 gap-3" :disabled="pending">
          <div class="dialog-field">
            <label for="product-name">Product name</label>
            <input id="product-name" v-model.trim="fields.name" maxlength="255" required v-bind="feedback.bind('name')" />
            <PosFieldError :id="feedback.fieldId('name')" :message="feedback.inline('name')" />
          </div>
          <div class="dialog-field">
            <label for="product-category">Category</label>
            <select id="product-category" v-model="fields.category" v-bind="feedback.bind('category_id')">
              <option value="">Uncategorized</option>
              <option v-for="name in catalog.categories" :key="name">{{ name }}</option>
            </select>
            <PosFieldError :id="feedback.fieldId('category_id')" :message="feedback.inline('category_id')" />
          </div>
          <div class="product-money-fields">
            <div class="dialog-field">
              <label for="product-cost">Unit cost (₱)</label>
              <input id="product-cost" v-model.trim="fields.cost" inputmode="decimal" required v-bind="feedback.bind('cost')" />
              <PosFieldError :id="feedback.fieldId('cost')" :message="feedback.inline('cost')" />
            </div>
            <div class="dialog-field">
              <label for="product-price">Sell price (₱)</label>
              <input id="product-price" v-model.trim="fields.price" inputmode="decimal" required v-bind="feedback.bind('price')" />
              <PosFieldError :id="feedback.fieldId('price')" :message="feedback.inline('price')" />
            </div>
          </div>
          <p v-if="current && current.stock > 0 && parsePrice(fields.cost) !== current.costCentavos" class="screen-note">
            Saving a different unit cost adjusts the value of stock on hand.
          </p>
          <div class="dialog-field">
            <label for="product-markup">Markup (%)</label>
            <input id="product-markup" v-model.trim="fields.markup" inputmode="decimal" :pattern="markupPattern" v-bind="feedback.bind('markup_percentage')" />
            <PosFieldError :id="feedback.fieldId('markup_percentage')" :message="feedback.inline('markup_percentage')" />
          </div>
          <button
            v-if="priceSuggestion && priceSuggestion !== fields.price"
            class="dialog-action justify-self-start"
            type="button"
            @click="fields.price = priceSuggestion"
          >
            Use suggested price {{ formatStockMoney(priceSuggestion) }}
          </button>
          <div v-if="!current" class="dialog-field">
            <label for="product-stock">Initial stock</label>
            <input
              id="product-stock"
              v-model="fields.stock"
              type="number"
              min="0"
              step="1"
              v-bind="feedback.bind('stock')"
              required
            />
            <PosFieldError :id="feedback.fieldId('stock')" :message="feedback.inline('stock')" />
          </div>
          <p v-else class="screen-note">{{ current.stock }} units in stock</p>
        </fieldset>
        <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
        <div class="screen-actions">
          <button
            class="dialog-action"
            type="button"
            :disabled="pending"
            @click="productDialog.close()"
          >
            Cancel
          </button>
          <button class="dialog-action dialog-action--primary" type="submit" :disabled="pending">
            {{ pending ? 'Saving…' : current ? 'Save changes' : 'Save product' }}
          </button>
        </div>
      </form>
    </PosDialog>

    <PosDialog
      ref="archiveDialog"
      :title="current?.archived ? 'Restore product' : 'Delete product'"
    >
      <p v-if="current?.archived">
        Return <strong>{{ current.name }}</strong> to the active catalog?
      </p>
      <p v-else>
        Move <strong>{{ current?.name }}</strong> to Archived products? You can restore it later.
      </p>
      <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
      <div class="screen-actions">
        <button
          class="dialog-action"
          type="button"
          :disabled="pending"
          @click="archiveDialog.close()"
        >
          Cancel
        </button>
        <button
          class="dialog-action"
          :class="current?.archived ? 'dialog-action--primary' : 'archive-action'"
          type="button"
          :disabled="pending"
          @click="toggleArchive"
        >
          {{ pending ? 'Saving…' : current?.archived ? 'Restore product' : 'Delete product' }}
        </button>
      </div>
    </PosDialog>
  </div>
</template>

<style scoped>
.products-page {
  grid-template-columns: minmax(0, 1fr);
  gap: 1.35rem;
}

.products-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.products-heading h1 {
  font-size: clamp(1.8rem, 4vw, 2.55rem);
  letter-spacing: -0.038em;
}

.products-heading p {
  margin-top: 0.35rem;
}

.icon-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  font-size: 0.875rem;
}

.icon-action:not(.dialog-action--primary):enabled {
  background: var(--pos-paper);
}

.catalog-actions .icon-action:enabled:hover {
  border-color: var(--owner-green);
  text-decoration: none;
}

.product-filters,
.product-money-fields {
  display: grid;
  gap: 0.8rem 0.95rem;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr));
  align-items: start;
}

.product-filters .dialog-field {
  font-size: 0.875rem;
}

.product-filters :deep(input),
.product-filters select {
  min-height: 2.875rem;
  border-color: #a8b7ac;
}

.catalog-scroll {
  scrollbar-width: thin;
}

.catalog-table {
  min-width: 47rem;
  font-size: 0.925rem;
}

.catalog-table thead {
  font-size: 0.8125rem;
}

.catalog-table tbody :is(th, td):not(.empty-row) {
  padding-block: 0.3rem;
}

.catalog-table tbody tr:is(:hover, :focus-within) {
  background: #f0f7f2;
}

.catalog-table .product-name {
  min-width: 13rem;
  font-weight: 675;
}

.catalog-table :is(th, td):nth-child(2) {
  min-width: 8.25rem;
}

.catalog-table :is(th, td):nth-child(3) {
  min-width: 9rem;
}

.numeric {
  text-align: right;
  white-space: nowrap;
}

.sell-price {
  font-weight: 650;
}

.action-cell {
  text-align: center;
}

.muted-cell {
  color: var(--owner-muted);
  font-size: 0.875rem;
}

.product-status {
  display: inline-block;
  margin-inline-start: 0.5rem;
  padding: 0.15rem 0.45rem;
  border-radius: 0.35rem;
  font-size: 0.75rem;
  font-weight: 650;
}

.archived-status {
  color: #4c5b53;
  background: #eef2ef;
}

.empty-status {
  color: #87551a;
  background: #fcf0d9;
}

.row-actions {
  border-radius: 0.55rem;
  color: var(--owner-muted);
}

.row-actions:hover,
.row-actions[aria-expanded='true'] {
  color: var(--owner-deep);
  background: var(--owner-pale);
}

.catalog-table .empty-row {
  padding: 3.25rem 1rem;
  text-align: center;
  color: var(--owner-muted);
}

.action-panel {
  position: fixed;
  z-index: 20;
  display: grid;
  gap: 0.125rem;
  width: min(10rem, calc(100vw - 1rem));
  padding: 0.3rem;
  border: 1px solid #dce7df;
  border-radius: 0.65rem;
  background: white;
  box-shadow: 0 8px 24px #172b1d26;
  font-family: Inter, 'Segoe UI', system-ui, sans-serif;
}

.action-panel button {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  gap: 0.65rem;
  padding: 0.4rem 0.65rem;
  border-radius: 0.4rem;
  text-align: left;
  font-size: 0.875rem;
}

.action-panel button:hover,
.action-panel button:focus-visible {
  background: #e5f3e9;
}

.delete-action {
  color: #a52a35;
}

.archive-action:enabled {
  border-color: #a52a35;
  background: #a52a35;
  color: white;
}

.detail-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.6rem 1rem;
  overflow-wrap: anywhere;
}

.detail-fields dt {
  color: var(--pos-muted);
  font-size: 0.8125rem;
}

.detail-fields dd {
  font-weight: 600;
}

@media (min-width: 43rem) {
  .product-filters {
    grid-template-columns: minmax(0, 1.55fr) repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 32rem) {
  .catalog-actions {
    width: 100%;
  }
}
</style>
