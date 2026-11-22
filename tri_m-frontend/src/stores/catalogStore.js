import { computed } from 'vue'
import { defineStore } from 'pinia'
import { request, toCentavos } from '@/services/posApi'
import { invalidateReads, useCachedRead } from '@/services/posReadCache'
import { PosError } from '@/utils/userFeedback'

export function productFromApi(product) {
  return {
    id: product.id,
    name: product.name,
    category: product.category,
    supplier: product.supplier,
    priceCentavos: toCentavos(product.price),
    costCentavos: product.cost === undefined ? undefined : toCentavos(product.cost),
    markup: product.markup_percentage,
    stock: product.stock,
    archived: product.archived,
  }
}

export const useCatalogStore = defineStore('catalog', () => {
  const categoryList = useCachedRead(() => ({
    kind: 'categories',
    path: '/api/categories',
    params: { include_counts: 0 },
  }))
  const records = computed(() => categoryList.data.value?.data ?? [])
  const status = computed(() =>
    categoryList.data.value ? 'ready' : categoryList.error.value ? 'error' : 'loading',
  )
  const categories = computed(() => records.value.map((category) => category.name))

  function categoryId(name) {
    return records.value.find((category) => category.name === name)?.id
  }

  async function saveProduct(fields, id = null) {
    const category = categoryId(fields.category)
    if (fields.category && !category)
      throw new PosError('That category is unavailable. Reload categories and choose one again.', {
        fields: { category_id: ['That category is unavailable. Reload categories and choose one again.'] },
      })
    const data = {
      name: fields.name,
      category_id: category ?? null,
      cost: fields.cost,
      price: fields.price,
    }
    if (fields.markup !== '') data.markup_percentage = fields.markup
    if (id === null) data.stock = fields.stock
    const url = id === null ? '/api/products' : '/api/products/' + id
    const response = await request(id === null ? 'post' : 'put', url, { data })
    invalidateReads([
      'products',
      'category-counts',
      'low-stock',
      ...(id === null ? [] : ['product:' + id, 'sales-overview']),
    ])
    return productFromApi(response.data)
  }

  async function setArchived(id, archived) {
    await request(
      archived ? 'delete' : 'post',
      '/api/products/' + id + (archived ? '' : '/restore'),
    )
    invalidateReads(['products', 'product:' + id, 'low-stock'])
  }

  async function saveCategory(name, previousName = '') {
    const id = categoryId(previousName)
    await request(id ? 'put' : 'post', id ? '/api/categories/' + id : '/api/categories', {
      data: { name },
    })
    invalidateReads([
      'categories',
      'category-counts',
      ...(id ? ['products', 'product-details', 'low-stock'] : []),
    ])
  }

  async function removeCategory(name) {
    const id = categoryId(name)
    await request('delete', '/api/categories/' + id)
    invalidateReads(['categories', 'category-counts'])
  }

  return {
    categories,
    records,
    status,
    loadError: computed(() => categoryList.error.value),
    load: categoryList.refresh,
    categoryId,
    saveProduct,
    setArchived,
    saveCategory,
    removeCategory,
  }
})
