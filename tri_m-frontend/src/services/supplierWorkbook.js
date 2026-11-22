import { strFromU8, unzipSync } from 'fflate'
import { PosError } from '@/utils/userFeedback'

const headings = {
  name: ['product', 'product name', 'item', 'item name', 'product description', 'item description', 'description', 'name'],
  category: ['category', 'product category'],
  quantity: ['quantity', 'qty', 'stock in', 'received', 'quantity received', 'qty received', 'units'],
  unit_cost: ['item cost', 'cost per item', 'unit cost', 'purchase cost', 'purchase price', 'cost'],
  selling_price: ['sell price', 'selling price', 'retail price', 'srp', 'price'],
  supplier: ['supplier', 'supplier name', 'vendor', 'vendor name'],
  date: ['date', 'purchase date', 'invoice date', 'stock in date', 'date received'],
}

export const identity = (value) => String(value ?? '').trim().replace(/\s+/gu, ' ').toLowerCase()

const children = (node, name) => [...(node?.children ?? [])].filter((child) => child.localName === name)
const first = (node, name) => children(node, name)[0]
const richText = (node) => children(node, 't').concat(children(node, 'r').flatMap((run) => children(run, 't')))
  .map(({ textContent }) => textContent).join('')

export async function readSupplierWorkbook(file) {
  if (!/\.xlsx$/i.test(file.name) || file.size > 2 * 1024 * 1024) {
    throw new PosError('Choose an Excel file ending in .xlsx that is 2 MB or less.')
  }

  let entries = 0
  let expanded = 0
  let files
  try {
    files = unzipSync(new Uint8Array(await file.arrayBuffer()), {
      filter({ name, originalSize }) {
        expanded += originalSize
        if (++entries > 150 || expanded > 20 * 1024 * 1024) {
          throw new PosError('This workbook is too large to open. Save it as smaller Excel files and try again.')
        }
        return name.endsWith('.xml') || name.endsWith('.rels')
      },
    })
  } catch (error) {
    if (error instanceof PosError) throw error
    throw new PosError('We could not open this workbook. Check that Excel can open it, then choose an unprotected .xlsx copy.', { cause: error })
  }

  const parser = new DOMParser()
  function xml(path) {
    if (!files[path]) throw new PosError('The workbook appears incomplete. Open it in Excel, save a new .xlsx copy, and try again.')
    const source = strFromU8(files[path])
    const document = parser.parseFromString(source, 'application/xml')
    if (/<!DOCTYPE/i.test(source) || document.getElementsByTagName('parsererror').length) {
      throw new PosError('We could not read this worksheet. Open the workbook in Excel, save a new copy, and try again.')
    }
    return document.documentElement
  }

  const book = xml('xl/workbook.xml')
  if (!files['[Content_Types].xml']) throw new PosError('Choose a readable Excel workbook ending in .xlsx.')
  const relationships = xml('xl/_rels/workbook.xml.rels')
  const targets = new Map()
  for (const link of children(relationships, 'Relationship')) {
    if (!link.getAttribute('Type')?.endsWith('/worksheet') || link.hasAttribute('TargetMode')) continue
    const target = link.getAttribute('Target') ?? ''
    const path = target.startsWith('/') ? target.slice(1) : 'xl/' + target
    if (/^xl\/(?:[\w-]+\/)*[\w.-]+\.xml$/.test(path)) targets.set(link.getAttribute('Id'), path)
  }
  const date1904 = ['1', 'true'].includes(first(book, 'workbookPr')?.getAttribute('date1904'))
  const namespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
  const sheets = children(first(book, 'sheets'), 'sheet')
    .map((sheet) => ({
      name: sheet.getAttribute('name'),
      visible: ['', 'visible'].includes(sheet.getAttribute('state') ?? ''),
      path: targets.get(sheet.getAttributeNS(namespace, 'id')),
    }))
    .filter(({ path }) => path)
    .map((sheet, index) => ({ ...sheet, index }))
  if (!sheets.some(({ visible }) => visible)) throw new PosError('Unhide the sheet you want to use in Excel and upload the workbook again.')
  const strings = files['xl/sharedStrings.xml']
    ? children(xml('xl/sharedStrings.xml'), 'si').map(richText)
    : []
  return {
    sheets: sheets.map(({ name, visible, index }) => ({ name, visible, index })),
    async worksheet(index) {
      const sheet = sheets[index]
      if (!sheet?.visible) throw new PosError('Choose a worksheet that is visible in Excel.')
      const rows = {}
      const data = first(xml(sheet.path), 'sheetData')
      for (const row of children(data, 'row')) {
        const number = Number(row.getAttribute('r'))
        for (const cell of children(row, 'c')) {
          const saved = first(cell, 'v')
          const inline = first(cell, 'is')
          if (!saved && !inline && !first(cell, 'f')) continue
          const address = /^([A-Z]{1,2})([1-9][0-9]*)$/.exec(cell.getAttribute('r') ?? '')
          if (!address || Number(address[2]) !== number || number > 1001 ||
              (address[1].length === 2 && address[1] > 'BL')) {
            throw new PosError('Use up to 1,001 rows and columns A through BL. Split the worksheet into smaller files if needed.')
          }
          const type = cell.getAttribute('t') || 'n'
          const value = type === 's' ? strings[Number(saved?.textContent)] : type === 'inlineStr'
            ? richText(inline) : saved?.textContent
          if ([...(value ?? '')].length > 2000) throw new PosError('Shorten the text in cell ' + cell.getAttribute('r') + ' to 2,000 characters or fewer.')
          rows[number] ??= {}
          rows[number][address[1]] = { type, value: value ?? null }
        }
      }
      return { index, name: sheet.name, date1904, rows }
    },
  }
}

export function worksheetSample({ index, name, rows }) {
  const entries = Object.entries(rows)
  const columns = [...new Set(entries.flatMap(([, cells]) => Object.keys(cells)))]
    .sort((a, b) => a.length - b.length || a.localeCompare(b))
  let header = Number(entries[0]?.[0] ?? 1)
  let mapping = {}
  let best = 0
  for (const [row, cells] of entries.slice(0, 25)) {
    const detected = {}
    for (const [field, aliases] of Object.entries(headings)) {
      const matches = Object.keys(cells).filter((column) => aliases.includes(
        identity(String(cells[column].value ?? '').replace(/\s*\(.*\)\s*$/u, '').replace(/[-_]/g, ' ')),
      ))
      if (matches.length === 1) detected[field] = matches[0]
    }
    const score = (detected.name && detected.quantity ? 100 : 0) + Object.keys(detected).length
    if (score > best) {
      best = score
      header = Number(row)
      mapping = detected
    }
  }
  return {
    index, name, header, mapping, columns,
    sample: entries.slice(0, 12).map(([row, cells]) => ({
      row: Number(row),
      cells: Object.fromEntries(Object.entries(cells).map(([column, cell]) => [column, cell.value])),
    })),
  }
}

function decimal(cell, currency = false) {
  if (!cell || ['b', 'e', 'd'].includes(cell.type)) return null
  let text = String(cell.value ?? '').trim()
  if (currency) text = text.replace(/^(?:PHP|₱)\s*/iu, '').trim()
  if (!/^\+?(?:\d{1,20}|\d{1,3}(?:,\d{3}){1,5})(?:\.\d{1,20})?(?:[Ee][+-]?\d{1,2})?$/.test(text)) return null
  const [coefficient, exponent = '0'] = text.replace(/[,+]/g, '').toLowerCase().split('e')
  const [whole, fraction = ''] = coefficient.split('.')
  const digits = whole + fraction
  const point = whole.length + Number(exponent)
  const left = point <= 0 ? '0' : digits.slice(0, point).padEnd(point, '0')
  const right = (point < 0 ? '0'.repeat(-point) + digits : digits.slice(point)).replace(/0+$/, '')
  return (left.replace(/^0+(?=\d)/, '') || '0') + (right ? '.' + right : '')
}

function money(cell, scale) {
  const value = decimal(cell, true)
  if (value === null || Number(value) > 9999999999.99 || (value.split('.')[1]?.length ?? 0) > scale) return null
  return value
}

function workbookDate(cell, date1904) {
  const text = String(cell?.value ?? '').trim()
  if (cell?.type === 'n' && /^\d{1,7}(?:\.\d+)?$/.test(text)) {
    const days = Math.floor(Number(text))
    if (days <= (date1904 ? 2957003 : 2958465) && (date1904 || (days > 0 && days !== 60))) {
      const base = date1904 ? '1904-01-01' : days < 60 ? '1899-12-31' : '1899-12-30'
      return new Date(Date.parse(base) + days * 86400000).toISOString().slice(0, 10)
    }
  }
  const iso = /^(\d{4})([-/])(\d{1,2})\2(\d{1,2})$/.exec(text)
  const written = /^(\d{1,2})-([a-z]{3})-(\d{2}|\d{4})$/i.exec(text)
  let date
  if (iso) {
    date = [iso[1], iso[3], iso[4]]
  } else if (written) {
    const month = 'jan feb mar apr may jun jul aug sep oct nov dec'.split(' ').indexOf(written[2].toLowerCase()) + 1
    const year = written[3].length === 2 ? Number(written[3]) + (Number(written[3]) < 70 ? 2000 : 1900) : Number(written[3])
    date = [String(year).padStart(4, '0'), String(month), written[1]]
  } else return null
  const normalized = date.map((part) => part.padStart(2, '0')).join('-')
  const time = Date.parse(normalized)
  return Number.isFinite(time) && new Date(time).toISOString().slice(0, 10) === normalized ? normalized : null
}

export function extractStockRows({ rows, date1904 }, header, mapping) {
  const sample = worksheetSample({ rows })
  if (!rows[header] || !mapping.name || !mapping.quantity || Object.values(mapping).some((column) => !sample.columns.includes(column))) {
    throw new PosError('Select the row with column names, then choose the columns for product name and quantity.')
  }
  const records = []
  const groups = new Map()
  for (const [rowNumber, row] of Object.entries(rows)) {
    const number = Number(rowNumber)
    if (number <= header) continue
    const cells = Object.fromEntries(Object.entries(mapping).map(([field, column]) => [field, row[column]]))
    const value = (field) => String(cells[field]?.value ?? '').trim()
    const labels = Object.values(row).map((cell) => identity(cell.value))
    if (!value('quantity') && labels.some((label) => label.startsWith('month of ') ||
      ['subtotal', 'sub total', 'total purchase', 'purchase total', 'grand total', 'total'].includes(label))) continue
    if (identity(value('name')) === identity(rows[header][mapping.name]?.value) &&
      identity(value('quantity')) === identity(rows[header][mapping.quantity]?.value)) continue
    if (!Object.keys(cells).some(value)) continue

    const name = value('name')
    const date = workbookDate(cells.date, date1904)
    const count = decimal(cells.quantity)
    const quantity = count && !count.includes('.') && Number(count) > 0 && Number(count) <= 2147483647 ? Number(count) : null
    const workbookPrice = money(cells.selling_price, 2)
    const price = Number(workbookPrice) > 0 ? workbookPrice : null
    const cost = money(cells.unit_cost, 16)
    const placeholder = /^\[.*(?:missing|cut.off|unknown|unreadable).*\]$/iu.test(name)
    const errors = []
    if (!name || [...name].length > 255) errors.push('Enter a product name of no more than 255 characters.')
    if (quantity === null) errors.push('Enter a positive whole quantity.')
    if (date === null && value('date')) errors.push('Check the date in this row.')
    if ([...value('supplier')].length > 150) errors.push('Enter a supplier name of no more than 150 characters.')
    if (price === null && value('selling_price')) errors.push('Check the selling price in this row.')
    if (cost === null && value('unit_cost')) errors.push('Check the item cost in this row.')
    if (placeholder) errors.push('This is a receipt placeholder. Skip this row.')

    const record = {
      id: number, row_number: number, uploaded_name: [...name].slice(0, 255).join('') || null,
      quantity, can_include: !placeholder, errors, warnings: [], sourceCategory: value('category'),
      stock_in: {
        date, source_date: value('date'), supplier: cells.supplier?.value ?? null,
        price, source_price: value('selling_price'), original_item_cost: cost,
        source_item_cost: value('unit_cost'), item_cost: cost,
      },
    }
    records.push(record)
    const key = JSON.stringify(Object.keys(cells).filter((field) => field !== 'category').sort().map((field) => identity(value(field))))
    const group = groups.get(key) ?? []
    group.push(record)
    groups.set(key, group)
  }
  for (const group of groups.values()) {
    if (group.length < 2) continue
    for (const row of group) {
      row.duplicateRows = group.filter((other) => other !== row).map((other) => other.row_number)
    }
  }
  if (!records.length) throw new PosError('We did not find any stock-in rows. Check the sheet and column settings, then try again.')
  return records
}
