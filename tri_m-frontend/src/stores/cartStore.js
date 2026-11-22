import { computed, onScopeDispose, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  invalidateReads,
  onReadInvalidation,
  productCacheMs,
  useCachedRead,
} from '@/services/posReadCache'
import { productFromApi } from '@/stores/catalogStore'
import { useSessionStore } from '@/stores/sessionStore'
import { readSessionStorage, writeSessionStorage } from '@/utils/sessionStorage'

export const useCartStore = defineStore('cart', () => {
  const session = useSessionStore()
  const quantities = ref(new Map())
  const products = ref(new Map())
  const unavailable = ref(new Set())
  const storageKey = (id) => 'tri-m-pos-cart-' + id
  const lines = computed(() =>
    [...quantities.value]
      .filter(([id]) => products.value.has(id))
      .map(([id, quantity]) => ({ product: products.value.get(id).product, quantity })),
  )
  const quantity = computed(() => lines.value.reduce((sum, line) => sum + line.quantity, 0))
  const total = computed(() =>
    lines.value.reduce((sum, line) => sum + line.product.priceCentavos * line.quantity, 0),
  )

  watch(
    () => session.account?.id,
    (id, previousId) => {
      if (previousId && !session.sessionExpired) writeSessionStorage(storageKey(previousId), [])
      quantities.value = new Map(id ? readSessionStorage(storageKey(id), []) : [])
      products.value.clear()
      unavailable.value.clear()
    },
    { immediate: true, flush: 'sync' },
  )

  const missing = computed(() =>
    [...quantities.value.keys()]
      .filter((id) => !products.value.has(id) && !unavailable.value.has(id))
      .sort((left, right) => left - right),
  )
  const selected = useCachedRead(() =>
    missing.value.length
      ? {
          kind: 'productDetails',
          path: '/api/products',
          params: { ids: missing.value.slice(0, 100), per_page: 100 },
          tags: missing.value.slice(0, 100).map((id) => 'product:' + id),
        }
      : null,
  )
  const status = computed(() =>
    selected.error.value ? 'error' : missing.value.length ? 'loading' : 'ready',
  )

  watch(
    selected.data,
    (response) => {
      if (!response) return
      const found = new Set()
      for (const item of response.data) {
        if (!quantities.value.has(item.id)) continue
        found.add(item.id)
        products.value.set(item.id, {
          product: productFromApi(item),
          observed: selected.updated.value,
        })
        unavailable.value.delete(item.id)
      }
      for (const id of selected.params.value?.ids ?? []) {
        if (!found.has(id) && quantities.value.has(id)) unavailable.value.add(id)
      }
    },
    { flush: 'sync' },
  )

  onScopeDispose(
    onReadInvalidation((tags) => {
      for (const id of quantities.value.keys()) {
        if (tags.includes('product-details') || tags.includes('product:' + id)) {
          products.value.delete(id)
          unavailable.value.delete(id)
        }
      }
    }),
  )

  function revalidate() {
    for (const [id, record] of products.value) {
      if (Date.now() - record.observed >= productCacheMs) products.value.delete(id)
    }
  }

  function refresh() {
    if (!missing.value.length && quantities.value.size) {
      invalidateReads(
        [...quantities.value.keys()].map((id) => 'product:' + id),
        false,
      )
    } else {
      void selected.refresh()
    }
  }

  function save() {
    if (session.account) writeSessionStorage(storageKey(session.account.id), [...quantities.value])
  }

  function add(product, observed = products.value.get(product.id)?.observed ?? Date.now()) {
    const count = quantities.value.get(product.id) ?? 0
    if (count >= product.stock || (!count && quantities.value.size >= 100)) return
    products.value.set(product.id, { product, observed })
    unavailable.value.delete(product.id)
    quantities.value.set(product.id, count + 1)
    save()
  }

  function remove(product) {
    quantities.value.delete(product.id)
    products.value.delete(product.id)
    unavailable.value.delete(product.id)
    save()
  }

  function decrease(product) {
    const count = quantities.value.get(product.id)
    if (!count) return
    if (count === 1) {
      remove(product)
      return
    }

    quantities.value.set(product.id, count - 1)
    save()
  }

  function clear() {
    quantities.value.clear()
    products.value.clear()
    unavailable.value.clear()
    save()
  }

  function removeUnavailable() {
    for (const id of unavailable.value) quantities.value.delete(id)
    unavailable.value.clear()
    save()
  }

  return {
    quantities,
    lines,
    quantity,
    total,
    status,
    loadError: computed(() => selected.error.value),
    unavailable,
    add,
    decrease,
    remove,
    clear,
    revalidate,
    refresh,
    removeUnavailable,
  }
})
