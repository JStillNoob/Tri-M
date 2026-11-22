const phPeso = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
})

export function formatPrice(centavos) {
  return phPeso.format(centavos / 100)
}

export function parsePrice(amount) {
  const value = String(amount).trim()

  if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(value)) return null

  const [pesos, centavos = ''] = value.split('.')
  return Number(pesos) * 100 + Number(centavos.padEnd(2, '0'))
}
