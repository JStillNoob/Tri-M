<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { revalidateActiveReads } from '@/services/posReadCache'

function refreshVisibleReads() {
  if (document.visibilityState === 'visible') revalidateActiveReads()
}

onMounted(() => document.addEventListener('visibilitychange', refreshVisibleReads))
onBeforeUnmount(() => document.removeEventListener('visibilitychange', refreshVisibleReads))
</script>

<template>
  <RouterView />
</template>
