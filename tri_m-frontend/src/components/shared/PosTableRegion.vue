<template>
  <div class="table-scroll table-region" role="region" tabindex="0">
    <slot />
  </div>
</template>

<style scoped>
.table-region {
  border: 1px solid var(--pos-line);
  border-radius: 0.625rem;
  background: var(--pos-paper);
}
</style>
