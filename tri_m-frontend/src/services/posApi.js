import axios from 'axios'
import { expiredSessionMessage, PosError } from '@/utils/userFeedback'

const client = axios.create({
  baseURL: import.meta.env.VITE_BACKEND_URL || '',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

let onSessionEnded

export function handleSessionEnd(callback) {
  onSessionEnded = callback
}

export async function request(method, url, options = {}) {
  try {
    const response = await client.request({ method, url, ...options })
    return response.data
  } catch (problem) {
    if (options.signal?.aborted) throw problem
    const response = problem.response
    const status = response?.status
    if ([401, 419].includes(status) && !['/api/login', '/api/logout', '/api/user', '/sanctum/csrf-cookie'].includes(url)) {
      onSessionEnded?.()
    }
    const safe = response?.data?.pos_error === true ? response.data : null
    const fields = safe?.errors ?? {}
    const fieldError = Object.values(fields).flat()[0]
    const timedOut = ['ECONNABORTED', 'ETIMEDOUT'].includes(problem.code)
    const checkout = method.toLowerCase() === 'post' && url === '/api/sales'
    const stockIn = method.toLowerCase() === 'post' && (url === '/api/imports/confirm-workbook' || /^\/api\/imports\/[^/]+\/approve$/.test(url))
    const purchase = method.toLowerCase() === 'post' && url === '/api/stock-purchases'
    const message = (() => {
      if (!response || status >= 500) {
        if (checkout) return 'We could not confirm the sale. Your basket was kept. Check Orders before trying again.'
        if (stockIn) return 'We could not confirm stock-in. Your draft was kept. Check Import history before trying again.'
        if (purchase) return 'We could not confirm this purchase. Your entries were kept. Check Previous purchases before trying again.'
        if (timedOut) return 'This is taking longer than expected. Please try again.'
        if (!response) return 'We could not connect. Check your Wi-Fi or internet connection and try again.'
      }
      if (fieldError) return fieldError
      if (safe?.message) return safe.message
      if (status === 401 && url === '/api/login') return 'Check your email and password and try again.'
      if ([401, 419].includes(status)) return expiredSessionMessage
      if (status === 403) return 'You do not have permission to do this. Ask the Owner for help.'
      if (status === 404) return 'We could not find what you were looking for. Go back and try opening it again.'
      if (status === 422) return 'Check the information you entered and try again.'
      if (status === 429) return 'Too many attempts. Wait a moment and try again.'
      return 'Something went wrong. Please try again.'
    })()
    throw new PosError(message, { fields, status, cause: problem })
  }
}

export function toCentavos(value) {
  return Math.round(Number(value) * 100)
}

export function saleFromApi(sale) {
  return {
    id: sale.id,
    receiptNo: sale.receipt_no,
    createdAt: sale.created_at,
    cashier: sale.cashier.name,
    total: toCentavos(sale.total),
    lines: (sale.items ?? []).map((item) => ({
      id: item.id,
      name: item.name,
      quantity: item.quantity,
      unitPrice: toCentavos(item.unit_price),
      total: toCentavos(item.total),
    })),
    payment: {
      method: sale.payment.method,
      received: toCentavos(sale.payment.amount_tendered),
      change: toCentavos(sale.payment.change_due),
      provider: { gcash: 'GCash', maya: 'Maya' }[sale.payment.wallet_provider],
      reference: sale.payment.reference_no,
    },
  }
}
