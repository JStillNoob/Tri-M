<script setup>
import {
  PhArrowLeft,
  PhCaretRight,
  PhClockCounterClockwise,
  PhEye,
  PhEyeSlash,
  PhFunnelSimple,
  PhList,
  PhMagnifyingGlass,
  PhMinus,
  PhPlus,
  PhShoppingCartSimple,
  PhX,
} from '@phosphor-icons/vue'

defineProps({
  name: { type: String, required: true },
})

const icons = {
  back: PhArrowLeft,
  cart: PhShoppingCartSimple,
  chevron: PhCaretRight,
  close: PhX,
  eye: PhEye,
  'eye-closed': PhEyeSlash,
  filter: PhFunnelSimple,
  history: PhClockCounterClockwise,
  menu: PhList,
  minus: PhMinus,
  plus: PhPlus,
  search: PhMagnifyingGlass,
}
</script>

<template>
  <component
    :is="icons[name]"
    class="pos-icon"
    :class="{ 'pos-icon--chevron': name === 'chevron' }"
    weight="bold"
    aria-hidden="true"
    focusable="false"
  />
</template>

<style scoped>
.pos-icon {
  inline-size: 1.5rem;
  block-size: 1.5rem;
  flex: none;
}

.pos-icon--chevron {
  inline-size: 0.875rem;
  block-size: 0.875rem;
}
</style>
