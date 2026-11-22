<script setup>
import { ref, useId } from 'vue'
import PosIcon from './PosIcon.vue'

defineProps({
  title: { type: String, required: true },
})

const titleId = useId()
const dialog = ref(null)

function open() {
  dialog.value.showModal()
}

function close() {
  dialog.value.close()
}

defineExpose({ open, close })
</script>

<template>
  <dialog ref="dialog" :aria-labelledby="titleId" @click.self="close">
    <div class="dialog-surface">
      <header class="dialog-header">
        <h2 :id="titleId" class="section-title min-w-0 [overflow-wrap:anywhere]">{{ title }}</h2>
        <button class="icon-button" type="button" aria-label="Close dialog" @click="close">
          <PosIcon name="close" />
        </button>
      </header>
      <div class="dialog-body">
        <slot />
      </div>
    </div>
  </dialog>
</template>

<style scoped>
dialog {
  margin: auto;
  inline-size: min(calc(100% - 1.75rem), 27rem);
  max-block-size: calc(100dvh - 2rem);
  overflow: hidden;
  padding: 0;
  border: 1px solid var(--pos-line);
  border-radius: 1.25rem;
  background: var(--pos-paper);
  color: var(--pos-ink);
  box-shadow: 0 1rem 3rem #17291a26;
}

dialog::backdrop {
  background: #15231b66;
}

.dialog-surface {
  display: flex;
  flex-direction: column;
  max-block-size: calc(100dvh - 2rem - 2px);
}

.dialog-header {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.375rem 1rem 0.375rem 1.125rem;
  border-block-end: 1px solid var(--pos-line);
}

.dialog-body {
  display: grid;
  min-block-size: 0;
  overflow-y: auto;
  gap: 0.875rem;
  padding: 0.875rem 1.125rem 1.125rem;
}
</style>
