<script setup>
import storeLogo from '@/assets/tri-m-minimart-logo.png'
import PosIcon from '@/components/shared/PosIcon.vue'

defineEmits(['menu', 'history'])
</script>

<template>
  <header class="pos-column pos-header">
    <button
      class="icon-button"
      type="button"
      aria-label="Menu"
      aria-haspopup="dialog"
      @click="$emit('menu')"
    >
      <PosIcon name="menu" />
    </button>
    <h1 class="brand">
      <img
        :src="storeLogo"
        width="2172"
        height="724"
        alt="TRI-M Mini-Mart. Your Go-To Shop!"
        fetchpriority="high"
      />
    </h1>
    <button class="icon-button" type="button" aria-label="Sales history" @click="$emit('history')">
      <PosIcon name="history" />
    </button>
  </header>
</template>

<style scoped>
.pos-header {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: clamp(0.125rem, 1vw, 0.75rem);
  align-items: center;
}

.brand {
  position: relative;
  inline-size: min(100%, 22rem);
  min-inline-size: 0;
  margin-inline: auto;
  overflow: clip;
  aspect-ratio: 4.15;
  mix-blend-mode: multiply;
}

.brand img {
  position: absolute;
  inset: 50% 0 auto;
  inline-size: 100%;
  block-size: auto;
  transform: translateY(-50%);
}

@container (max-width: 16rem) {
  .pos-header {
    grid-template-columns: 1fr 1fr;
    row-gap: 0.25rem;
  }

  .brand {
    grid-area: 1 / 1 / 2 / -1;
  }

  .pos-header > :last-child {
    justify-self: end;
  }
}
</style>
