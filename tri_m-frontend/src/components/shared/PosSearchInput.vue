<script setup>
import { Search } from '@lucide/vue'

defineProps({
  id: { type: String, required: true },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  maxlength: { type: Number, default: 100 },
})
const query = defineModel({ type: String, required: true })
</script>

<template>
  <div class="dialog-field">
    <label :for="id">{{ label }}</label>
    <div class="relative">
      <Search class="search-icon" :size="18" aria-hidden="true" />
      <input :id="id" v-model="query" class="search-input" type="search"
        :placeholder="placeholder" :maxlength="maxlength" autocomplete="off" />
    </div>
  </div>
</template>

<style scoped>
.search-icon {
  position: absolute;
  inset-inline-start: 0.75rem;
  inset-block-start: 50%;
  translate: 0 -50%;
  color: var(--pos-muted);
  pointer-events: none;
}

.dialog-field .search-input {
  padding-inline-start: 2.4rem;
}
</style>
