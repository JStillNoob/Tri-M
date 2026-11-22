const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
export const markupPattern = '[0-9]{1,5}(\\.[0-9]{1,4})?'

export function formatStockMoney(value) {
  return value == null ? '–' : currency.format(value)
}

export function suggestedPrice(cost, markup) {
  const costText = String(cost ?? '').trim()
  const markupText = String(markup ?? '').trim()
  if (!/^\d{1,10}(?:\.\d{1,16})?$/.test(costText) || !new RegExp('^' + markupPattern + '$').test(markupText)) {
    return ''
  }
  const [whole, fraction = ''] = costText.split('.')
  const [percentage, decimals = ''] = markupText.split('.')
  const rate = BigInt(percentage + decimals.padEnd(4, '0'))
  const divisor = 10n ** BigInt(fraction.length) * 1000000n
  const numerator = BigInt(whole + fraction) * (1000000n + rate)
  const pesos = (numerator + divisor - 1n) / divisor

  if (pesos <= 0n || pesos > 9999999999n) return ''
  return `${pesos}.00`
}

export function purchaseTotal(cost, quantity) {
  const value = String(cost ?? '').trim()
  if (!/^\d{1,10}(?:\.\d{1,16})?$/.test(value) || !Number.isInteger(quantity) || quantity < 1) {
    return null
  }
  const [whole, fraction = ''] = value.split('.')
  const divisor = 10n ** BigInt(fraction.length)
  const cents = (BigInt(whole + fraction) * BigInt(quantity) * 100n + divisor / 2n) / divisor
  return String(cents / 100n) + '.' + String(cents % 100n).padStart(2, '0')
}
