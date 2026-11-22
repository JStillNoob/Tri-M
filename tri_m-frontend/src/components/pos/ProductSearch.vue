<script setup>
import { ref } from 'vue'
import PosIcon from '@/components/shared/PosIcon.vue'

defineProps({
  hasFilters: { type: Boolean, default: false },
})
defineEmits(['filters'])

const query = defineModel({ type: String, required: true })
const searchInput = ref(null)

function clearSearch() {
  query.value = ''
  searchInput.value.focus()
}
</script>

<template>
  <div class="dialog-field" role="search" aria-label="Product catalog">
    <label for="product-search">Search products</label>
    <div class="flex items-stretch gap-2.5">
      <div class="search-box">
        <PosIcon name="search" aria-hidden="true" />
        <input
          id="product-search"
          ref="searchInput"
          v-model="query"
          type="search"
          placeholder="Product name"
          autocomplete="off"
        />
        <button
          v-if="query"
          class="icon-button"
          type="button"
          aria-label="Clear search"
          @click="clearSearch"
        >
          <PosIcon name="close" />
        </button>
      </div>
      <button
        class="filter-button"
        :class="{ 'filter-button--active': hasFilters }"
        type="button"
        :aria-label="hasFilters ? 'Product filters, active' : 'Product filters'"
        aria-haspopup="dialog"
        @click="$emit('filters')"
      >
        <PosIcon name="filter" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.search-box {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 0.625rem;
  min-inline-size: 0;
  min-block-size: 3rem;
  padding-inline: 0.75rem 0.125rem;
  border: 1px solid #adb6b0;
  border-radius: 0.625rem;
  background: var(--pos-paper);
  color: var(--pos-muted);
}

.search-box:focus-within {
  outline: 2px solid var(--pos-green-deep);
  outline-offset: 2px;
}

.dialog-field .search-box input {
  display: block;
  flex: 1;
  min-inline-size: 0;
  min-height: 2.75rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--pos-ink);
  font-size: 1rem;
}

.search-box input:focus-visible {
  outline: none;
}

.search-box input::placeholder {
  color: var(--pos-muted);
}

.search-box input::-webkit-search-cancel-button {
  display: none;
}

.filter-button {
  display: grid;
  flex: 0 0 3rem;
  place-items: center;
  align-self: center;
  min-block-size: 3rem;
  border-radius: 0.625rem;
  background: var(--pos-ink);
  color: white;
}

.filter-button:hover {
  outline: 2px solid var(--pos-muted);
  outline-offset: 2px;
}

.filter-button--active {
  box-shadow: 0 0 0 3px var(--pos-green) inset;
}

.filter-button:active {
  transform: translateY(1px);
}

@container (max-width: 16rem) {
  .search-box {
    gap: 0.125rem;
    padding-inline: 0.5rem;
  }

  .search-box > .pos-icon {
    display: none;
  }

  .search-box input {
    min-height: 44px;
    padding-block-start: 0;
    font-size: 0.875rem;
  }

  .search-box .icon-button,
  .filter-button {
    inline-size: 48px;
    block-size: 48px;
    min-block-size: 48px;
  }

  .filter-button {
    flex-basis: 48px;
  }

  .filter-button > .pos-icon {
    inline-size: 24px;
    block-size: 24px;
  }
}
</style>
