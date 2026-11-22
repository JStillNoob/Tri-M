const transactionDateFormat = new Intl.DateTimeFormat('en-PH', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: 'Asia/Manila',
})

export const walletProviders = ['GCash', 'Maya']

export function transactionDate(value) {
  return transactionDateFormat.format(new Date(value))
}

export function paymentLabel(payment) {
  return payment.method === 'cash' ? 'Cash' : payment.provider
}
