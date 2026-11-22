import { createRouter, createWebHistory } from 'vue-router'
import { handleSessionEnd } from '@/services/posApi'
import { handleOtherSessionChange } from '@/services/posReadCache'
import { useSessionStore } from '@/stores/sessionStore'
import LoginPage from '@/pages/auth/LoginPage.vue'
import SellPage from '@/pages/cashier/SellPage.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from) {
    if (to.path !== from.path) return { top: 0 }
  },
  routes: [
    { path: '/', redirect: { name: 'sell' } },
    { path: '/login', name: 'login', component: LoginPage, meta: { title: 'Log in' } },
    { path: '/sell', name: 'sell', component: SellPage, meta: { role: 'cashier', title: 'Sell' } },
    {
      path: '/basket',
      name: 'basket',
      component: () => import('@/pages/cashier/BasketPage.vue'),
      meta: { role: 'cashier', title: 'Basket' },
    },
    {
      path: '/payment',
      name: 'payment',
      component: () => import('@/pages/cashier/PaymentPage.vue'),
      meta: { role: 'cashier', title: 'Payment' },
    },
    {
      path: '/receipts/:id',
      name: 'receipt',
      component: () => import('@/pages/cashier/ReceiptPage.vue'),
      meta: { auth: true, title: 'Receipt' },
    },
    {
      path: '/orders',
      name: 'orders',
      component: () => import('@/pages/cashier/OrdersPage.vue'),
      meta: { role: 'cashier', title: 'Orders' },
    },
    {
      path: '/owner',
      component: () => import('@/layouts/OwnerLayout.vue'),
      meta: { role: 'owner' },
      children: [
        {
          path: '',
          name: 'owner-dashboard',
          component: () => import('@/pages/owner/DashboardPage.vue'),
          meta: { title: 'Dashboard' },
        },
        {
          path: 'products',
          name: 'owner-products',
          component: () => import('@/pages/owner/ProductsPage.vue'),
          meta: { title: 'Products' },
        },
        {
          path: 'categories',
          name: 'owner-categories',
          component: () => import('@/pages/owner/CategoriesPage.vue'),
          meta: { title: 'Categories' },
        },
        {
          path: 'import',
          name: 'owner-import',
          component: () => import('@/pages/owner/ImportPage.vue'),
          meta: { title: 'Excel stock-in' },
        },
        {
          path: 'stock-purchases',
          name: 'owner-stock-purchases',
          component: () => import('@/pages/owner/StockPurchasesPage.vue'),
          meta: { title: 'Previous purchases' },
        },
        {
          path: 'orders',
          name: 'owner-orders',
          component: () => import('@/pages/cashier/OrdersPage.vue'),
          meta: { title: 'Orders' },
        },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: { name: 'sell' } },
  ],
})

router.beforeEach(async (to) => {
  const session = useSessionStore()
  await session.restore()
  const { account } = session
  if (!account && (to.meta.role || to.meta.auth)) return { name: 'login' }

  if (account && (to.name === 'login' || (to.meta.role && to.meta.role !== account.role))) {
    return { name: account.role === 'owner' ? 'owner-dashboard' : 'sell' }
  }
})

router.afterEach((to) => {
  document.title = (to.meta.title || 'POS') + ' | TRI-M Mini-Mart'
})

function clearSession(unexpected = false) {
  const session = useSessionStore()
  session.expire(unexpected)
  if (router.currentRoute.value.name !== 'login') void router.replace({ name: 'login' })
}

handleSessionEnd(() => clearSession(true))
handleOtherSessionChange(() => clearSession())

export default router
