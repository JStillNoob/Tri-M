import { computed, shallowReactive, shallowRef, watch } from 'vue'
import { request } from '@/services/posApi'
import { readSessionStorage, writeSessionStorage } from '@/utils/sessionStorage'

const ttl = (value, seconds) =>
  value === undefined || !Number.isFinite(Number(value))
    ? seconds * 1000
    : Math.max(0, Number(value) * 1000)

const policies = {
  categories: {
    ttl: ttl(import.meta.env.VITE_POS_CATEGORY_CACHE_SECONDS, 600),
    persist: true,
    tags: ['categories'],
  },
  categoryCounts: {
    ttl: ttl(import.meta.env.VITE_POS_CATEGORY_CACHE_SECONDS, 600),
    tags: ['category-counts'],
  },
  products: { ttl: ttl(import.meta.env.VITE_POS_PRODUCT_CACHE_SECONDS, 60), tags: ['products'] },
  productDetails: {
    ttl: ttl(import.meta.env.VITE_POS_PRODUCT_CACHE_SECONDS, 60),
    tags: ['product-details'],
  },
  overview: {
    ttl: ttl(import.meta.env.VITE_POS_DASHBOARD_CACHE_SECONDS, 30),
    tags: ['sales-overview'],
    serverRefresh: true,
  },
  lowStock: { ttl: ttl(import.meta.env.VITE_POS_LOW_STOCK_CACHE_SECONDS, 30), tags: ['low-stock'] },
  stockFinancials: { ttl: 30_000, tags: ['products', 'sales-overview'] },
  recentOrders: {
    ttl: ttl(import.meta.env.VITE_POS_ORDERS_CACHE_SECONDS, 20),
    tags: ['recent-orders'],
  },
  orders: { ttl: ttl(import.meta.env.VITE_POS_ORDERS_CACHE_SECONDS, 20), tags: ['orders'] },
  receipt: { ttl: ttl(import.meta.env.VITE_POS_RECEIPT_CACHE_SECONDS, 60), tags: ['receipts'] },
  target: {
    ttl: ttl(import.meta.env.VITE_POS_DASHBOARD_CACHE_SECONDS, 30),
    tags: ['sales-targets'],
  },
}
export const productCacheMs = policies.productDetails.ttl

const entries = new Map()
const invalidationListeners = new Set()
const accountScope = shallowRef(null)
const persistedKey = 'tri-m-category-cache-v1'
const backend = import.meta.env.VITE_BACKEND_URL || window.location.origin
const channel =
  typeof BroadcastChannel === 'function' ? new BroadcastChannel('tri-m-pos-read-cache') : null
let onOtherSessionChange

channel?.addEventListener('message', ({ data }) => {
  if (data?.backend === backend && data.sessionChanged) onOtherSessionChange?.()
  if (data?.backend === backend && Array.isArray(data.tags)) invalidateReads(data.tags, false)
})

export function announceSessionChange() {
  channel?.postMessage({ backend, sessionChanged: true })
}

export function handleOtherSessionChange(handler) {
  onOtherSessionChange = handler
}

export function setReadCacheAccount(account) {
  const scope = account ? JSON.stringify([backend, account.id, account.role]) : null
  if (scope !== accountScope.value) {
    for (const entry of entries.values()) {
      cancel(entry)
      entry.data = null
    }
    entries.clear()
    accountScope.value = scope
  }
  const saved = readSessionStorage(persistedKey, null)
  if (!scope || (saved && saved.scope !== scope)) writeSessionStorage(persistedKey, null)
}

function cancel(entry) {
  entry.controller?.abort()
  entry.controller = null
  entry.pending = null
}

function getEntry(spec) {
  const { kind, path, tags = [] } = spec
  const params = Object.fromEntries(
    Object.entries(spec.params ?? {})
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .sort(([left], [right]) => left.localeCompare(right)),
  )
  const key = JSON.stringify([accountScope.value, kind, path, params])
  const existing = entries.get(key)
  if (existing) {
    entries.delete(key)
    entries.set(key, existing)
    return existing
  }

  const policy = policies[kind]
  const saved = policy.persist ? readSessionStorage(persistedKey, null) : null
  const restored = saved?.key === key && saved.scope === accountScope.value ? saved : null
  const entry = shallowReactive({
    key,
    path,
    params,
    policy,
    tags: [...policy.tags, ...tags],
    users: 0,
    data: restored?.data ?? null,
    updated: restored?.updated ?? 0,
    error: null,
    pending: null,
    controller: null,
  })
  entries.set(key, entry)
  for (const [oldKey, oldEntry] of entries) {
    if (entries.size <= 120) break
    if (oldEntry !== entry && !oldEntry.users && !oldEntry.pending) entries.delete(oldKey)
  }
  return entry
}

function load(entry, force = false) {
  if (entry.pending) return entry.pending
  if (!force && entry.data && Date.now() - entry.updated < entry.policy.ttl)
    return Promise.resolve(entry.data)

  const controller = new AbortController()
  entry.controller = controller
  entry.error = null
  const params =
    force && entry.policy.serverRefresh ? { ...entry.params, refresh: 1 } : entry.params
  entry.pending = request('get', entry.path, { params, signal: controller.signal })
    .then((data) => {
      if (entry.controller !== controller) return null
      entry.updated = Date.now()
      entry.data = data
      if (entry.policy.persist) {
        writeSessionStorage(persistedKey, {
          scope: accountScope.value,
          key: entry.key,
          updated: entry.updated,
          data,
        })
      }
      return data
    })
    .catch((error) => {
      if (entry.controller === controller && !controller.signal.aborted) entry.error = error
      return null
    })
    .finally(() => {
      if (entry.controller === controller) {
        entry.controller = null
        entry.pending = null
      }
    })
  return entry.pending
}

export function useCachedRead(source) {
  const subscription = shallowRef(null)

  watch(
    () => accountScope.value && source(),
    (spec, _, onCleanup) => {
      subscription.value = null
      if (!spec) return
      const entry = getEntry(spec)
      entry.users++
      subscription.value = entry
      void load(entry)
      onCleanup(() => {
        entry.users--
        queueMicrotask(() => {
          if (!entry.users) cancel(entry)
        })
      })
    },
    { immediate: true },
  )

  return {
    data: computed(() => subscription.value?.data ?? null),
    params: computed(() => subscription.value?.params ?? null),
    updated: computed(() => subscription.value?.updated ?? 0),
    loading: computed(() => !!subscription.value?.pending && !subscription.value?.data),
    refreshing: computed(() => !!subscription.value?.pending && !!subscription.value?.data),
    error: computed(() => subscription.value?.error ?? null),
    refresh: () => (subscription.value ? load(subscription.value, true) : Promise.resolve(null)),
    replace(data) {
      const entry = subscription.value
      if (!entry) return
      updateEntry(entry, data)
      channel?.postMessage({ backend, tags: entry.tags })
    },
  }
}

function updateEntry(entry, data) {
  cancel(entry)
  entry.updated = Date.now()
  entry.data = data
  entry.error = null
}

export function primeRead(spec, data, accountId) {
  if (accountScope.value && JSON.parse(accountScope.value)[1] === accountId)
    updateEntry(getEntry(spec), data)
}

export function invalidateReads(tags, broadcast = true) {
  if (tags.includes('categories')) writeSessionStorage(persistedKey, null)
  for (const entry of entries.values()) {
    if (!entry.tags.some((tag) => tags.includes(tag))) continue
    cancel(entry)
    entry.data = null
    entry.updated = 0
    entry.error = null
    if (entry.users) void load(entry)
  }
  for (const listener of invalidationListeners) listener(tags)
  if (broadcast) channel?.postMessage({ backend, tags })
}

export function onReadInvalidation(listener) {
  invalidationListeners.add(listener)
  return () => invalidationListeners.delete(listener)
}

export function revalidateActiveReads() {
  for (const entry of entries.values()) {
    if (entry.users) void load(entry)
  }
}
