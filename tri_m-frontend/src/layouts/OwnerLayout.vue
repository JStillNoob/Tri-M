<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  FileSpreadsheet,
  House,
  LayoutGrid,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  ReceiptText,
  ShoppingBag,
  X,
} from '@lucide/vue'
import storeLogo from '@/assets/tri-m-minimart-logo.png'
import { useSessionStore } from '@/stores/sessionStore'
import { userMessage } from '@/utils/userFeedback'

const router = useRouter()
const session = useSessionStore()
const signOutError = ref('')
const signingOut = ref(false)
const menuOpen = ref(false)
const menuButton = ref(null)
const links = [
  { name: 'owner-dashboard', label: 'Dashboard', icon: House },
  { name: 'owner-products', label: 'Products', icon: ShoppingBag },
  { name: 'owner-categories', label: 'Categories', icon: LayoutGrid },
  { name: 'owner-import', label: 'Excel Stock-in', icon: FileSpreadsheet },
  { name: 'owner-orders', label: 'Orders', icon: ReceiptText },
]

async function signOut() {
  signingOut.value = true
  signOutError.value = ''
  try {
    await session.signOut()
    await router.replace({ name: 'login' })
  } catch (problem) {
    signOutError.value = userMessage(problem, 'We could not sign you out. Try again.')
  } finally {
    signingOut.value = false
  }
}

function closeMenu() {
  if (!menuOpen.value) return
  menuOpen.value = false
  menuButton.value?.focus()
}
</script>

<template>
  <div class="owner-workspace" :class="{ 'owner-workspace--expanded': menuOpen }">
    <aside
      class="owner-sidebar"
      :class="{ 'owner-sidebar--expanded': menuOpen }"
      @keydown.esc="closeMenu"
    >
      <header class="owner-brand">
        <img
          :src="storeLogo"
          width="2172"
          height="724"
          alt="TRI-M Mini-Mart"
          fetchpriority="high"
        />
        <button
          ref="menuButton"
          class="owner-menu-toggle"
          type="button"
          :aria-expanded="menuOpen"
          aria-controls="owner-navigation"
          :aria-label="menuOpen ? 'Collapse navigation menu' : 'Expand navigation menu'"
          @click="menuOpen = !menuOpen"
        >
          <component
            :is="menuOpen ? X : Menu"
            class="mobile-menu-icon"
            :size="19"
            aria-hidden="true"
          />
          <component
            :is="menuOpen ? PanelLeftClose : PanelLeftOpen"
            class="tablet-menu-icon"
            :size="19"
            aria-hidden="true"
          />
          <span class="mobile-menu-label">Menu</span>
        </button>
      </header>
      <div id="owner-navigation" class="owner-navigation">
        <nav aria-label="Owner navigation">
          <ul class="owner-nav" role="list">
            <li v-for="link in links" :key="link.name">
              <RouterLink
                :to="{ name: link.name }"
                :aria-label="link.label"
                :title="link.label"
                :class="{ 'owner-link--active': link.name === 'owner-import' && $route.name === 'owner-stock-purchases' }"
                exact-active-class="owner-link--active"
                @click="closeMenu"
              >
                <component :is="link.icon" :size="20" aria-hidden="true" />
                <span class="owner-nav-label">{{ link.label }}</span>
              </RouterLink>
            </li>
          </ul>
        </nav>
        <button
          class="owner-signout"
          type="button"
          :disabled="signingOut"
          aria-label="Sign out"
          title="Sign out"
          @click="signOut"
        >
          <span class="owner-nav-label">{{ signingOut ? 'Signing out…' : 'Sign out' }}</span>
          <LogOut :size="19" aria-hidden="true" />
        </button>
        <p v-if="signOutError" class="screen-error" role="alert">{{ signOutError }}</p>
      </div>
    </aside>
    <main
      class="owner-content"
      :class="{
        'owner-content--dashboard': $route.name === 'owner-dashboard',
        'owner-content--wide': ['owner-products', 'owner-import', 'owner-stock-purchases'].includes($route.name),
      }"
    >
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.owner-workspace {
  --owner-green: #15803d;
  --owner-deep: #145d33;
  --owner-muted: #526158;
  --owner-pale: #e5f3e9;
  --owner-line: #dce7df;
  --owner-ink: #18241d;
  --pos-green: var(--owner-green);
  --pos-green-deep: var(--owner-deep);
  --pos-ink: var(--owner-ink);
  --pos-muted: var(--owner-muted);
  --pos-line: var(--owner-line);
  min-height: 100dvh;
  color: var(--owner-ink);
  background: #fbfdfb;
  font-family: Inter, 'Segoe UI Variable Text', 'Segoe UI', system-ui, sans-serif;
}

.owner-sidebar {
  display: grid;
  gap: 0.5rem;
  padding: max(0.625rem, env(safe-area-inset-top)) var(--pos-gutter) 0.625rem;
  border-block-end: 1px solid var(--owner-line);
  background: #fff;
}

.owner-brand {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.25rem 0.75rem;
}

.owner-brand img {
  display: block;
  width: min(11rem, 100%);
  height: auto;
  object-fit: contain;
}

.owner-navigation {
  display: none;
}

.owner-sidebar--expanded .owner-navigation {
  display: grid;
  gap: 0.75rem;
}

.owner-nav {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.owner-nav li {
  min-inline-size: 0;
}

.owner-nav a,
.owner-menu-toggle,
.owner-signout {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-height: 2.75rem;
  padding: 0.45rem 0.75rem;
  border-radius: 0.6rem;
  color: inherit;
  font-weight: 650;
  transition:
    background-color 160ms ease,
    color 160ms ease,
    transform 160ms ease;
}

.owner-nav a svg,
.owner-signout svg {
  flex: none;
}

.tablet-menu-icon {
  display: none;
}

.owner-nav a:hover,
.owner-menu-toggle:hover,
.owner-signout:hover:not(:disabled) {
  background: #f0f7f2;
}

.owner-nav a:active,
.owner-menu-toggle:active,
.owner-signout:active:not(:disabled) {
  transform: translateY(1px);
}

.owner-nav .owner-link--active {
  background: var(--owner-pale);
  color: var(--owner-deep);
  font-weight: 750;
}

.owner-menu-toggle,
.owner-signout {
  border: 1px solid var(--owner-line);
  background: #fff;
}

.owner-signout {
  justify-content: space-between;
  text-align: start;
}

.owner-signout svg {
  color: #b42328;
}

.owner-content {
  min-width: 0;
  width: min(100%, 66rem);
  margin-inline: auto;
  padding: clamp(1.25rem, 4vw, 2.75rem) var(--pos-gutter) 3rem;
}

.owner-content--dashboard {
  width: min(100%, 94rem);
  padding: clamp(1.25rem, 3vw, 2.75rem) clamp(var(--pos-gutter), 3.5vw, 3.25rem) 3rem;
}

.owner-content--wide {
  width: min(100%, 91rem);
  padding-inline: clamp(var(--pos-gutter), 3.1vw, 3.25rem);
}

@media (min-width: 48rem) {
  .owner-workspace {
    display: grid;
    grid-template-columns: 4.75rem minmax(0, 1fr);
  }

  .owner-workspace--expanded {
    grid-template-columns: 13.5rem minmax(0, 1fr);
  }

  .owner-sidebar {
    position: sticky;
    top: 0;
    grid-template-rows: auto minmax(0, 1fr);
    gap: 1.5rem;
    height: 100dvh;
    padding: 1rem 0.75rem;
    border-block-end: 0;
    border-inline-end: 1px solid var(--owner-line);
    overflow-y: auto;
  }

  .owner-brand {
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
  }

  .owner-menu-toggle {
    justify-content: center;
  }

  .mobile-menu-icon,
  .mobile-menu-label {
    display: none;
  }

  .tablet-menu-icon {
    display: block;
  }

  .owner-navigation,
  .owner-sidebar--expanded .owner-navigation {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .owner-signout {
    margin-block-start: auto;
  }
}

@media (48rem <= width < 69rem) {
  .owner-sidebar:not(.owner-sidebar--expanded) .owner-nav-label {
    display: none;
  }

  .owner-sidebar:not(.owner-sidebar--expanded) :is(.owner-nav a, .owner-signout) {
    justify-content: center;
    padding-inline: 0.5rem;
  }
}

@media (min-width: 69rem) {
  .owner-workspace,
  .owner-workspace--expanded {
    grid-template-columns: clamp(13.5rem, 17vw, 16rem) minmax(0, 1fr);
  }

  .owner-sidebar {
    padding: 1.5rem 1rem 1rem;
    gap: 2rem;
  }

  .owner-brand img {
    width: min(12rem, 100%);
    margin-inline: auto;
  }

  .owner-menu-toggle {
    display: none;
  }
}
</style>
