import { parsePrice } from '@/utils/price'

export const maximumStock = 2147483647

export function normalizeSku(value) {
  const sku = String(value ?? '')
    .trim()
    .toUpperCase()
  if (!sku || sku.length > 100) throw new Error('Enter a SKU of 1 to 100 characters.')
  return sku
}

export function parseStock(value) {
  const text = String(value ?? '').trim()
  if (!/^\d+$/.test(text) || Number(text) > maximumStock) {
    throw new Error('Enter a valid whole stock quantity of zero or more.')
  }
  return Number(text)
}

export function resolveCategory(value, categories) {
  const name = String(value ?? '').trim()
  if (!name) return ''

  const category = categories.find((item) => item.toLowerCase() === name.toLowerCase())
  if (!category) throw new Error('Choose a category that already exists.')
  return category
}

export function productDetails(fields, categories) {
  const sku = normalizeSku(fields.sku)
  const name = String(fields.name ?? '').trim()
  if (!name || name.length > 255) throw new Error('Enter a product name of 1 to 255 characters.')

  const costCentavos = parsePrice(fields.cost)
  if (costCentavos === null) throw new Error('Enter a valid cost with up to two decimal places.')
  const priceCentavos = parsePrice(fields.price)
  if (priceCentavos === null) throw new Error('Enter a valid price with up to two decimal places.')

  return {
    sku,
    name,
    category: resolveCategory(fields.category, categories),
    costCentavos,
    priceCentavos,
  }
}
