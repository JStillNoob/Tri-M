<script setup>
import { computed } from 'vue'
import { formatPrice } from '@/utils/price'
import { toCentavos } from '@/services/posApi'

const props = defineProps({
  points: { type: Array, required: true },
  cumulative: Boolean,
})

const width = 600
const height = 180
const currency = new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 1 })
const values = computed(() =>
  props.points.map((point) => toCentavos(props.cumulative ? point.cumulative_sales : point.sales)),
)

const step = computed(() => {
  const interval = Math.max(...values.value, 400) / 4
  const scale = 10 ** Math.floor(Math.log10(interval))
  return [1, 2, 2.5, 5, 10].find((value) => value * scale >= interval) * scale
})

const maximum = computed(() => step.value * 4)
const coordinates = computed(() =>
  values.value.map((value, index) => ({
    x: values.value.length === 1 ? width / 2 : (width * index) / (values.value.length - 1),
    y: height - 4 - (value / maximum.value) * (height - 7),
  })),
)
const line = computed(() => coordinates.value.map(({ x, y }) => x + ',' + y).join(' '))
const area = computed(() => {
  const first = coordinates.value[0]
  const last = coordinates.value.at(-1)
  return first && first !== last
    ? first.x + ',' + height + ' ' + line.value + ' ' + last.x + ',' + height
    : ''
})
const labels = computed(() => {
  const size = Math.min(5, props.points.length)
  if (size === 0) return []
  if (size === 1) return [{ label: props.points[0].label, position: 50 }]
  const last = props.points.length - 1
  return Array.from({ length: size }, (_, tick) => {
    const index = Math.round((tick * last) / (size - 1))
    return {
      label: props.points[index].label,
      position: (index / last) * 100,
      secondary: size > 3 && tick !== 0 && tick !== size - 1 && tick !== Math.floor(size / 2),
    }
  })
})
</script>

<template>
  <figure class="sales-figure">
    <div class="sales-chart" aria-hidden="true">
      <div class="amount-axis">
        <span v-for="level in [4, 3, 2, 1, 0]" :key="level"
          >₱{{ currency.format((level * step) / 100) }}</span
        >
      </div>
      <div class="plot">
        <svg :viewBox="'0 0 ' + width + ' ' + height" preserveAspectRatio="none" aria-hidden="true">
          <line
            v-for="level in [4, 3, 2, 1, 0]"
            :key="level"
            x1="0"
            :x2="width"
            :y1="height - 4 - (level * (height - 7)) / 4"
            :y2="height - 4 - (level * (height - 7)) / 4"
            class="gridline"
            vector-effect="non-scaling-stroke"
          />
          <polygon v-if="area" :points="area" class="area" />
          <polyline
            v-if="coordinates.length > 1"
            :points="line"
            class="series"
            vector-effect="non-scaling-stroke"
          />
          <path
            v-else-if="coordinates.length"
            :d="'M ' + (coordinates[0].x - 1) + ' ' + coordinates[0].y + ' h 2'"
            class="series single-point"
            vector-effect="non-scaling-stroke"
          />
        </svg>
        <div class="time-axis">
          <span
            v-for="(tick, index) in labels"
            :key="index"
            :class="{
              'start-label': tick.position === 0,
              'end-label': tick.position === 100,
              'secondary-label': tick.secondary,
            }"
            :style="{ left: tick.position + '%' }"
            >{{ tick.label }}</span
          >
        </div>
      </div>
    </div>
    <figcaption class="sr-only">
      {{ cumulative ? 'Cumulative sales' : 'Sales by interval' }}. {{ points.length }}
      {{ points.length === 1 ? 'interval' : 'intervals' }}, ending at {{ points.at(-1)?.label }}.
    </figcaption>
    <details v-if="!cumulative" class="chart-values">
      <summary>View chart values</summary>
      <div class="table-scroll" role="region" aria-label="Sales chart values" tabindex="0">
        <table>
          <thead>
            <tr>
              <th scope="col">Interval</th>
              <th scope="col">Sales</th>
              <th scope="col">Orders</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(point, index) in points" :key="point.start">
              <th scope="row">{{ point.label }}</th>
              <td>{{ formatPrice(values[index]) }}</td>
              <td>{{ point.orders }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>
  </figure>
</template>

<style scoped>
.sales-chart {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.625rem;
  padding-block-start: 0.25rem;
}

.amount-axis {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: var(--chart-height, 9rem);
  text-align: end;
}

.amount-axis,
.time-axis {
  color: var(--owner-muted);
  font-size: 0.72rem;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

.plot svg {
  display: block;
  width: 100%;
  height: var(--chart-height, 9rem);
  overflow: visible;
}

.gridline {
  stroke: #e4ece6;
}

.area {
  fill: var(--owner-pale);
}

.series {
  fill: none;
  stroke: var(--owner-green);
  stroke-width: 2.2;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.single-point {
  stroke-width: 5;
}

.time-axis {
  position: relative;
  height: 1.6rem;
  margin-block-start: 0.45rem;
}

.time-axis span {
  position: absolute;
  white-space: nowrap;
  transform: translateX(-50%);
}

.time-axis .start-label {
  transform: none;
}

.time-axis .end-label {
  transform: translateX(-100%);
}

.chart-values {
  color: var(--owner-muted);
  font-size: 0.875rem;
}

.chart-values summary {
  display: list-item;
  min-height: 2.75rem;
  padding-block: 0.68rem;
  width: fit-content;
  color: var(--owner-deep);
  text-decoration: underline;
  text-underline-offset: 0.18em;
}

.chart-values table {
  width: 100%;
  font-variant-numeric: tabular-nums;
}

.chart-values th,
.chart-values td {
  border-bottom: 1px solid var(--owner-line);
  padding: 0.4rem 0.25rem;
  text-align: end;
  white-space: nowrap;
}

.chart-values th:first-child {
  text-align: start;
}

@media (max-width: 34rem) {
  .secondary-label {
    display: none;
  }
}
</style>
