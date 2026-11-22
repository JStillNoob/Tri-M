<template>
  <div class="pos-layout">
    <slot name="header" />
    <main class="pos-column pos-content">
      <slot />
    </main>
    <slot name="footer" />
  </div>
</template>

<style scoped>
.pos-layout {
  container-type: inline-size;
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  padding-block-start: max(1.25rem, env(safe-area-inset-top));
  background: var(--pos-paper);
}

.pos-content {
  flex: 1;
  padding-block: 1.25rem 2rem;
}

@media (min-width: 34rem) {
  .pos-layout {
    padding-block-start: 1.75rem;
  }

  .pos-content {
    padding-block-start: 1.75rem;
  }
}

@media (min-width: 58rem) {
  .pos-layout {
    padding-block-start: 2.5rem;
  }

  .pos-content {
    padding-block-start: 2rem;
  }
}
</style>
