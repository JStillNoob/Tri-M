<script setup>
import { computed, reactive, ref } from 'vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import { identity } from '@/services/supplierWorkbook'
import { parsePrice } from '@/utils/price'
import { formatStockMoney, markupPattern, suggestedPrice } from '@/utils/stockMoney'
import { PosError, useFormFeedback } from '@/utils/userFeedback'

const props = defineProps({
  busy: Boolean, error: { type: String, default: '' },
  products: { type: Array, required: true },
  categories: { type: Array, required: true },
})
const emit = defineEmits(['save'])
const dialog = ref(null)
const feedback = useFormFeedback(['item_cost', 'approved_selling_price'])
const row = ref(null)
const fields = reactive({})
const query = ref('')
const results = ref([])

const choices = computed(() => [
  ...new Map(
    [row.value?.product, ...(row.value?.candidates ?? []), ...results.value]
      .filter((item) => item && !item.archived)
      .map((item) => [item.id, item]),
  ).values(),
])
const suggestion = computed(() => suggestedPrice(
  fields.item_cost || row.value?.stock_in.original_item_cost,
  fields.markup_percentage,
))
const finalPrice = computed(() => fields.approved_selling_price ?? suggestion.value)
const selectedProduct = computed(() => choices.value.find((item) => item.id === fields.product_id))

function open(item) {
  feedback.clear()
  row.value = item
  query.value = ''
  results.value = []
  const category = item.category_review
  const sourcePrice = item.stock_in.price
  const decision = !item.can_include ? 'skip' : item.decision === 'pending'
    ? (choices.value.length ? 'existing' : 'new') : item.decision
  Object.assign(fields, {
    decision,
    date: item.stock_in.date ?? '',
    supplier: item.stock_in.supplier ?? '',
    quantity: item.quantity ?? '',
    item_cost: parsePrice(item.stock_in.item_cost) !== null ? item.stock_in.item_cost : '',
    markup_percentage: item.markup_percentage ?? '',
    product_id: item.product?.archived ? '' : (item.product?.id ?? ''),
    new_name: item.new_name ?? item.uploaded_name ?? '',
    category_id: item.category_id ?? (decision === 'new'
      ? (category.use_source || category.create_name ? 'name' : (category.suggestion?.id ?? ''))
      : ''),
    category_name: category.category_name ?? '',
    price_decision: decision === 'existing' ? item.price_decision ?? '' : '',
    approved_selling_price: item.price_from_bulk_markup ? null : (item.approved_selling_price ??
      (decision === 'new' && parsePrice(sourcePrice) > 0 ? sourcePrice : null)),
  })
  dialog.value.open()
}

function searchProducts() {
  if (!query.value) return
  results.value = props.products
    .filter((product) => !product.archived && identity(product.name).includes(identity(query.value)))
    .slice(0, 20)
}

function submit() {
  const { decision, date, supplier, quantity, item_cost, markup_percentage, product_id, new_name, category_id, category_name } = fields
  feedback.clear()
  if (decision !== 'skip') {
    const errors = {}
    if (!item_cost && row.value.stock_in.original_item_cost == null) errors.item_cost = ['Enter the item cost.']
    if ((decision === 'new' || fields.price_decision === 'change') && !(Number(finalPrice.value) > 0)) {
      errors.approved_selling_price = ['Enter a selling price greater than zero.']
    }
    if (Object.keys(errors).length) {
      feedback.show(new PosError('', { fields: errors }))
      return
    }
  }
  const data = { decision }
  if (decision !== 'skip') Object.assign(data, {
    date: date || null,
    supplier: supplier || null,
    quantity,
    item_cost: item_cost && item_cost !== row.value.stock_in.original_item_cost ? item_cost : null,
    markup_percentage: markup_percentage || null,
    approved_selling_price: (decision === 'new' || fields.price_decision === 'change') ? finalPrice.value || null : null,
  })
  if (decision === 'existing') Object.assign(data, {
    product_id,
    category_id: category_id || null,
    price_decision: fields.price_decision,
    reviewed_catalog_price: selectedProduct.value?.price,
  })
  if (decision === 'new') {
    Object.assign(data, {
      new_name,
      category_id: category_id === 'name' ? null : category_id,
      use_workbook_category: category_id === 'name',
      ...(category_id === 'name' ? { category_name } : {}),
    })
  }
  emit('save', row.value.id, data)
}

defineExpose({ open, close: () => dialog.value.close() })
</script>

<template>
  <PosDialog ref="dialog" :title="row ? 'Edit row ' + row.row_number : 'Edit row'">
    <form v-if="row" class="grid gap-3" @submit.prevent="submit" @input="feedback.clear()">
      <p class="font-semibold [overflow-wrap:anywhere]">{{ row.uploaded_name || row.product?.name || 'Unnamed product' }}</p>
      <ul v-if="row.errors.length || row.warnings.length" class="screen-note list-disc ps-5">
        <li v-for="message in [...row.errors, ...row.warnings]" :key="message">{{ message }}</li>
      </ul>
      <fieldset class="grid min-w-0 gap-3" :disabled="busy">
        <div class="dialog-field">
          <label for="stock-row-action">Action</label>
          <select id="stock-row-action" v-model="fields.decision" @change="fields.decision === 'existing' && fields.category_id === 'name' && (fields.category_id = '')">
            <option value="existing" :disabled="!row.can_include">Add stock to an existing product</option>
            <option value="new" :disabled="!row.can_include">Create a new product</option>
            <option value="skip">Skip this row</option>
          </select>
        </div>

        <template v-if="fields.decision === 'existing'">
          <label class="dialog-field">
            Find a product by name
            <input v-model.trim="query" type="search" maxlength="100" @keydown.enter.prevent="searchProducts" />
          </label>
          <button class="dialog-action justify-self-start" type="button" :disabled="!query" @click="searchProducts">
            Find products
          </button>
          <div class="dialog-field">
            <label for="stock-row-product">Product</label>
            <select id="stock-row-product" v-model="fields.product_id" required @change="fields.price_decision = ''; fields.approved_selling_price = null">
              <option value="">Select a product</option>
              <option v-for="item in choices" :key="item.id" :value="item.id">
                {{ item.name }} · {{ item.stock }} in stock · ₱{{ item.price }}
              </option>
            </select>
          </div>
          <p v-if="selectedProduct && fields.quantity" class="screen-note">
            In stock {{ selectedProduct.stock }} · Incoming {{ fields.quantity }} ·
            After this row {{ selectedProduct.stock + Number(fields.quantity) }}
          </p>
          <div class="dialog-field">
            <label for="stock-row-existing-category">Category on this record</label>
            <select id="stock-row-existing-category" v-model="fields.category_id">
              <option value="">Use product category</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
          </div>
        </template>
        <template v-if="fields.decision === 'new'">
          <label class="dialog-field">
            Product name
            <input v-model.trim="fields.new_name" required maxlength="255" />
          </label>
          <div class="dialog-field">
            <label for="stock-row-category">Category</label>
            <select id="stock-row-category" v-model="fields.category_id" required>
              <option value="">Select a category</option>
              <option value="name">Enter a category name</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
          </div>
          <label v-if="fields.category_id === 'name'" class="dialog-field">
            Category name
            <input v-model.trim="fields.category_name" required maxlength="100" />
          </label>
        </template>

        <template v-if="fields.decision !== 'skip'">
          <label class="dialog-field">
            Date
            <input v-model="fields.date" type="date" />
          </label>
          <label class="dialog-field">
            Quantity
            <input v-model.number="fields.quantity" type="number" min="1" max="2147483647" step="1" required />
          </label>
          <label class="dialog-field">
            Supplier
            <input v-model.trim="fields.supplier" maxlength="150" />
          </label>
          <label class="dialog-field">
            Item cost (₱)
            <input v-model.trim="fields.item_cost" inputmode="decimal" pattern="[0-9]{1,10}(\.[0-9]{1,2})?"
              v-bind="feedback.bind('item_cost')" :required="row.stock_in.original_item_cost == null" />
            <PosFieldError :id="feedback.fieldId('item_cost')" :message="feedback.inline('item_cost')" />
          </label>
          <p v-if="!fields.item_cost && row.stock_in.original_item_cost" class="screen-note [overflow-wrap:anywhere]">
            Using workbook cost of ₱{{ row.stock_in.original_item_cost }} per item.
          </p>
          <label class="dialog-field">
            Markup (%)
            <input v-model.trim="fields.markup_percentage" inputmode="decimal" :pattern="markupPattern" />
          </label>
          <p v-if="suggestion" class="screen-note">
            Suggested sell price <strong>{{ formatStockMoney(suggestion) }}</strong>
          </p>
          <div v-if="fields.decision === 'existing'" class="dialog-field">
            <label for="stock-row-price-decision">Selling price decision</label>
            <select id="stock-row-price-decision" v-model="fields.price_decision" required>
              <option value="">Choose a price decision</option>
              <option value="keep">Keep the current price{{ selectedProduct ? ' · ' + formatStockMoney(selectedProduct.price) : '' }}</option>
              <option value="change">Approve a new price</option>
            </select>
          </div>
          <p v-if="fields.decision === 'existing' && fields.price_decision === 'change'" class="screen-note">
            The approved price applies to all units after confirmation.
          </p>
          <label v-if="fields.decision === 'new' || fields.price_decision === 'change'" class="dialog-field">
            Sell price (₱)
            <input
              :value="finalPrice"
              inputmode="decimal"
              pattern="[0-9]{1,10}(\.[0-9]{1,2})?"
              v-bind="feedback.bind('approved_selling_price')"
              required
              @input="fields.approved_selling_price = $event.target.value.trim()"
            />
            <PosFieldError :id="feedback.fieldId('approved_selling_price')" :message="feedback.inline('approved_selling_price')" />
          </label>
          <button
            v-if="(fields.decision === 'new' || fields.price_decision === 'change') && suggestion && finalPrice !== suggestion"
            type="button"
            class="dialog-action justify-self-start"
            @click="fields.approved_selling_price = null"
          >
            Use suggested price
          </button>
        </template>
      </fieldset>
      <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
      <button class="dialog-action dialog-action--primary justify-self-start" type="submit" :disabled="busy">
        {{ busy ? 'Saving…' : 'Save row' }}
      </button>
    </form>
  </PosDialog>
</template>
