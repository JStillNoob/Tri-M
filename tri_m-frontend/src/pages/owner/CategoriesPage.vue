<script setup>
import { computed, ref } from 'vue'
import PosDialog from '@/components/shared/PosDialog.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import PosTableRegion from '@/components/shared/PosTableRegion.vue'
import PosToast from '@/components/shared/PosToast.vue'
import { useCachedRead } from '@/services/posReadCache'
import { useCatalogStore } from '@/stores/catalogStore'
import { useFormFeedback, userMessage } from '@/utils/userFeedback'

const catalog = useCatalogStore()
const categoryDialog = ref(null)
const previousName = ref('')
const name = ref('')
const feedback = useFormFeedback(['name'])
const { alert: error } = feedback
const notice = ref('')
const pending = ref(false)
const counts = useCachedRead(() => ({ kind: 'categoryCounts', path: '/api/categories' }))
const categoryCounts = computed(
  () =>
    new Map(
      (counts.data.value?.data ?? []).map((category) => [category.name, category.products_count]),
    ),
)

function edit(category = '') {
  previousName.value = category
  name.value = category
  notice.value = ''
  feedback.clear()
  categoryDialog.value.open()
}

async function save() {
  if (pending.value) return
  pending.value = true
  feedback.clear()
  try {
    await catalog.saveCategory(name.value, previousName.value)
    notice.value = previousName.value ? 'Category renamed.' : 'Category added.'
    categoryDialog.value.close()
  } catch (problem) {
    feedback.show(problem, 'We could not save this category. Try again.')
  } finally {
    pending.value = false
  }
}

async function remove() {
  if (pending.value) return
  pending.value = true
  error.value = ''
  try {
    await catalog.removeCategory(previousName.value)
    notice.value = 'Category removed.'
    categoryDialog.value.close()
  } catch (problem) {
    error.value = userMessage(problem, 'We could not remove this category. Try again.')
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="owner-page">
    <header class="owner-heading">
      <div>
        <h1 class="screen-title">Categories</h1>
      </div>
      <button class="dialog-action dialog-action--primary" type="button" @click="edit()">
        Add category
      </button>
    </header>
    <PosToast :message="notice" @dismiss="notice = ''" />
    <p v-if="catalog.status === 'loading'" class="screen-note" role="status">Loading categories…</p>
    <div v-else-if="catalog.status === 'error'" class="grid gap-2">
      <p class="screen-error" role="alert">{{ userMessage(catalog.loadError, 'We could not load categories. Try again.') }}</p>
      <button class="dialog-action justify-self-start" type="button" @click="catalog.load()">
        Try again
      </button>
    </div>
    <p v-else-if="!catalog.categories.length" class="screen-note" role="status">
      Add a category to organize your products.
    </p>
    <PosTableRegion v-else aria-label="Categories">
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">Category</th>
            <th scope="col" class="number-cell">Products</th>
            <th scope="col" class="number-cell">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="category in catalog.categories" :key="category">
            <th scope="row" class="font-semibold">{{ category }}</th>
            <td class="number-cell">{{ categoryCounts.get(category) ?? '–' }}</td>
            <td class="number-cell">
              <button class="dialog-action" type="button" :aria-label="'Edit ' + category" @click="edit(category)">
                Edit
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </PosTableRegion>

    <PosDialog ref="categoryDialog" :title="previousName ? 'Edit category' : 'Add category'">
      <form class="grid gap-3" @submit.prevent="save">
        <div class="dialog-field">
          <label for="category-name">Category name</label>
          <input id="category-name" v-model.trim="name" maxlength="100" required v-bind="feedback.bind('name')" @input="feedback.clear()" />
          <PosFieldError :id="feedback.fieldId('name')" :message="feedback.inline('name')" />
        </div>
        <p v-if="previousName && categoryCounts.get(previousName)" class="screen-note">
          Move the assigned products to another category before removing this one.
        </p>
        <p v-if="error" class="screen-error" role="alert">{{ error }}</p>
        <div class="screen-actions">
          <button
            v-if="previousName"
            class="dialog-action"
            type="button"
            :disabled="pending || Boolean(categoryCounts.get(previousName))"
            @click="remove"
          >
            Remove category
          </button>
          <button class="dialog-action dialog-action--primary" type="submit" :disabled="pending">
            {{ previousName ? 'Save changes' : 'Save category' }}
          </button>
        </div>
      </form>
    </PosDialog>
  </div>
</template>

