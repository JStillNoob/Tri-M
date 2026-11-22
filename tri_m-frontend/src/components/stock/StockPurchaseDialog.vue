<script setup>
import { computed, reactive, ref, watch } from 'vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import { request } from '@/services/posApi'
import { invalidateReads } from '@/services/posReadCache'
import { useCatalogStore } from '@/stores/catalogStore'
import { formatStockMoney, markupPattern, purchaseTotal, suggestedPrice } from '@/utils/stockMoney'
import { useFormFeedback, userMessage } from '@/utils/userFeedback'

const emit = defineEmits(['saved'])
const dialog = ref(null)
const catalog = useCatalogStore()
const suppliers = ref(null)
const supplierSelect = ref(null)
const supplierDetails = ref(null)
const newSupplier = ref('')
const supplierBusy = ref(false)
const supplierError = ref('')
const feedback = useFormFeedback(['product_name', 'category_id', 'supplier_id', 'purchase_date', 'quantity', 'unit_cost', 'markup_percentage', 'selling_price'])
const supplierFeedback = useFormFeedback(['name'])
const { alert: error } = feedback
const { alert: supplierSaveError } = supplierFeedback
const pending = ref(false)
const matching = ref(false)
const matchError = ref('')
const match = ref(null)
const matchAttempt = ref(0)
const fields = reactive({})
const dateFormat = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', dateStyle: 'short' })
let key

const suggestion = computed(() => suggestedPrice(fields.unit_cost, fields.markup_percentage))
const editingPrice = computed(() => !match.value || fields.update_catalog)
const sellingPrice = computed(() => editingPrice.value
  ? fields.selling_price ?? suggestion.value
  : match.value.price,
)
const totalCost = computed(() => purchaseTotal(fields.unit_cost, Number(fields.quantity)))

async function loadSuppliers() {
  supplierError.value = ''
  try {
    suppliers.value = (await request('get', '/api/suppliers')).data
  } catch (problem) {
    supplierError.value = userMessage(problem, 'We could not load suppliers. Try again.')
  }
}

function open() {
  Object.assign(fields, {
    purchase_date: dateFormat.format(new Date()),
    product_name: '',
    category_id: '',
    supplier_id: '',
    quantity: 1,
    unit_cost: '',
    markup_percentage: '',
    selling_price: null,
    update_catalog: false,
  })
  match.value = null
  feedback.clear()
  supplierFeedback.clear()
  matchError.value = ''
  newSupplier.value = ''
  supplierDetails.value.open = false
  key = globalThis.crypto?.randomUUID?.()
  dialog.value.open()
  void loadSuppliers()
}

watch([() => fields.product_name, matchAttempt], ([name], [previousName], onCleanup) => {
  match.value = null
  matchError.value = ''
  if (name !== previousName) {
    fields.update_catalog = false
    fields.selling_price = null
    fields.category_id = ''
  }
  matching.value = Boolean(name)
  if (!name) return

  const controller = new AbortController()
  const timer = setTimeout(async () => {
    try {
      const { data } = await request('get', '/api/stock-purchases/product', {
        params: { name },
        signal: controller.signal,
      })
      if (controller.signal.aborted) return
      match.value = data
      if (data) {
        fields.category_id = data.category_id ?? ''
        fields.markup_percentage = data.markup_percentage ?? ''
      }
    } catch (problem) {
      if (!controller.signal.aborted) matchError.value = userMessage(problem, 'We could not check the catalog for this product. Try again.')
    } finally {
      if (!controller.signal.aborted) matching.value = false
    }
  }, 250)

  onCleanup(() => {
    clearTimeout(timer)
    controller.abort()
  })
}, { flush: 'sync' })

async function addSupplier() {
  if (!newSupplier.value || supplierBusy.value) return
  supplierBusy.value = true
  supplierFeedback.clear()
  supplierError.value = ''
  try {
    const { data } = await request('post', '/api/suppliers', { data: { name: newSupplier.value } })
    suppliers.value = [...(suppliers.value ?? []).filter(({ id }) => id !== data.id), data]
      .sort((left, right) => left.name.localeCompare(right.name))
    fields.supplier_id = data.id
    newSupplier.value = ''
    supplierDetails.value.open = false
    supplierSelect.value.focus()
  } catch (problem) {
    supplierFeedback.show(problem, 'We could not save this supplier. Try again.')
  } finally {
    supplierBusy.value = false
  }
}

async function save() {
  if (pending.value || matching.value || matchError.value) return
  feedback.clear()
  if (!key) {
    error.value = 'Open the app on the store computer or use the secure address to save stock purchases.'
    return
  }
  pending.value = true
  try {
    const response = await request('post', '/api/stock-purchases', {
      headers: { 'Idempotency-Key': key },
      data: { ...fields, category_id: fields.category_id || null, selling_price: sellingPrice.value },
    })
    invalidateReads(['products', 'product:' + response.data.product_id, 'category-counts', 'low-stock'])
    dialog.value.close()
    emit('saved')
  } catch (problem) {
    feedback.show(problem, 'We could not confirm this purchase. Your entries were kept. Check Previous purchases before trying again.')
  } finally {
    pending.value = false
  }
}

defineExpose({ open })
</script>

<template>
  <PosDialog ref="dialog" title="Add stock purchase">
    <form class="grid gap-3" @submit.prevent="save" @input="feedback.clear()">
      <fieldset class="grid min-w-0 gap-3" :disabled="pending">
        <label class="dialog-field">
          Product name
          <input v-model.trim="fields.product_name" maxlength="255" required v-bind="feedback.bind('product_name')" />
          <PosFieldError :id="feedback.fieldId('product_name')" :message="feedback.inline('product_name')" />
        </label>
        <p v-if="matching" class="screen-note" role="status">Checking the catalog…</p>
        <div v-else-if="matchError" class="grid gap-2">
          <p class="screen-error" role="alert">{{ matchError }}</p>
          <button class="dialog-action justify-self-start" type="button" @click="matchAttempt++">Retry product check</button>
        </div>
        <p v-else-if="match" class="screen-note">Restock {{ match.name }} · {{ match.stock }} in stock</p>
        <div class="dialog-field">
          <label for="purchase-category">Category</label>
          <select id="purchase-category" v-model="fields.category_id" :disabled="Boolean(match)" required v-bind="feedback.bind('category_id')">
            <option value="">Choose a category</option>
            <option v-for="category in catalog.records" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
          <PosFieldError :id="feedback.fieldId('category_id')" :message="feedback.inline('category_id')" />
        </div>
        <div class="dialog-field">
          <label for="purchase-supplier">Supplier</label>
          <select id="purchase-supplier" ref="supplierSelect" v-model="fields.supplier_id" required v-bind="feedback.bind('supplier_id')">
            <option value="">{{ suppliers ? 'Choose a supplier' : 'Loading suppliers…' }}</option>
            <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">
              {{ supplier.name }}
            </option>
          </select>
          <PosFieldError :id="feedback.fieldId('supplier_id')" :message="feedback.inline('supplier_id')" />
        </div>
        <details ref="supplierDetails">
          <summary>Add a supplier</summary>
          <div class="grid gap-2 pt-2">
            <label class="dialog-field">
              Supplier name
              <input v-model.trim="newSupplier" maxlength="150" v-bind="supplierFeedback.bind('name')"
                @input="supplierFeedback.clear()" @keydown.enter.prevent="addSupplier" />
              <PosFieldError :id="supplierFeedback.fieldId('name')" :message="supplierFeedback.inline('name')" />
            </label>
            <button class="dialog-action justify-self-start" type="button" :disabled="!newSupplier || supplierBusy" @click="addSupplier">
              Save supplier
            </button>
            <p v-if="supplierSaveError" class="screen-error" role="alert">{{ supplierSaveError }}</p>
          </div>
        </details>
        <div v-if="supplierError" class="grid gap-2">
          <p class="screen-error" role="alert">{{ supplierError }}</p>
          <button class="dialog-action justify-self-start" type="button" @click="loadSuppliers">Reload suppliers</button>
        </div>
        <label class="dialog-field">
          Purchase date
          <input v-model="fields.purchase_date" type="date" required v-bind="feedback.bind('purchase_date')" />
          <PosFieldError :id="feedback.fieldId('purchase_date')" :message="feedback.inline('purchase_date')" />
        </label>
        <div class="grid grid-cols-2 gap-2">
          <label class="dialog-field">
            Quantity
            <input v-model.number="fields.quantity" type="number" min="1" max="2147483647" step="1" required v-bind="feedback.bind('quantity')" />
            <PosFieldError :id="feedback.fieldId('quantity')" :message="feedback.inline('quantity')" />
          </label>
          <label class="dialog-field">
            Cost per item (₱)
            <input v-model.trim="fields.unit_cost" inputmode="decimal" pattern="[0-9]{1,10}(\.[0-9]{1,2})?" required v-bind="feedback.bind('unit_cost')" />
            <PosFieldError :id="feedback.fieldId('unit_cost')" :message="feedback.inline('unit_cost')" />
          </label>
        </div>
        <p class="summary-line"><span>Total cost</span> <strong>{{ formatStockMoney(totalCost) }}</strong></p>
        <label class="dialog-field">
          Markup (%)
          <input v-model.trim="fields.markup_percentage" inputmode="decimal" :pattern="markupPattern" required v-bind="feedback.bind('markup_percentage')" />
          <PosFieldError :id="feedback.fieldId('markup_percentage')" :message="feedback.inline('markup_percentage')" />
        </label>
        <p v-if="suggestion" class="summary-line"><span>Suggested sell price</span> <strong>{{ formatStockMoney(suggestion) }}</strong></p>
        <label v-if="match" class="flex min-h-11 items-center gap-2">
          <input v-model="fields.update_catalog" type="checkbox" />
          Update catalog selling price and markup
        </label>
        <label v-if="editingPrice" class="dialog-field">
          Sell price (₱)
          <input
            :value="sellingPrice"
            inputmode="decimal"
            pattern="[0-9]{1,10}(\.[0-9]{1,2})?"
            v-bind="feedback.bind('selling_price')"
            required
            @input="fields.selling_price = $event.target.value.trim()"
          />
          <PosFieldError :id="feedback.fieldId('selling_price')" :message="feedback.inline('selling_price')" />
        </label>
        <p v-else class="summary-line"><span>Catalog sell price</span> <strong>{{ formatStockMoney(sellingPrice) }}</strong></p>
        <button
          v-if="editingPrice && suggestion && sellingPrice !== suggestion"
          class="dialog-action justify-self-start"
          type="button"
          @click="fields.selling_price = null"
        >Use suggested price</button>
      </fieldset>
      <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
      <button class="dialog-action dialog-action--primary justify-self-start" type="submit" :disabled="pending || matching || Boolean(matchError)">
        {{ pending ? 'Saving purchase…' : 'Save stock purchase' }}
      </button>
    </form>
  </PosDialog>
</template>
