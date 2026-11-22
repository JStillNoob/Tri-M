<script setup>
import { X } from '@lucide/vue'

defineProps({ message: { type: String, required: true } })
defineEmits(['dismiss'])
</script>

<template>
  <div class="pos-toast" :class="{ 'pos-toast--visible': message }">
    <p role="status" aria-atomic="true">{{ message }}</p>
    <button
      v-if="message"
      class="icon-button"
      type="button"
      aria-label="Dismiss notification"
      @click="$emit('dismiss')"
    >
      <X :size="18" aria-hidden="true" />
    </button>
  </div>
</template>

<style scoped>
.pos-toast {
  position: fixed;
  z-index: 3;
  inset-inline-end: max(1rem, env(safe-area-inset-right));
  inset-block-end: max(1rem, env(safe-area-inset-bottom));
  max-inline-size: calc(100% - 2rem);
}

.pos-toast--visible {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.25rem 0.25rem 0.875rem;
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
  background: var(--pos-paper);
  box-shadow: 0 3px 12px #18241d18;
  color: var(--pos-green-deep);
  font-weight: 650;
}

.pos-toast p {
  overflow-wrap: anywhere;
}
</style>
