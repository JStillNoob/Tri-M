import { ref } from 'vue'
import { defineStore } from 'pinia'
import { request } from '@/services/posApi'
import { announceSessionChange, setReadCacheAccount } from '@/services/posReadCache'
import { expiredSessionMessage, userMessage } from '@/utils/userFeedback'

export const useSessionStore = defineStore('session', () => {
  const account = ref(null)
  const sessionMessage = ref('')
  const sessionExpired = ref(false)
  let checked = false
  let loading
  let generation = 0

  async function restore() {
    if (checked) return
    if (!loading) {
      const version = generation
      loading = request('get', '/api/user')
        .then(({ data }) => {
          if (version !== generation) return
          setReadCacheAccount(data)
          account.value = data
          sessionMessage.value = ''
          checked = true
        })
        .catch((problem) => {
          if (version !== generation) return
          account.value = null
          checked = [401, 403, 419].includes(problem.status)
          if (checked) setReadCacheAccount(null)
          if (problem.status !== 401) {
            sessionMessage.value = userMessage(problem, 'We could not check your sign-in. Reload the page and try again.')
          }
        })
        .finally(() => {
          loading = null
        })
    }
    await loading
  }

  async function signIn(email, password) {
    sessionMessage.value = ''
    await request('get', '/sanctum/csrf-cookie')
    const { data } = await request('post', '/api/login', {
      data: { email: email.trim(), password },
    })
    generation++
    setReadCacheAccount(data)
    sessionExpired.value = false
    account.value = data
    checked = true
    announceSessionChange()
  }

  async function signOut() {
    try {
      await request('post', '/api/logout')
    } catch (problem) {
      if (problem.status === 419) {
        await request('get', '/sanctum/csrf-cookie')
        try {
          await request('post', '/api/logout')
        } catch (retry) {
          if (retry.status !== 401) throw retry
        }
      } else if (problem.status !== 401) {
        throw problem
      }
    }
    expire()
    announceSessionChange()
  }

  function expire(unexpected = false) {
    generation++
    sessionExpired.value = unexpected
    sessionMessage.value = unexpected ? expiredSessionMessage : ''
    setReadCacheAccount(null)
    account.value = null
    checked = true
  }

  return { account, sessionMessage, sessionExpired, restore, signIn, signOut, expire }
})
