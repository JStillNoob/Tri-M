<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ChevronRight, Minus, Plus } from '@lucide/vue'
import DashboardSectionState from '@/components/dashboard/DashboardSectionState.vue'
import SalesTrendChart from '@/components/dashboard/SalesTrendChart.vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import PosToast from '@/components/shared/PosToast.vue'
import { request, toCentavos } from '@/services/posApi'
import { useCachedRead } from '@/services/posReadCache'
import { useCatalogStore } from '@/stores/catalogStore'
import { formatPrice } from '@/utils/price'
import { formatStockMoney } from '@/utils/stockMoney'
import { userMessage } from '@/utils/userFeedback'

const catalog = useCatalogStore()
const periods = [
  { value: 'today', label: 'Today', title: 'today', previous: 'this time yesterday' },
  { value: 'week', label: 'This Week', title: 'this week', previous: 'this time last week' },
  { value: 'month', label: 'This Month', title: 'this month', previous: 'this time last month' },
]
const period = ref('today')
const categoryId = ref('')
const showRetail = ref(false)
const targetDialog = ref(null)
const targetAmount = ref('')
const targetError = ref('')
const targetNotice = ref('')
const savingTarget = ref(false)
const displayNumber = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 })

const overview = reactive(
  useCachedRead(() => ({
    kind: 'overview',
    path: '/api/dashboard/overview',
    params: { period: period.value, category_id: categoryId.value },
  })),
)
const orders = reactive(
  useCachedRead(() => ({
    kind: 'recentOrders',
    path: '/api/dashboard/recent-orders',
    params: { limit: 5 },
  })),
)
const stock = reactive(
  useCachedRead(() => ({
    kind: 'lowStock',
    path: '/api/dashboard/low-stock',
    params: { per_page: 5 },
  })),
)
const target = reactive(useCachedRead(() => ({ kind: 'target', path: '/api/dashboard/targets' })))
const financials = reactive(useCachedRead(() => ({ kind: 'stockFinancials', path: '/api/dashboard/stock-financials' })))
const stockFinances = computed(() => financials.data?.data)
const selected = computed(() => periods.find((option) => option.value === period.value))
const money = (amount) => formatPrice(toCentavos(amount))

const comparison = computed(() => {
  const report = overview.data?.data
  if (report?.sales_difference == null)
    return 'No completed sales to compare in the previous period.'
  const change = Number(report.sales_difference)
  if (change === 0) return 'Sales are the same as ' + selected.value.previous + '.'
  const percent =
    report.sales_percentage_change == null
      ? ''
      : ' (' + displayNumber.format(Math.abs(Number(report.sales_percentage_change))) + '%)'
  return (
    money(Math.abs(change)) +
    percent +
    (change > 0 ? ' more than ' : ' less than ') +
    selected.value.previous +
    '.'
  )
})

const report = computed(() => overview.data?.data)
const goal = computed(() => target.data?.data)

watch(
  () => catalog.records,
  (categories) => {
    if (
      catalog.status === 'ready' &&
      categoryId.value &&
      !categories.some(({ id }) => id === categoryId.value)
    )
      categoryId.value = ''
  },
)

function refreshDashboard() {
  for (const section of [overview, orders, stock, target, financials]) void section.refresh()
}

function openTarget() {
  targetAmount.value = goal.value?.amount ?? ''
  targetError.value = ''
  targetNotice.value = ''
  targetDialog.value?.open()
}

async function saveTarget() {
  if (savingTarget.value || !goal.value) return
  savingTarget.value = true
  targetError.value = ''

  try {
    const response = await request('put', '/api/dashboard/targets', {
      data: { month: goal.value.month, amount: String(targetAmount.value) },
    })
    target.replace(response)
    targetNotice.value = 'Sales target saved.'
    targetDialog.value?.close()
  } catch (problem) {
    targetError.value = userMessage(problem, 'We could not save your sales target. Check the amount and try again.')
    if (problem.status === 422) void target.refresh()
  } finally {
    savingTarget.value = false
  }
}
</script>

<template>
  <div class="owner-dashboard">
    <header class="dashboard-header">
      <h1 class="screen-title">Dashboard</h1>
      <div class="dashboard-filters">
        <div class="period-options" role="group" aria-label="Sales period">
          <button
            v-for="option in periods"
            :key="option.value"
            type="button"
            :aria-pressed="period === option.value"
            @click="period = option.value"
          >
            {{ option.label }}
          </button>
        </div>
        <div class="category-filter">
          <label for="dashboard-category" class="category-label">Sales category</label>
          <select
            id="dashboard-category"
            v-model="categoryId"
            :disabled="catalog.status !== 'ready'"
          >
            <option value="">All Categories</option>
            <option v-for="category in catalog.records" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
          <p v-if="catalog.status === 'error'" class="screen-error" role="alert">
            {{ userMessage(catalog.loadError, 'We could not load categories. Try again.') }}
          </p>
          <button
            v-if="catalog.status === 'error'"
            class="dashboard-link"
            type="button"
            @click="catalog.load()"
          >
            Retry categories
          </button>
        </div>
      </div>
    </header>

    <section class="overview-card" aria-label="Sales overview">
      <DashboardSectionState
        v-if="!report"
        :loading="overview.loading"
        :error="overview.error ? userMessage(overview.error) : ''"
        label="sales overview"
        @retry="overview.refresh"
      />
      <template v-else-if="report">
        <div class="overview-sales">
          <h2>Total sales {{ selected.title }}</h2>
          <p class="featured-total">{{ money(report.sales_total) }}</p>
          <p class="dashboard-note">{{ comparison }}</p>
          <p v-if="categoryId" class="dashboard-note">
            Category sales use the current product categories.
          </p>
          <p v-if="overview.error" class="dashboard-note" role="status">
            Showing the last available report.
          </p>
          <button
            class="dashboard-link"
            type="button"
            :disabled="overview.refreshing || savingTarget"
            @click="refreshDashboard"
          >
            {{ overview.refreshing ? 'Refreshing…' : 'Refresh dashboard' }}
          </button>
        </div>
        <div class="overview-trend">
          <h2>Sales trend</h2>
          <p class="dashboard-note">Running total</p>
          <SalesTrendChart v-if="report.total_orders" :points="report.trend" cumulative />
          <p v-else class="dashboard-empty">
            No completed sales {{ selected.title }}{{ categoryId ? ' in this category' : '' }}.
          </p>
        </div>
        <dl class="overview-averages">
          <div>
            <dt>Average sale value</dt>
            <dd>{{ money(report.average_sale_value) }}</dd>
          </div>
          <div>
            <dt>Average items per sale</dt>
            <dd>{{ displayNumber.format(Number(report.average_items_per_sale)) }}</dd>
          </div>
        </dl>
      </template>
    </section>

    <section class="dashboard-section grid gap-3" aria-labelledby="stock-financials-heading">
      <h2 id="stock-financials-heading">Stock and profit</h2>
      <DashboardSectionState
        v-if="!stockFinances"
        :loading="financials.loading"
        :error="financials.error ? userMessage(financials.error) : ''"
        label="stock and profit"
        @retry="financials.refresh"
      />
      <template v-else>
        <div class="finance-groups">
          <div>
            <h3>Recorded purchases · All time</h3>
            <dl>
              <div><dt>Total stock investment</dt><dd>{{ formatStockMoney(stockFinances.stock_investment) }}</dd></div>
              <div><dt>Total expected sales</dt><dd>{{ formatStockMoney(stockFinances.expected_sales) }}</dd></div>
              <div><dt>Total expected gross profit</dt><dd>{{ formatStockMoney(stockFinances.expected_gross_profit) }}</dd></div>
            </dl>
            <p class="dashboard-note">Expected figures assume every purchased unit sells at its approved price.</p>
            <p v-if="stockFinances.incomplete_purchases" class="dashboard-note">
              {{ stockFinances.incomplete_purchases }} older purchase rows have incomplete cost or price data.
            </p>
          </div>
          <div>
            <h3>Completed sales · All time</h3>
            <dl>
              <div><dt>Actual sales</dt><dd>{{ formatStockMoney(stockFinances.actual_sales) }}</dd></div>
              <div><dt>Actual gross profit</dt><dd>{{ formatStockMoney(stockFinances.actual_gross_profit) }}</dd></div>
            </dl>
          </div>
          <div>
            <h3>Current inventory</h3>
            <dl>
              <div><dt>Remaining stock value at cost</dt><dd>{{ formatStockMoney(stockFinances.remaining_stock_value) }}</dd></div>
            </dl>
          </div>
        </div>
      </template>
    </section>

    <div class="dashboard-columns">
      <section class="dashboard-section" aria-labelledby="performance-heading">
        <header class="performance-header">
          <h2 id="performance-heading">Your sales {{ selected.title }}</h2>
          <button
            v-if="report"
            class="dashboard-link"
            type="button"
            :aria-expanded="showRetail"
            aria-controls="retail-metrics"
            @click="showRetail = !showRetail"
          >
            <component :is="showRetail ? Minus : Plus" :size="17" aria-hidden="true" />
            {{ showRetail ? 'Hide retail metrics' : 'Show more retail metrics' }}
          </button>
        </header>
        <div v-if="report" class="performance-chart">
          <p class="dashboard-note">{{ period === 'today' ? 'Sales by hour' : 'Sales by day' }}</p>
          <SalesTrendChart v-if="report.total_orders" :points="report.trend" />
          <p v-else class="dashboard-empty">Sales will appear here once orders are completed.</p>
          <div v-show="showRetail" id="retail-metrics" class="retail-metrics">
            <dl>
              <div>
                <dt>Units sold</dt>
                <dd>{{ displayNumber.format(report.retail_metrics.units_sold) }}</dd>
              </div>
              <div>
                <dt>Products sold</dt>
                <dd>{{ displayNumber.format(report.retail_metrics.unique_products) }}</dd>
              </div>
              <div>
                <dt>Gross profit</dt>
                <dd>{{ money(report.retail_metrics.gross_profit) }}</dd>
              </div>
              <div v-for="payment in report.retail_metrics.payments" :key="payment.method">
                <dt>{{ payment.method === 'cash' ? 'Cash sales' : 'E-wallet sales' }}</dt>
                <dd>{{ money(payment.total) }}</dd>
              </div>
              <div>
                <dt>Best-selling product</dt>
                <dd>{{ report.retail_metrics.best_seller?.name ?? 'No sales yet' }}</dd>
              </div>
            </dl>
          </div>
        </div>
        <p v-else class="dashboard-note">
          {{
            overview.loading ? 'Loading sales performance…' : 'Sales performance is unavailable.'
          }}
        </p>

        <div class="performance-stats">
          <div class="sales-target">
            <h3>Monthly sales target</h3>
            <DashboardSectionState
              v-if="!goal"
              :loading="target.loading"
              :error="target.error ? userMessage(target.error) : ''"
              label="sales target"
              @retry="target.refresh"
            />
            <template v-else-if="goal">
              <p v-if="goal.amount !== null" class="stat-value">{{ money(goal.amount) }}</p>
              <p v-else class="dashboard-note">No target for {{ goal.label }}.</p>
              <template v-if="goal.amount !== null">
                <progress
                  :value="Math.min(Number(goal.achievement_percentage), 100)"
                  max="100"
                  aria-label="Monthly sales target progress"
                ></progress>
                <p class="dashboard-note">
                  {{ money(goal.actual_sales) }} sold ·
                  {{ displayNumber.format(Number(goal.achievement_percentage)) }}%
                </p>
              </template>
              <button class="dashboard-link" type="button" @click="openTarget">
                {{ goal.amount === null ? 'Set a sales target' : 'Edit sales target' }}
              </button>
            </template>
          </div>
          <template v-if="report">
            <div>
              <h3>Average daily sales</h3>
              <p class="stat-value">{{ money(report.average_daily_sales) }}</p>
              <p v-if="report.calendar_days > 1" class="dashboard-note">
                Over {{ report.calendar_days }} calendar days
              </p>
            </div>
            <div>
              <h3>{{ categoryId ? 'Orders with this category' : 'Total orders' }}</h3>
              <p class="stat-value">{{ displayNumber.format(report.total_orders) }}</p>
            </div>
          </template>
        </div>
      </section>

      <div class="dashboard-side">
        <section class="dashboard-section" aria-labelledby="recent-orders-heading">
          <h2 id="recent-orders-heading">Recent orders</h2>
          <DashboardSectionState
            v-if="!orders.data"
            :loading="orders.loading"
            :error="orders.error ? userMessage(orders.error) : ''"
            label="recent orders"
            @retry="orders.refresh"
          />
          <template v-else-if="orders.data">
            <PosTableRegion v-if="orders.data.data.length" class="dashboard-table" aria-labelledby="recent-orders-heading">
              <table class="data-table">
                <thead>
                  <tr><th scope="col">Order</th><th scope="col">Time</th><th scope="col" class="number-cell">Total</th></tr>
                </thead>
                <tbody>
                  <tr v-for="order in orders.data.data" :key="order.id">
                    <th scope="row">
                      <RouterLink class="record-link" :to="{ name: 'receipt', params: { id: order.id } }"
                        :aria-label="'Order #' + order.id + ', receipt ' + order.receipt_no">#{{ order.id }}</RouterLink>
                    </th>
                    <td><time :datetime="order.created_at">{{ order.local_time }}</time></td>
                    <td class="number-cell font-semibold">{{ money(order.total) }}</td>
                  </tr>
                </tbody>
              </table>
            </PosTableRegion>
            <p v-else class="dashboard-empty">Completed orders will appear here.</p>
          </template>
          <RouterLink class="dashboard-link" :to="{ name: 'owner-orders' }"
            >View orders <ChevronRight :size="16" aria-hidden="true"
          /></RouterLink>
        </section>

        <section class="dashboard-section" aria-labelledby="low-stock-heading">
          <h2 id="low-stock-heading">Low stock alerts</h2>
          <DashboardSectionState
            v-if="!stock.data"
            :loading="stock.loading"
            :error="stock.error ? userMessage(stock.error) : ''"
            label="stock alerts"
            @retry="stock.refresh"
          />
          <template v-else-if="stock.data">
            <p v-if="stock.data.meta.total" class="dashboard-note">
              {{ displayNumber.format(stock.data.meta.total) }}
              {{ stock.data.meta.total === 1 ? 'product needs' : 'products need' }} restocking
            </p>
            <PosTableRegion v-if="stock.data.data.length" class="dashboard-table" aria-labelledby="low-stock-heading">
              <table class="data-table">
                <thead>
                  <tr><th scope="col">Product</th><th scope="col" class="number-cell">Stock</th></tr>
                </thead>
                <tbody>
                  <tr v-for="product in stock.data.data" :key="product.id">
                    <th scope="row">{{ product.name }}</th>
                    <td class="number-cell"><strong class="stock-level">{{
                      product.stock_status === 'out_of_stock' ? 'Out of stock' : product.stock + ' left'
                    }}</strong></td>
                  </tr>
                </tbody>
              </table>
            </PosTableRegion>
            <p v-else class="dashboard-empty">
              All active products are above the low-stock threshold.
            </p>
          </template>
          <RouterLink class="dashboard-link" :to="{ name: 'owner-products' }"
            >View products <ChevronRight :size="16" aria-hidden="true"
          /></RouterLink>
        </section>
      </div>
    </div>

    <PosToast :message="targetNotice" @dismiss="targetNotice = ''" />
    <PosDialog ref="targetDialog" title="Monthly sales target">
      <form class="grid gap-3" @submit.prevent="saveTarget">
        <p v-if="goal" class="dashboard-note">{{ goal.label }} · All categories</p>
        <label class="dialog-field" for="sales-target-amount">
          Target amount (₱)
          <input
            id="sales-target-amount"
            v-model="targetAmount"
            type="number"
            inputmode="decimal"
            min="0.01"
            max="9999999999.99"
            step="0.01"
            required
          />
        </label>
        <p v-if="targetError" class="screen-error" role="alert">{{ targetError }}</p>
        <button
          class="dialog-action dialog-action--primary justify-self-start"
          type="submit"
          :disabled="savingTarget"
        >
          {{ savingTarget ? 'Saving…' : 'Save target' }}
        </button>
      </form>
    </PosDialog>
  </div>
</template>

<style scoped>
.owner-dashboard {
  display: grid;
  gap: clamp(0.875rem, 2vw, 1.375rem);
}

.dashboard-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.9rem 1.5rem;
  padding-block-end: 0.375rem;
}

.dashboard-filters {
  display: flex;
  flex: none;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.55rem;
  min-width: 0;
  width: max-content;
  max-width: 100%;
}

.period-options {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  width: fit-content;
  padding: 0.25rem;
  border: 1px solid var(--owner-line);
  border-radius: 0.7rem;
  background: #fff;
}

.period-options button,
.category-filter select {
  min-height: 2.75rem;
  padding-inline: clamp(0.52rem, 1vw, 0.9rem);
  border-radius: 0.5rem;
  font-size: 0.9375rem;
  font-weight: 620;
  white-space: nowrap;
}

.period-options button[aria-pressed='true'] {
  background: var(--owner-deep);
  color: #fff;
}

.period-options button[aria-pressed='false']:hover,
.dashboard-link:hover {
  background: #f1f8f4;
}

.period-options button:active,
.dashboard-link:active {
  transform: translateY(1px);
}

.category-filter {
  position: relative;
  min-width: min(100%, 10.5rem);
  max-width: min(100%, 17rem);
}

.category-label {
  position: absolute;
  inset-block-start: 0.32rem;
  inset-inline-start: 0.9rem;
  color: var(--owner-muted);
  font-size: 0.68rem;
  line-height: 1;
}

.category-filter select {
  width: 100%;
  min-height: 3.375rem;
  padding-block-start: 0.7rem;
  padding-inline-start: 0.9rem;
  border: 1px solid var(--owner-line);
  border-radius: 0.7rem;
  background: #fff;
  color: var(--owner-ink);
  text-overflow: ellipsis;
}

.dashboard-section,
.overview-card {
  min-width: 0;
}

.dashboard-section {
  padding-block-start: 1rem;
  border-block-start: 1px solid var(--owner-line);
}

.dashboard-section h2 {
  font-size: clamp(1.05rem, 1.55vw, 1.32rem);
  font-weight: 720;
  line-height: 1.25;
}

.finance-groups {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr));
  gap: 1rem clamp(1rem, 3vw, 2rem);
}

.finance-groups h3 {
  margin-block-end: 0.375rem;
  font-weight: 650;
}

.finance-groups dl div {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.125rem 0.5rem;
  padding-block: 0.375rem;
  border-block-start: 1px solid var(--owner-line);
}

.finance-groups dd {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.overview-card {
  display: grid;
  gap: 1rem;
  align-items: start;
  padding: clamp(1rem, 2vw, 1.55rem);
  border-radius: 0.625rem;
  background: #f0f7f2;
}

.overview-card > *,
.dashboard-columns > *,
.performance-stats > * {
  min-width: 0;
}

.overview-card h2,
.overview-averages dt {
  font-size: 1rem;
  font-weight: 630;
}

.featured-total,
.overview-averages dd,
.stat-value {
  overflow-wrap: anywhere;
  color: var(--owner-ink);
  font-variant-numeric: tabular-nums;
  font-weight: 680;
  letter-spacing: -0.035em;
  line-height: 1.13;
}

.featured-total {
  margin-block: 0.45rem 0.6rem;
  color: var(--owner-deep);
  font-size: clamp(1.95rem, 3.4vw, 3.15rem);
  font-weight: 780;
}

.overview-averages {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
}

.overview-averages dd,
.stat-value {
  margin-block: 0.3rem;
  font-size: clamp(1.35rem, 2.45vw, 2.15rem);
}

.dashboard-note,
.dashboard-empty {
  color: var(--owner-muted);
  font-size: 0.875rem;
  line-height: 1.45;
}

.dashboard-note + .dashboard-note {
  margin-block-start: 0.45rem;
}

.dashboard-empty {
  padding-block: 0.7rem;
}

.dashboard-columns,
.dashboard-side {
  display: grid;
  gap: clamp(0.875rem, 2vw, 1.375rem);
  align-items: start;
}

.performance-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  column-gap: 0.75rem;
  margin-block-end: 0.65rem;
}

.dashboard-link {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  width: fit-content;
  min-height: 2.75rem;
  border-radius: 0.35rem;
  color: var(--owner-deep);
  font-size: 0.875rem;
  font-weight: 690;
  text-align: start;
}

.dashboard-link:hover {
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.performance-chart {
  --chart-height: clamp(8rem, 16vw, 13rem);
}

.overview-trend > .dashboard-note,
.performance-chart > .dashboard-note {
  margin-block: 0.35rem 0.6rem;
}

.performance-stats,
.retail-metrics dl {
  display: grid;
  gap: 0.9rem;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 9.25rem), 1fr));
  border-block-start: 1px solid var(--owner-line);
  padding-block-start: 0.95rem;
  margin-block-start: 0.65rem;
}

.performance-stats h3,
.retail-metrics dt {
  font-size: 0.9rem;
  font-weight: 640;
}

.retail-metrics dd {
  margin-block-start: 0.2rem;
  font-weight: 680;
  overflow-wrap: anywhere;
}

.sales-target progress {
  display: block;
  width: 100%;
  height: 0.48rem;
  margin-block: 0.6rem 0.45rem;
  appearance: none;
  border: 0;
  border-radius: 0.25rem;
  background: var(--owner-pale);
  accent-color: var(--owner-green);
}

.sales-target progress::-webkit-progress-bar {
  border-radius: inherit;
  background: var(--owner-pale);
}

.sales-target progress::-webkit-progress-value {
  border-radius: inherit;
  background: var(--owner-green);
}

.sales-target progress::-moz-progress-bar {
  border-radius: inherit;
  background: var(--owner-green);
}

.dashboard-side h2 + .dashboard-note,
.dashboard-table {
  margin-block-start: 0.5rem;
}

.dashboard-table :is(th, td) {
  padding-block: 0.375rem;
}

.stock-level {
  color: #9c1d25;
  font-size: 0.8rem;
}

@media (max-width: 34rem) {
  .dashboard-filters,
  .category-filter {
    width: 100%;
    max-width: 100%;
  }
}

@media (min-width: 42rem) {
  .dashboard-filters {
    display: grid;
    grid-template-columns: max-content minmax(10.5rem, 12.75rem);
    width: auto;
  }

  .overview-card {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
  }

  .overview-averages {
    grid-column: 1 / -1;
  }
}

@media (min-width: 57rem) {
  .dashboard-columns {
    grid-template-columns: minmax(0, 1.75fr) minmax(0, 1fr);
  }
}

@media (min-width: 73rem) {
  .overview-card {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr) minmax(0, 0.64fr);
    column-gap: clamp(1rem, 2.4vw, 2rem);
  }

  .overview-averages {
    grid-column: auto;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.4rem;
  }
}
</style>
