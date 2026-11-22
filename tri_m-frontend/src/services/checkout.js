import { request, saleFromApi } from '@/services/posApi'
import { primeRead } from '@/services/posReadCache'
import { readSessionStorage, writeSessionStorage } from '@/utils/sessionStorage'
import { PosError } from '@/utils/userFeedback'

const pending = new Map()

export async function completeCheckout(accountId, lines, payment) {
  if (!globalThis.crypto?.subtle || !globalThis.crypto.randomUUID) {
    throw new PosError('Open the app on the store computer or use the secure address to complete this sale. Your basket was kept.')
  }
  const items = lines
    .map(({ product, quantity }) => ({ product_id: product.id, quantity }))
    .sort((left, right) => left.product_id - right.product_id)
  const data = { items, payment }
  const storageKey = 'tri-m-checkout-' + accountId
  const saved = pending.get(storageKey) ?? readSessionStorage(storageKey, {})
  const digest = await crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(JSON.stringify(data)),
  )
  const fingerprint = Array.from(new Uint8Array(digest)).join(',')
  const key = saved[fingerprint] ?? crypto.randomUUID()
  saved[fingerprint] = key
  pending.set(storageKey, saved)
  writeSessionStorage(storageKey, saved)

  const result = await request('post', '/api/sales', {
    data,
    headers: { 'Idempotency-Key': key },
  })
  if (!result?.data?.id) {
    throw new PosError('We could not confirm the sale. Your basket was kept. Check Orders before trying again.')
  }
  const sale = saleFromApi(result.data)
  primeRead({ kind: 'receipt', path: '/api/sales/' + result.data.id }, result, accountId)
  delete saved[fingerprint]
  writeSessionStorage(storageKey, saved)
  return sale
}
