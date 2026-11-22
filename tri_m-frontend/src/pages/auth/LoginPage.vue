<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import storeLogo from '@/assets/tri-m-minimart-logo.png'
import PosIcon from '@/components/shared/PosIcon.vue'
import PosFieldError from '@/components/shared/PosFieldError.vue'
import POSLayout from '@/layouts/POSLayout.vue'
import { useSessionStore } from '@/stores/sessionStore'
import { useFormFeedback } from '@/utils/userFeedback'

const router = useRouter()
const session = useSessionStore()
const email = ref('')
const password = ref('')
const passwordVisible = ref(false)
const submitting = ref(false)
const feedback = useFormFeedback(['email', 'password'])
const { alert: error } = feedback

async function submit() {
  if (submitting.value) return

  feedback.clear()
  submitting.value = true

  try {
    await session.signIn(email.value, password.value)
    await router.replace({ name: session.account.role === 'owner' ? 'owner-dashboard' : 'sell' })
  } catch (problem) {
    feedback.show(problem, 'We could not sign you in. Reload the page and try again.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <POSLayout>
    <section class="login-panel" aria-labelledby="login-title">
      <header class="grid gap-3">
        <div class="login-brand">
          <img
            :src="storeLogo"
            width="2172"
            height="724"
            alt="TRI-M Mini-Mart"
            fetchpriority="high"
          />
        </div>
        <h1 id="login-title" class="screen-title">Log in</h1>
      </header>

      <form class="grid gap-4" :aria-busy="submitting" @submit.prevent="submit" @input="feedback.clear()">
        <div class="dialog-field">
          <label for="login-email">Email</label>
          <input id="login-email" v-model="email" type="email" autocomplete="username" required v-bind="feedback.bind('email')" />
          <PosFieldError :id="feedback.fieldId('email')" :message="feedback.inline('email')" />
        </div>
        <div class="dialog-field">
          <label for="login-password">Password</label>
          <div class="password-field">
            <input
              id="login-password"
              v-model="password"
              :type="passwordVisible ? 'text' : 'password'"
              autocomplete="current-password"
              v-bind="feedback.bind('password')"
              required
            />
            <button
              class="icon-button"
              type="button"
              aria-controls="login-password"
              :aria-label="passwordVisible ? 'Hide password' : 'Show password'"
              @click="passwordVisible = !passwordVisible"
            >
              <PosIcon :name="passwordVisible ? 'eye-closed' : 'eye'" />
            </button>
          </div>
          <PosFieldError :id="feedback.fieldId('password')" :message="feedback.inline('password')" />
        </div>
        <p v-if="error || session.sessionMessage" class="screen-error" role="alert">{{ error || session.sessionMessage }}</p>
        <button
          class="dialog-action dialog-action--primary"
          type="submit"
          :disabled="submitting"
        >
          {{ submitting ? 'Logging in…' : 'Log in' }}
        </button>
      </form>
    </section>
  </POSLayout>
</template>

<style scoped>
.login-panel {
  display: grid;
  gap: 1.25rem;
  width: min(100%, 24rem);
  margin: clamp(0.5rem, 5vh, 3rem) auto 0;
}

.login-brand {
  position: relative;
  width: min(100%, 20rem);
  aspect-ratio: 4.15;
  margin-inline: auto;
  overflow: clip;
  mix-blend-mode: multiply;
}

.login-brand img {
  position: absolute;
  inset: 50% 0 auto;
  width: 100%;
  height: auto;
  transform: translateY(-50%);
}

.password-field {
  position: relative;
}

.password-field input {
  padding-inline-end: 3rem;
}

.password-field .icon-button {
  position: absolute;
  inset-block: 0;
  inset-inline-end: 0;
  border-radius: 0.625rem;
  color: var(--pos-muted);
}
</style>
