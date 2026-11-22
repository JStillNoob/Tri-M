<script setup>
defineProps({
  loading: Boolean,
  error: { type: String, default: '' },
  label: { type: String, required: true },
})
defineEmits(['retry'])
</script>

<template>
  <p v-if="loading" class="screen-note" role="status">Loading {{ label }}…</p>
  <div v-else-if="error" class="grid gap-2">
    <p class="screen-error" role="alert">{{ error }}</p>
    <button class="dialog-action justify-self-start" type="button" @click="$emit('retry')">
      Try again
    </button>
  </div>
</template>
