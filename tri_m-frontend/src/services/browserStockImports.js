import { identity } from './supplierWorkbook'
import { purchaseTotal, suggestedPrice } from '@/utils/stockMoney'
import { PosError } from '@/utils/userFeedback'

let connection

async function draftsDatabase() {
  connection ??= new Promise((resolve, reject) => {
    const request = indexedDB.open('tri-m-stock-in', 1)
    request.onupgradeneeded = () => {
      request.result.createObjectStore('workbooks', { keyPath: ['owner', 'id'] }).createIndex('owner', 'owner')
    }
    request.onsuccess = () => {
      request.result.onversionchange = () => {
        request.result.close()
        connection = null
      }
      resolve(request.result)
    }
    request.onerror = request.onblocked = reject
  })
  try {
    return await connection
  } catch {
    connection = null
    throw new PosError('We could not open saved workbooks. Allow this site to save data in your browser and try again.')
  }
}

async function accessDrafts(mode, action) {
  const database = await draftsDatabase()
  return new Promise((resolve, reject) => {
    const transaction = database.transaction('workbooks', mode)
    const request = action(transaction.objectStore('workbooks'))
    transaction.oncomplete = () => resolve(request.result)
    transaction.onabort = () => reject(new PosError('We could not save or open your draft. Free up some space on this device and try again.'))
  })
}

export const getDraft = (owner, id) => accessDrafts('readonly', (store) => store.get([owner, id]))
export const removeDraft = (owner, id) => accessDrafts('readwrite', (store) => store.delete([owner, id]))

export async function saveDraft(draft) {
  const database = await draftsDatabase()
  const revision = draft.revision ?? 0
  const updated = { ...draft, updated: new Date().toISOString(), revision: revision + 1 }
  return new Promise((resolve, reject) => {
    const transaction = database.transaction('workbooks', 'readwrite')
    const store = transaction.objectStore('workbooks')
    const read = store.get([draft.owner, draft.id])
    let error = 'This browser could not save the draft. Check available site storage.'
    read.onsuccess = () => {
      if ((read.result?.revision ?? 0) !== revision || (revision > 0 && !read.result)) {
        error = 'This draft changed in another tab. Reload the workbook before editing or confirming.'
        transaction.abort()
      } else {
        store.put(updated)
      }
    }
    transaction.oncomplete = () => resolve(updated)
    transaction.onabort = () => reject(new PosError(error))
  })
}

export async function listDrafts(owner) {
  const drafts = await accessDrafts('readonly', (store) => store.index('owner').getAll(owner))
  return drafts.map(({ id, file, status, updated }) => ({ id, name: file.name, status, updated }))
    .sort((first, second) => second.updated.localeCompare(first.updated))
}

function grouped(items, key) {
  const groups = new Map()
  for (const item of items) {
    const value = key(item)
    if (!groups.has(value)) groups.set(value, [])
    groups.get(value).push(item)
  }
  return groups
}

function blankOrZeroPrice(value) {
  const price = String(value ?? '').trim()
  return price === '' || /^(?:(?:PHP|₱)\s*)?\+?0+(?:\.0+)?(?:e[+-]?\d{1,2})?$/iu.test(price)
}

function rowErrors(source) {
  return source.errors.filter((error) =>
    error !== 'Check the selling price in this row.' || !blankOrZeroPrice(source.stock_in.source_price),
  )
}

function skippedRows(draft) {
  return new Set(draft.rows
    .filter(({ id }) => draft.removedRows?.[id] || draft.reviews[id]?.decision === 'skip')
    .map(({ row_number }) => row_number))
}

function rowWarnings(source, skipped) {
  const warnings = []
  let duplicates = source.duplicateRows
  for (const message of source.warnings) {
    const saved = /^This row repeats the values on row\(s\) ([\d, ]+)\. Check before including it\.$/.exec(message)
    if (saved) duplicates ??= saved[1].split(',').map(Number)
    else warnings.push(message)
  }
  const active = (duplicates ?? []).filter((number) => skipped.has(source.row_number) || !skipped.has(number))
  if (active.length) {
    warnings.push('This row repeats the values on row(s) ' + active.join(', ') + '. Check before including it.')
  }
  return warnings
}

function bulkReviewedNewRows(draft, catalog, categories, skipped) {
  if (!catalog || !Object.keys(draft.markups).length) return {}

  const existingNames = new Set(catalog.products.map(({ name }) => identity(name)))
  const activeRows = draft.rows.filter(({ row_number }) => !skipped.has(row_number))
  const workbookNames = grouped(activeRows, ({ uploaded_name }) => identity(uploaded_name))
  const reviews = {}
  for (const source of activeRows) {
    const markup = draft.markups[source.id]
    const name = identity(source.uploaded_name)
    if (markup == null || draft.reviews[source.id] || !source.can_include || !source.quantity ||
      !name || existingNames.has(name) || workbookNames.get(name).length !== 1 ||
      rowErrors(source).length || rowWarnings(source, skipped).length ||
      !blankOrZeroPrice(source.stock_in.source_price)) continue

    const categoryName = source.sourceCategory.trim().replace(/\s+/g, ' ')
    const categoryKey = identity(categoryName)
    const matches = categories.get(categoryKey) ?? []
    const price = suggestedPrice(source.stock_in.original_item_cost, markup)
    if (!price || !categoryKey || categoryKey === 'all' || [...categoryName].length > 100 || matches.length > 1) continue

    reviews[source.id] = {
      decision: 'new',
      new_name: source.uploaded_name,
      category_id: matches[0]?.id ?? null,
      use_workbook_category: matches.length === 0,
      approved_selling_price: price,
    }
  }
  return reviews
}

export function draftPreview(draft, catalog, page, filter, search = '') {
  const base = { id: draft.id, file_name: draft.file.name, status: draft.status, setup: { sheets: draft.sheets, sheet: draft.sample } }
  if (draft.status === 'uploaded') return { ...base, rows: [] }

  const products = new Map((catalog?.products ?? []).map((product) => [product.id, product]))
  const categoryById = new Map((catalog?.categories ?? []).map((category) => [category.id, category]))
  const categories = grouped(catalog?.categories ?? [], ({ name }) => identity(name))
  const names = grouped(catalog?.products ?? [], ({ name }) => identity(name))
  const skipped = skippedRows(draft)
  const bulkReviews = bulkReviewedNewRows(draft, catalog, categories, skipped)
  const rows = draft.rows.map((source) => {
    const manualReview = draft.reviews[source.id]
    const review = manualReview ?? bulkReviews[source.id]
    const name = identity(source.uploaded_name)
    const candidates = name ? (names.get(name) ?? []) : []
    const exact = candidates.length === 1 && !candidates[0].archived ? candidates[0] : null
    const product = review ? products.get(review.product_id) ?? null : exact
    const removed = Boolean(draft.removedRows?.[source.id])
    const warnings = rowWarnings(source, skipped)
    let errors = rowErrors(source)
    const decision = removed ? 'skip' : review?.decision ?? (product ? 'existing' : 'pending')
    const priceChanged = product && source.stock_in.price !== null && Number(source.stock_in.price) !== Number(product.price)
    if (!review && candidates.length > 1) warnings.push('Multiple catalog products match. Select the correct product.')
    if (!review && candidates.length === 1 && candidates[0].archived) warnings.push('The matching product is archived. Restore it before importing stock.')
    if (!review && priceChanged) warnings.push('The workbook price differs from the catalog price. Review the price.')
    if (review && decision !== 'skip') errors = []
    if (decision === 'new' && names.has(identity(review.new_name))) errors.push('A catalog product already uses this name. Select the existing product.')
    if (review?.category_id && !categoryById.has(review.category_id)) errors.push('The selected category is no longer available.')

    const priceReview = decision === 'existing' && (
      !review || !product || product.archived || Number(product.price) !== Number(review.reviewed_catalog_price)
    )
    if (priceReview && review) warnings.push('The catalog changed since this row was reviewed. Review the product and price again.')
    const priceDecision = priceReview ? null : review?.price_decision
    const categoryText = (review?.category_name ?? source.sourceCategory).trim().replace(/\s+/g, ' ')
    const categoryName = categoryText && [...categoryText].length <= 100 && identity(categoryText) !== 'all' ? categoryText : null
    const matchingCategories = categories.get(identity(categoryName)) ?? []
    const selected = categoryById.get(review?.category_id ?? product?.category_id) ?? null
    if (decision === 'existing' && product && source.sourceCategory && !review?.category_id &&
      identity(source.sourceCategory) !== identity(selected?.name)) {
      warnings.push('The workbook category differs from the catalog. Restock keeps the current category (' + (selected?.name ?? 'Uncategorized') + ').')
    }
    const markup = draft.markups[source.id] ?? review?.markup_percentage ?? null
    const cost = review?.item_cost ?? source.stock_in.original_item_cost
    const quantity = review?.quantity ?? source.quantity
    return {
      ...source,
      decision, removed, quantity, errors, warnings, product, candidates: candidates.slice(0, 20),
      new_name: review?.new_name, category_id: review?.category_id,
      price_decision: priceDecision, price_review_required: priceReview,
      approved_selling_price: priceReview ? null : review?.approved_selling_price,
      price_from_bulk_markup: Boolean(bulkReviews[source.id]),
      markup_percentage: markup,
      suggested_selling_price: suggestedPrice(cost, markup),
      purchase: { total_cost: purchaseTotal(cost, quantity) },
      category_review: {
        source: source.sourceCategory, category_name: categoryName, selected,
        suggestion: matchingCategories.length === 1 ? matchingCategories[0] : null,
        create_name: categoryName && !matchingCategories.length ? categoryName : null,
        use_source: review?.use_workbook_category,
      },
      stock_in: {
        ...source.stock_in,
        ...(manualReview && manualReview.decision !== 'skip' ? { date: manualReview.date, source_date: null, supplier: manualReview.supplier } : {}),
        item_cost: cost,
      },
    }
  })

  const included = rows.filter((row) => ['existing', 'new'].includes(row.decision))
  const groups = grouped(included, (row) => row.decision === 'existing'
    ? 'existing:' + (draft.reviews[row.id]?.product_id ?? row.product?.id)
    : 'new:' + identity(row.new_name))
  for (const group of groups.values()) {
    const expected = (group[0].product?.stock ?? 0) + group.reduce((sum, row) => sum + (row.quantity ?? 0), 0)
    const prices = new Set(group.filter((row) => !row.price_review_required)
      .map((row) => Number(row.approved_selling_price ?? row.product?.price)))
    const newCategories = new Set(group.filter((row) => row.decision === 'new').map((row) =>
      identity(row.category_review.selected?.name ?? row.category_review.category_name),
    ))
    for (const row of group) {
      row.expected_stock = expected
      if (prices.size > 1) row.errors.push('Rows for the same product must agree on the final selling price.')
      if (newCategories.size > 1) row.errors.push('Rows for the same new product must use the same category.')
      if (expected > 2147483647) row.errors.push('The resulting stock exceeds the supported quantity.')
    }
  }

  const pending = rows.filter((row) => row.decision === 'pending' || (row.decision !== 'skip' && row.errors.length))
  const query = identity(search)
  const rowNumber = /^(?:row\s*#?\s*|#\s*)?([0-9]+)$/.exec(query)
  const filtered = rows.filter((row) => {
    if (rowNumber ? row.row_number !== Number(rowNumber[1]) : query &&
      ![row.new_name, row.product?.name, row.uploaded_name].some((name) => identity(name).includes(query))) return false
    if (filter === 'skipped') return row.decision === 'skip'
    if (row.removed) return false
    if (filter === 'pending') return pending.includes(row) || row.price_review_required
    if (filter === 'warnings') return row.warnings.length || row.errors.length || row.price_review_required
    return true
  })
  const lastPage = Math.max(1, Math.ceil(filtered.length / 20))
  const currentPage = Math.min(page, lastPage)
  return {
    ...base,
    worksheet_name: draft.sample.name,
    summary: {
      total_rows: rows.length,
      pending_rows: pending.length,
      price_review_rows: rows.filter((row) => row.price_review_required).length,
      units: included.filter((row) => !row.price_review_required && !row.errors.length).reduce((sum, row) => sum + row.quantity, 0),
      skipped_rows: rows.filter((row) => row.decision === 'skip').length,
      unmatched_without_price: pending.filter((row) =>
        !row.errors.length && !row.candidates.length &&
        row.stock_in.price === null && row.markup_percentage == null,
      ).length,
    },
    rows: filtered.slice((currentPage - 1) * 20, currentPage * 20),
    pagination: { current_page: currentPage, last_page: lastPage, total: filtered.length },
  }
}

export function confirmedWorkbookData(draft, catalog) {
  const categories = grouped(catalog.categories, ({ name }) => identity(name))
  const bulkReviews = bulkReviewedNewRows(draft, catalog, categories, skippedRows(draft))
  const form = new FormData()
  form.append('file', draft.file)
  form.append('review', JSON.stringify({
    draft_id: draft.id,
    sheet: draft.sample.index,
    header: draft.header,
    mapping: draft.mapping,
    rows: draft.rows.map(({ id }) => draft.removedRows?.[id] ? { row_number: id, decision: 'skip' } : ({
      row_number: id,
      ...(draft.reviews[id] ?? bulkReviews[id]),
      ...(draft.markups[id] != null && draft.reviews[id]?.decision !== 'skip'
        ? { markup_percentage: draft.markups[id] } : {}),
    })),
  }))
  return form
}
