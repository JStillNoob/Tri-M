<script setup>
import { computed, nextTick, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosPagination from '@/components/shared/PosPagination.vue'
import PosSearchInput from '@/components/shared/PosSearchInput.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import PosToast from '@/components/shared/PosToast.vue'
import ImportRowDialog from '@/components/stock/ImportRowDialog.vue'
import { request } from '@/services/posApi'
import { invalidateReads } from '@/services/posReadCache'
import { useSessionStore } from '@/stores/sessionStore'
import { confirmedWorkbookData, draftPreview, getDraft, listDrafts, removeDraft, saveDraft } from '@/services/browserStockImports'
import { extractStockRows, readSupplierWorkbook, worksheetSample } from '@/services/supplierWorkbook'
import { transactionDate } from '@/utils/sales'
import { formatStockMoney, markupPattern } from '@/utils/stockMoney'
import { PosError, userMessage } from '@/utils/userFeedback'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const fields = {
  date: 'Date',
  name: 'Product name (required)',
  quantity: 'Quantity (required)',
  category: 'Category',
  supplier: 'Supplier',
  unit_cost: 'Item cost',
  selling_price: 'Price',
}
const statuses = { uploaded: 'Choose columns', review: 'In review', approved: 'Approved' }
const rowStatuses = { existing: 'Restock', new: 'New product', pending: 'Needs review', skip: 'Skipped' }
const emptyCatalog = { products: [], categories: [] }
const file = ref(null)
const draft = shallowRef(null)
const drafts = ref([])
const catalog = shallowRef(emptyCatalog)
const catalogReady = ref(false)
const approvedBatch = shallowRef(null)
const history = shallowRef(null)
const sheet = shallowRef(null)
const header = ref(1)
const sheetIndex = ref(0)
const mapping = reactive({})
const showColumns = ref(false)
const page = ref(1)
const filter = ref('all')
const rowSearch = ref('')
const busy = ref(false)
const error = ref('')
const rowNotice = ref('')
const dialogError = ref('')
const rowDialog = ref(null)
const confirmDialog = ref(null)
const confirmation = ref('approve')
const heading = ref(null)
const selectedRows = ref(new Set())
const bulkPercentage = ref('')
const importWait = { timeout: 90000 }
let loadSequence = 0

const owner = computed(() => session.account?.id)
const view = computed(() => route.query.view === 'history' ? 'history' : 'workbook')
const importId = computed(() => (typeof route.query.import === 'string' ? route.query.import : ''))
const batch = computed(() => draft.value
  ? draftPreview(draft.value, catalogReady.value ? catalog.value : null, page.value, filter.value, rowSearch.value)
  : approvedBatch.value)
const summary = computed(() => batch.value?.summary ?? {})
const canEdit = computed(() => draft.value !== null)
const readyToConfirm = computed(() => catalogReady.value && !summary.value.pending_rows &&
  !summary.value.price_review_rows && summary.value.units > 0)
const selectableRows = computed(() => (batch.value?.rows ?? []).filter(canSelectForMarkup))
const pageSelectionCount = computed(() =>
  selectableRows.value.filter((row) => selectedRows.value.has(row.id)).length,
)
const columnsReady = computed(() => Boolean(mapping.name && mapping.quantity))
const multipleSheets = computed(
  () => (batch.value?.setup?.sheets ?? []).filter(({ visible }) => visible).length > 1,
)
const pagination = computed(() =>
  view.value === 'history' ? history.value?.meta : batch.value?.pagination,
)
const stats = computed(() => {
  const labels = {
    total_rows: 'Rows',
    pending_rows: 'Need review',
    ...(summary.value.price_review_rows ? { price_review_rows: 'Price decisions' } : {}),
    units: batch.value?.status === 'approved' ? 'Units added' : 'Units to add',
    skipped_rows: 'Skipped',
  }
  return Object.entries(labels).filter(([key]) => summary.value[key] !== undefined)
})

function setSheet(value) {
  sheet.value = value
  sheetIndex.value = value.index
  header.value = value.header
  for (const field of Object.keys(fields)) mapping[field] = value.mapping[field] ?? ''
  showColumns.value = !columnsReady.value || Boolean(draft.value?.setupError)
}

async function refreshCatalog(sequence = loadSequence) {
  const response = await request('get', '/api/imports/review-catalog', importWait)
  if (sequence === loadSequence) {
    catalog.value = response.data
    catalogReady.value = true
  }
}

async function confirmedDetails(id) {
  const { data } = await request('get', '/api/imports/' + id, {
    ...importWait,
    params: { page: page.value, filter: filter.value, search: rowSearch.value || undefined },
  })
  if (data.status !== 'approved') throw new PosError('This draft is unavailable in this browser. Open the original workbook to start again.')
  return data
}

async function load() {
  const sequence = ++loadSequence
  const id = importId.value
  const accountId = owner.value
  if (accountId == null) {
    busy.value = false
    return
  }
  error.value = ''
  busy.value = true
  try {
    if (!id || view.value === 'history') {
      const [local, confirmed] = await Promise.allSettled([
        listDrafts(accountId),
        view.value === 'history'
          ? request('get', '/api/imports', { ...importWait, params: { page: page.value, per_page: 20 } })
          : Promise.resolve(null),
      ])
      if (sequence !== loadSequence) return
      if (local.status === 'fulfilled') drafts.value = local.value
      if (confirmed.status === 'fulfilled') history.value = confirmed.value
      if (local.status === 'rejected') throw local.reason
      if (confirmed.status === 'rejected') throw confirmed.reason
      return
    }
    const saved = await getDraft(accountId, id)
    if (sequence !== loadSequence) return
    if (!saved || saved.possiblySubmitted) {
      try {
        const confirmed = await confirmedDetails(id)
        if (sequence !== loadSequence) return
        approvedBatch.value = confirmed
        draft.value = null
        if (saved) await removeDraft(accountId, id)
        return
      } catch (problem) {
        if (!saved) {
          if (problem.status === 404) throw new PosError('This draft is unavailable in this browser. Open the original workbook to start again.', { cause: problem })
          throw problem
        }
        if (approvedBatch.value) throw problem
        if (problem.status !== 404 && sequence === loadSequence) {
          error.value = 'We could not check whether this import went through. Check Import history before confirming again.'
        }
      }
    }
    if (sequence !== loadSequence) return
    draft.value = saved
    if (saved.status === 'uploaded') setSheet(saved.sample)
    else await refreshCatalog(sequence)
  } catch (problem) {
    if (sequence === loadSequence) error.value = userMessage(problem, 'We could not open this stock-in. Reload the page and try again.')
  } finally {
    if (sequence === loadSequence) busy.value = false
  }
}

watch([view, importId, owner], () => {
  draft.value = null
  drafts.value = []
  catalog.value = emptyCatalog
  catalogReady.value = false
  approvedBatch.value = null
  history.value = null
  sheet.value = null
  error.value = ''
  file.value = null
  rowNotice.value = ''
  showColumns.value = false
  page.value = 1
  filter.value = 'all'
  rowSearch.value = ''
  selectedRows.value = new Set()
  bulkPercentage.value = ''
  void load()
}, { immediate: true })

watch(rowSearch, (query, previous, onCleanup) => {
  page.value = 1
  if (query && !previous) filter.value = 'all'
  if (draft.value || !approvedBatch.value) return
  const timer = setTimeout(load, 300)
  onCleanup(() => clearTimeout(timer))
})

async function perform(action, problemTarget = error) {
  if (busy.value) return
  const sequence = loadSequence
  problemTarget.value = ''
  busy.value = true
  try {
    await action()
  } catch (problem) {
    if (sequence === loadSequence) problemTarget.value = userMessage(problem, 'We could not complete this stock-in action. Try again.')
  } finally {
    if (sequence === loadSequence) busy.value = false
  }
}

async function storeDraft(value) {
  const updated = await saveDraft(value)
  if (importId.value === updated.id && owner.value === updated.owner) draft.value = updated
}

function mapDraft(data, worksheet, sample, headerRow, columns) {
  return {
    ...data, sample, header: headerRow, mapping: columns, status: 'review',
    rows: extractStockRows(worksheet, headerRow, columns), reviews: {}, markups: {},
  }
}

async function upload() {
  const selectedFile = file.value
  const accountId = owner.value
  if (!selectedFile || accountId == null) return
  await perform(async () => {
    if (!globalThis.crypto?.randomUUID) {
      throw new PosError('Open the app on the store computer or use the secure address to read Excel workbooks.')
    }
    const workbook = await readSupplierWorkbook(selectedFile)
    const visible = workbook.sheets.filter(({ visible }) => visible)
    const worksheet = await workbook.worksheet(visible[0].index)
    const sample = worksheetSample(worksheet)
    let data = {
      id: globalThis.crypto.randomUUID(), owner: accountId, file: selectedFile, status: 'uploaded',
      sheets: workbook.sheets, sample,
    }
    if (visible.length === 1 && sample.mapping.name && sample.mapping.quantity && sample.columns.length) {
      try {
        data = mapDraft(data, worksheet, sample, sample.header, sample.mapping)
      } catch (problem) {
        data.setupError = userMessage(problem, 'Check the worksheet and column settings, then try again.')
      }
    }
    await storeDraft(data)
    if (owner.value === accountId && !importId.value && view.value === 'workbook') {
      await router.replace({ query: { import: data.id } })
    }
  })
}

async function selectSheet(index) {
  const current = draft.value
  await perform(async () => {
    const workbook = await readSupplierWorkbook(current.file)
    const sample = worksheetSample(await workbook.worksheet(index))
    await storeDraft({ ...current, sample, setupError: null })
    setSheet(sample)
  })
  if (sheet.value) sheetIndex.value = sheet.value.index
}

async function saveMapping() {
  const current = draft.value
  await perform(async () => {
    const workbook = await readSupplierWorkbook(current.file)
    const worksheet = await workbook.worksheet(sheetIndex.value)
    const columns = Object.fromEntries(Object.entries(mapping).filter(([, column]) => column))
    await storeDraft(mapDraft(current, worksheet, sheet.value, Number(header.value), columns))
    filter.value = 'all'
    page.value = 1
    showColumns.value = false
    await refreshCatalog()
    await nextTick()
    heading.value?.focus()
  })
}

async function saveRow(row, data) {
  await perform(async () => {
    const source = draft.value.rows.find(({ id }) => id === row)
    if (data.decision !== 'skip') {
      if (!source?.can_include) throw new PosError('Skip this row. It does not have a product to stock in.')
      if (data.item_cost == null && source.stock_in.original_item_cost == null) throw new PosError('Enter the item cost.')
      if ((data.decision === 'new' || data.price_decision === 'change') && !(Number(data.approved_selling_price) > 0)) {
        throw new PosError('Enter a selling price greater than zero.')
      }
    }
    const markups = { ...draft.value.markups }
    delete markups[row]
    await storeDraft({ ...draft.value, markups, reviews: { ...draft.value.reviews, [row]: data } })
    selectedRows.value.delete(row)
    page.value = batch.value.pagination.current_page
    rowDialog.value.close()
  }, dialogError)
}

async function setRowRemoved(row, removed) {
  await perform(async () => {
    const current = draft.value
    const removedRows = { ...current.removedRows }
    if (removed) removedRows[row.id] = true
    else delete removedRows[row.id]
    await storeDraft({ ...current, removedRows })
    if (draft.value?.id !== current.id) return
    selectedRows.value.delete(row.id)
    page.value = batch.value.pagination.current_page
    rowNotice.value = removed
      ? `Row ${row.row_number} removed. Find it under Skipped rows.`
      : `Row ${row.row_number} restored to stock-in.`
    await nextTick()
    heading.value?.focus({ preventScroll: true })
  })
}

function canSelectForMarkup(row) {
  return row.can_include && row.decision !== 'skip' && row.stock_in.item_cost != null &&
    row.markup_percentage == null
}

function togglePageRows() {
  const select = pageSelectionCount.value !== selectableRows.value.length
  for (const { id } of selectableRows.value) {
    if (select) selectedRows.value.add(id)
    else selectedRows.value.delete(id)
  }
}

async function applyMarkup() {
  if (!selectedRows.value.size) return
  await perform(async () => {
    const current = draft.value
    const markups = { ...current.markups }
    for (const id of selectedRows.value) {
      if (markups[id] != null || current.reviews[id]?.markup_percentage != null) {
        throw new PosError('Use Edit to change markup on a row that already has it.')
      }
      markups[id] = bulkPercentage.value
    }
    await storeDraft({ ...current, markups })
    selectedRows.value = new Set()
    bulkPercentage.value = ''
  })
}

function startRow(row) {
  dialogError.value = ''
  rowDialog.value.open(row)
}

function ask(action) {
  confirmation.value = action
  dialogError.value = ''
  confirmDialog.value.open()
}

async function confirm() {
  const current = draft.value
  if (!current) return
  await perform(async () => {
    if (confirmation.value === 'cancel') {
      await removeDraft(current.owner, current.id)
      if (importId.value !== current.id || owner.value !== current.owner) return
      confirmDialog.value.close()
      await router.replace({ query: {} })
      return
    }
    if (!readyToConfirm.value) return
    await storeDraft({ ...current, possiblySubmitted: true })
    let result
    try {
      result = (await request('post', '/api/imports/confirm-workbook', {
        // A full workbook is written in one transaction, including its purchase history.
        timeout: 0,
        data: confirmedWorkbookData(current, catalog.value),
      })).data
    } catch (problem) {
      if ([409, 422].includes(problem.status)) await refreshCatalog().catch(() => {})
      throw problem
    }
    invalidateReads([
      'products', 'low-stock',
      ...new Set(result.entries.map(({ product_id }) => 'product:' + product_id)),
      ...(result.new_products ? ['category-counts'] : []),
      ...(result.new_categories ? ['categories'] : []),
    ])
    if (importId.value !== current.id || owner.value !== current.owner) {
      await removeDraft(current.owner, current.id)
      return
    }
    approvedBatch.value = { ...batch.value, status: 'approved', summary: result }
    draft.value = null
    page.value = 1
    filter.value = 'all'
    confirmDialog.value.close()
    const refreshes = await Promise.allSettled([removeDraft(current.owner, current.id), confirmedDetails(current.id)])
    if (importId.value !== current.id || owner.value !== current.owner) return
    if (refreshes[1].status === 'fulfilled') approvedBatch.value = refreshes[1].value
    const incomplete = refreshes.find(({ status }) => status === 'rejected')
    if (incomplete) error.value = userMessage(incomplete.reason, 'Stock-in was saved. Open Import history to view the details.')
  }, dialogError)
}

async function changePage(number) {
  page.value = number
  if (!draft.value) await load()
  heading.value?.focus()
}

function formatOriginal(value) {
  if (value === null || value === '' || value === undefined) return '–'
  const [whole, fraction = ''] = String(value).split('.')
  return '₱' + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + fraction.padEnd(2, '0')
}

function rowPrice(row) {
  return formatOriginal(row.applied?.selling_price ?? row.approved_selling_price ?? row.product?.price)
}

function rowCost(row) {
  const cost = row.stock_in.item_cost
  return cost == null ? (row.stock_in.source_item_cost || '–') : formatStockMoney(cost)
}
</script>


<template>
  <div class="owner-page">
    <header class="owner-heading">
      <h1 class="screen-title">Excel stock-in</h1>
      <nav class="import-nav" aria-label="Stock import">
        <RouterLink :to="{ query: {} }" :aria-current="view === 'workbook' ? 'page' : undefined"
          >Workbook</RouterLink
        >
        <RouterLink
          :to="{ query: { view: 'history' } }"
          :aria-current="view === 'history' ? 'page' : undefined"
          >Import history</RouterLink
        >
        <RouterLink :to="{ name: 'owner-stock-purchases' }">Manual stock-in</RouterLink>
      </nav>
    </header>

    <p v-if="error" class="screen-error" role="alert">
      {{ error }}
    </p>
    <p v-if="busy && !batch && (view !== 'workbook' || importId)" class="screen-note" role="status">
      Loading…
    </p>
    <button
      v-if="error && !busy && view === 'workbook' && importId"
      class="dialog-action justify-self-start"
      type="button"
      @click="load"
    >
      Reload workbook
    </button>

    <section v-if="!importId && drafts.length" class="grid gap-3" aria-labelledby="saved-drafts-title">
      <h2 id="saved-drafts-title" class="section-title">Drafts saved in this browser</h2>
      <PosTableRegion aria-labelledby="saved-drafts-title">
        <table class="data-table history-table" aria-labelledby="saved-drafts-title">
          <thead>
            <tr>
              <th scope="col">Workbook</th>
              <th scope="col">Last saved</th>
              <th scope="col">Status</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in drafts" :key="item.id">
              <th scope="row" class="font-semibold">{{ item.name }}</th>
              <td><time :datetime="item.updated">{{ transactionDate(item.updated) }}</time></td>
              <td>{{ statuses[item.status] }}</td>
              <td><RouterLink class="record-link" :to="{ query: { import: item.id } }">Resume draft</RouterLink></td>
            </tr>
          </tbody>
        </table>
      </PosTableRegion>
    </section>

    <section
      v-if="view === 'history'"
      aria-labelledby="history-title"
      class="grid gap-3"
    >
      <div class="owner-heading">
        <h2 id="history-title" ref="heading" tabindex="-1" class="section-title">Import history</h2>
        <RouterLink class="dialog-action" :to="{ name: 'owner-stock-purchases' }">Previous purchases</RouterLink>
      </div>
      <p v-if="history && !history.data.length" class="screen-note" role="status">
        No confirmed imports yet.
      </p>
      <PosTableRegion v-else-if="history" aria-label="Workbook records">
        <table class="data-table history-table" aria-labelledby="history-title">
          <thead>
            <tr>
              <th scope="col">Workbook</th>
              <th scope="col">Date</th>
              <th scope="col">Status</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in history.data" :key="item.id">
              <th scope="row" class="font-semibold">{{ item.file_name }}</th>
              <td>
                <time :datetime="item.confirmed_at ?? item.updated_at">
                  {{ transactionDate(item.confirmed_at ?? item.updated_at) }}
                </time>
              </td>
              <td>
                {{ statuses[item.status] }}
                <span v-if="item.summary.units !== undefined" class="screen-note">
                  · {{ item.summary.units }} units added
                </span>
              </td>
              <td>
                <RouterLink class="record-link" :to="{ query: { import: item.id } }">
                  View details
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </PosTableRegion>
    </section>

    <form v-else-if="!importId" class="grid gap-3" @submit.prevent="upload">
      <label class="dialog-field max-w-xl">
        Supplier workbook (.xlsx)
        <input
          class="workbook-input"
          type="file"
          accept=".xlsx"
          :disabled="busy"
          @change="file = $event.target.files[0] ?? null"
        />
      </label>
      <button
        class="dialog-action dialog-action--primary justify-self-start"
        type="submit"
        :disabled="!file || busy"
      >
        {{ busy ? 'Reading workbook…' : 'Read workbook' }}
      </button>
      <p class="screen-note">Review and resume in this browser. The workbook is sent when you confirm stock-in.</p>
    </form>

    <template v-else-if="batch">
      <section class="grid gap-3" aria-labelledby="workbook-title">
        <div class="owner-heading">
          <div class="min-w-0">
            <h2 id="workbook-title" class="section-title [overflow-wrap:anywhere]">
              {{ batch.file_name }}
            </h2>
            <p class="screen-note">
              {{ statuses[batch.status] }}
              <template v-if="batch.worksheet_name"> · {{ batch.worksheet_name }}</template>
              <template v-if="batch.confirmed_at">
                · {{ transactionDate(batch.confirmed_at) }}</template
              >
            </p>
            <p v-if="!canEdit" class="screen-note [overflow-wrap:anywhere]">
              Reference {{ batch.id }}
            </p>
            <p v-else class="screen-note">Draft saved in this browser</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              v-if="canEdit"
              type="button"
              class="dialog-action"
              :disabled="busy"
              @click="ask('cancel')"
            >
              Cancel import
            </button>
          </div>
        </div>

        <form
          v-if="batch.status === 'uploaded' && sheet"
          class="grid gap-4"
          @submit.prevent="saveMapping"
        >
          <p v-if="draft?.setupError" class="screen-error" role="status">{{ draft.setupError }}</p>
          <div v-if="multipleSheets || showColumns" class="import-grid">
            <div v-if="multipleSheets" class="dialog-field">
              <label for="workbook-sheet">Worksheet</label>
              <select
                id="workbook-sheet"
                v-model="sheetIndex"
                :disabled="busy"
                @change="selectSheet(sheetIndex)"
              >
                <option
                  v-for="item in batch.setup.sheets"
                  :key="item.index"
                  :value="item.index"
                  :disabled="!item.visible"
                >
                  {{ item.name }}{{ item.visible ? '' : ' (hidden)' }}
                </option>
              </select>
            </div>
            <label v-if="showColumns" class="dialog-field">
              Header row
              <input v-model="header" type="number" min="1" max="1000" required :disabled="busy" />
            </label>
          </div>
          <details v-if="showColumns && sheet.sample.length">
            <summary class="font-semibold">Preview worksheet cells</summary>
            <div class="sample-cells" role="region" aria-label="Worksheet cells" tabindex="0">
              <table>
                <thead>
                  <tr>
                    <th scope="col">Row</th>
                    <th v-for="column in sheet.columns" :key="column" scope="col">{{ column }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in sheet.sample" :key="item.row">
                    <th scope="row">{{ item.row }}</th>
                    <td v-for="column in sheet.columns" :key="column">
                      {{ item.cells[column] ?? '–' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
          <fieldset v-if="showColumns" class="grid gap-2" :disabled="busy">
            <legend class="section-title">Column settings</legend>
            <p v-if="!columnsReady" class="screen-note">
              Select the columns for product names and quantities.
            </p>
            <div class="import-grid">
              <div v-for="(label, field) in fields" :key="field" class="dialog-field min-w-0">
                <label :for="'mapping-' + field">{{ label }}</label>
                <select
                  :id="'mapping-' + field"
                  v-model="mapping[field]"
                  :required="field === 'quantity' || field === 'name'"
                >
                  <option value="">Unmapped</option>
                  <option
                    v-for="column in sheet.columns"
                    :key="column"
                    :value="column"
                    :disabled="mapping[field] !== column && Object.values(mapping).includes(column)"
                  >
                    {{ column }} ·
                    {{
                      sheet.sample.find((item) => item.row === Number(header))?.cells[column] ||
                      'Column ' + column
                    }}
                  </option>
                </select>
              </div>
            </div>
          </fieldset>
          <div class="flex flex-wrap gap-2">
            <button
              class="dialog-action dialog-action--primary"
              type="submit"
              :disabled="busy || !columnsReady"
            >
              {{ busy ? 'Checking rows…' : 'Review stock-in rows' }}
            </button>
          </div>
        </form>
      </section>

      <section
        v-if="batch.status !== 'uploaded' && stats.length"
        class="import-summary grid gap-3"
        aria-labelledby="summary-title"
      >
        <div class="owner-heading">
          <h2 id="summary-title" class="section-title">
            {{ batch.status === 'approved' ? 'Import complete' : 'Import summary' }}
          </h2>
          <button
            v-if="batch.status === 'review'"
            type="button"
            class="dialog-action dialog-action--primary"
            :disabled="busy || !readyToConfirm"
            @click="ask('approve')"
          >
            Confirm stock-in
          </button>
        </div>
        <dl class="summary-grid">
          <div v-for="[key, label] in stats" :key="key">
            <dt>{{ label }}</dt>
            <dd>{{ summary[key] }}</dd>
          </div>
        </dl>
        <p v-if="batch.status === 'review' && summary.pending_rows" class="screen-note">
          <template v-if="summary.unmatched_without_price">
            {{ summary.unmatched_without_price }} unmatched
            {{ summary.unmatched_without_price === 1 ? 'row has' : 'rows have' }} a blank or zero
            selling price. Apply markup to price new products with complete details, or use Edit to review each row.
          </template>
          <template v-else>Resolve the remaining rows to approve this import.</template>
        </p>
        <p v-if="batch.status === 'review' && summary.price_review_rows" class="screen-error" role="status">
          Choose whether to keep the current price or approve a new price for each restock before confirming.
        </p>
      </section>

      <section
        v-if="batch.status !== 'uploaded' && summary.total_rows"
        class="grid gap-3"
        aria-labelledby="rows-title"
      >
        <div class="owner-heading">
          <h2 id="rows-title" ref="heading" tabindex="-1" class="section-title">Stock-in rows</h2>
          <div class="row-filters">
            <PosSearchInput
              id="stock-row-search"
              v-model.trim="rowSearch"
              class="row-search-field"
              label="Search stock-in rows"
              placeholder="Row number or product name"
              :maxlength="255"
            />
            <div class="dialog-field purchase-filter">
              <label for="purchase-filter">Show</label>
              <select id="purchase-filter" v-model="filter" :disabled="busy" @change="changePage(1)">
                <option value="all">All rows</option>
                <option value="pending">Needs review</option>
                <option value="warnings">Warnings and errors</option>
                <option value="skipped">Skipped rows</option>
              </select>
            </div>
          </div>
        </div>
        <form v-if="batch.status === 'review' && selectedRows.size" class="bulk-markup" @submit.prevent="applyMarkup">
          <span class="screen-note" role="status">{{ selectedRows.size }} selected</span>
          <label class="dialog-field">
            Bulk markup (%)
            <input v-model.trim="bulkPercentage" :pattern="markupPattern" inputmode="decimal" required :disabled="busy" />
          </label>
          <button class="dialog-action dialog-action--primary" type="submit" :disabled="busy">Apply markup</button>
          <button class="dialog-action" type="button" :disabled="busy" @click="selectedRows = new Set()">Clear selection</button>
        </form>
        <p v-if="rowSearch && batch.rows.length" class="screen-note" role="status">
          {{ batch.pagination.total }} {{ batch.pagination.total === 1 ? 'matching row' : 'matching rows' }}
        </p>
        <p v-if="!batch.rows.length" class="screen-note" role="status">
          {{ rowSearch ? 'No rows match this search.' : 'No rows match this filter.' }}
        </p>
        <PosTableRegion v-else aria-label="Stock-in rows table">
          <table class="data-table stock-table" aria-labelledby="rows-title">
            <thead>
              <tr>
                <th scope="col" class="row-number">Row</th>
                <th v-if="batch.status === 'review'" scope="col">
                  <label class="row-selection">
                    <input
                      type="checkbox"
                      :checked="selectableRows.length > 0 && pageSelectionCount === selectableRows.length"
                      :indeterminate="pageSelectionCount > 0 && pageSelectionCount < selectableRows.length"
                      :disabled="busy || !selectableRows.length"
                      @change="togglePageRows"
                    />
                    <span class="sr-only">Select all eligible rows on this page</span>
                  </label>
                </th>
                <th scope="col">Date</th>
                <th scope="col">Product name</th>
                <th scope="col" class="number-cell">Quantity</th>
                <th scope="col">Category</th>
                <th scope="col">Supplier</th>
                <th scope="col" class="number-cell">Item cost</th>
                <th scope="col" class="number-cell">Markup (%)</th>
                <th scope="col" class="number-cell">Total cost</th>
                <th scope="col" class="number-cell">Sell price</th>
                <th v-if="batch.status === 'review'" scope="col">Actions</th>
                <th scope="col">Row details</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in batch.rows" :key="row.id">
                <td class="row-number">{{ row.row_number }}</td>
                <td v-if="batch.status === 'review'">
                  <label
                    class="row-selection"
                    :title="row.markup_percentage == null ? undefined : 'Use Edit to change this markup.'"
                  >
                    <input
                      v-model="selectedRows"
                      type="checkbox"
                      :value="row.id"
                      :disabled="busy || !canSelectForMarkup(row)"
                    />
                    <span class="sr-only">Select row {{ row.row_number }}</span>
                  </label>
                </td>
                <td>{{ row.stock_in.date || row.stock_in.source_date || '–' }}</td>
                <th scope="row" class="product-cell">
                  <span class="font-semibold">{{ row.applied?.name || row.new_name || row.product?.name || row.uploaded_name || 'Unnamed product' }}</span>
                  <span class="screen-note ms-2">{{ rowStatuses[row.decision] }}</span>
                </th>
                <td class="number-cell">{{ row.quantity ?? '–' }}</td>
                <td>
                  {{ row.applied?.category || row.category_review.selected?.name || row.category_review.category_name || row.category_review.source || '–' }}
                </td>
                <td>{{ row.stock_in.supplier || '–' }}</td>
                <td class="number-cell" :title="row.stock_in.item_cost ?? undefined">
                  {{ rowCost(row) }}
                  <span v-if="batch.status === 'review' && row.can_include && row.decision !== 'skip' && row.stock_in.item_cost == null" class="screen-note ms-2">
                    Enter item cost
                  </span>
                </td>
                <td class="number-cell">{{ row.markup_percentage == null ? '–' : Number(row.markup_percentage) + '%' }}</td>
                <td class="number-cell">{{ formatStockMoney(row.purchase?.total_cost) }}</td>
                <td class="number-cell">
                  {{ rowPrice(row) }}
                  <span v-if="batch.status === 'review' && row.stock_in.source_price" class="screen-note ms-2">
                    Workbook {{ row.stock_in.price == null ? row.stock_in.source_price : formatOriginal(row.stock_in.price) }}
                  </span>
                  <span v-if="row.price_from_bulk_markup" class="screen-note ms-2">From bulk markup</span>
                  <span v-else-if="batch.status === 'review' && row.suggested_selling_price" class="screen-note ms-2">
                    Suggested {{ formatStockMoney(row.suggested_selling_price) }}
                  </span>
                </td>
                <td v-if="batch.status === 'review'">
                  <div class="inline-flex gap-2">
                    <button
                      v-if="row.removed"
                      type="button"
                      class="dialog-action"
                      :disabled="busy"
                      :aria-label="'Restore row ' + row.row_number"
                      @click="setRowRemoved(row, false)"
                    >Restore</button>
                    <template v-else>
                      <button type="button" class="dialog-action" :disabled="busy" :aria-label="'Edit row ' + row.row_number" @click="startRow(row)">Edit</button>
                      <button
                        v-if="row.decision !== 'skip'"
                        type="button"
                        class="dialog-action remove-row"
                        :disabled="busy"
                        :aria-label="'Remove row ' + row.row_number"
                        @click="setRowRemoved(row, true)"
                      >Remove</button>
                    </template>
                  </div>
                </td>
                <td class="screen-note">
                  <template v-if="row.price_review_required">
                    <span class="price-warning">Selling price decision required</span>{{ ' · ' }}
                  </template>
                  <span v-if="batch.status === 'review' && row.product && row.decision !== 'skip'">
                    In stock {{ row.product.stock }} · Incoming {{ row.quantity ?? '–' }}{{ row.expected_stock == null ? '' : ' · After import ' + row.expected_stock }}
                  </span>
                  <template v-if="batch.status === 'review' && row.product && row.decision !== 'skip' && (row.errors.length || row.warnings.length)">
                    {{ ' · ' }}
                  </template>
                  {{ [...row.errors, ...row.warnings].join(' · ') }}
                </td>
              </tr>
            </tbody>
          </table>
        </PosTableRegion>
      </section>
    </template>

    <PosPagination
      v-if="pagination?.last_page > 1"
      label="Workbook pages"
      :page="pagination.current_page"
      :last-page="pagination.last_page"
      :loading="busy"
      @update:page="changePage"
    />

    <PosToast :message="rowNotice" @dismiss="rowNotice = ''" />
    <ImportRowDialog
      ref="rowDialog"
      :busy="busy"
      :error="dialogError"
      :products="catalog.products"
      :categories="catalog.categories"
      @save="saveRow"
    />
    <PosDialog
      ref="confirmDialog"
      :title="confirmation === 'approve' ? 'Confirm stock-in' : 'Cancel import'"
    >
      <p v-if="confirmation === 'approve'">
        Add {{ summary.units }} units to stock from this workbook?
      </p>
      <p v-else>Discard this workbook draft and its edits?</p>
      <p v-if="dialogError" class="screen-error" role="alert">{{ dialogError }}</p>
      <button
        type="button"
        class="dialog-action dialog-action--primary"
        :disabled="busy"
        @click="confirm"
      >
        {{ busy ? 'Saving…' : confirmation === 'approve' ? 'Confirm stock-in' : 'Discard draft' }}
      </button>
    </PosDialog>
  </div>
</template>

<style scoped>
.owner-page :is(section.grid, form.grid, fieldset.grid) {
  grid-template-columns: minmax(0, 1fr);
  min-width: 0;
}

.row-filters {
  display: flex;
  flex: 0 1 29rem;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem;
  max-inline-size: 100%;
}

.row-search-field {
  flex: 2 1 14rem;
  min-inline-size: 0;
}

.purchase-filter {
  flex: 1 1 9rem;
  min-inline-size: 0;
}

.price-warning {
  color: #8b1f26;
  font-weight: 650;
}

.remove-row:enabled {
  color: #8b1f26;
}

.bulk-markup {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.5rem;
  padding-block: 0.625rem;
  border-block-start: 1px solid var(--pos-line);
}

.bulk-markup .screen-note {
  align-self: center;
}

.bulk-markup input {
  inline-size: 8.5rem;
}

.row-selection {
  display: grid;
  place-items: center;
  min-inline-size: 2.75rem;
  min-block-size: 2.75rem;
}

.row-selection input {
  inline-size: 1rem;
  block-size: 1rem;
  accent-color: var(--pos-green);
}

.import-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0 1rem;
}

.import-nav a {
  display: inline-flex;
  align-items: center;
  min-height: 2.75rem;
  color: var(--pos-muted);
  font-size: 0.875rem;
  font-weight: 600;
}

.import-nav a[aria-current],
.import-nav a:hover {
  color: var(--pos-green-deep);
  text-decoration: underline;
  text-underline-offset: 0.3em;
}

.import-grid,
.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 10rem), 1fr));
  gap: 0.625rem 1rem;
}

.import-summary {
  padding-block: 0.875rem;
  border-block: 1px solid var(--pos-line);
}

.summary-grid {
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 7rem), 1fr));
  font-size: 0.875rem;
  font-variant-numeric: tabular-nums;
}

.summary-grid dt {
  color: var(--pos-muted);
}

.summary-grid dd {
  overflow-wrap: anywhere;
  font-size: 1.25rem;
  font-weight: 700;
}

.stock-table {
  min-inline-size: 64rem;
}

.stock-table :is(th, td) {
  padding-inline: 0.625rem;
}

.stock-table .row-number {
  position: sticky;
  inset-inline-start: 0;
  z-index: 1;
  inline-size: 3.75rem;
  min-inline-size: 3.75rem;
  background: var(--pos-paper);
  box-shadow: 1px 0 var(--pos-line);
  text-align: center;
}

.stock-table thead .row-number {
  background: var(--pos-control);
}

.stock-table .product-cell {
  min-width: 11rem;
  font-weight: 400;
}

.history-table {
  min-inline-size: 33rem;
}

.dialog-field .workbook-input {
  display: block;
  overflow: hidden;
  padding: 0;
  text-overflow: ellipsis;
}

.workbook-input::file-selector-button {
  min-block-size: 2.75rem;
  margin-inline-end: 0.75rem;
  padding: 0.5rem 0.875rem;
  border-inline-end: 1px solid var(--pos-line);
  background: var(--pos-control);
  color: var(--pos-green-deep);
  font-weight: 650;
  cursor: pointer;
}

.workbook-input:enabled::file-selector-button:hover {
  background: var(--pos-mint);
}

.workbook-input:disabled::file-selector-button {
  color: var(--pos-muted);
  cursor: not-allowed;
}

.sample-cells {
  max-width: 100%;
  max-height: 20rem;
  contain: inline-size;
  overflow: auto;
  margin-block: 0.5rem;
  border: 1px solid var(--pos-line);
  font-size: 0.875rem;
}

.sample-cells :is(th, td) {
  min-width: 3rem;
  padding: 0.5rem;
  border: 1px solid var(--pos-line);
  text-align: start;
  white-space: nowrap;
}
</style>
