import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// Layouts
import BuyerLayout from '@/layouts/BuyerLayout.vue'
import SellerLayout from '@/layouts/SellerLayout.vue'
import OpsLayout from '@/layouts/OpsLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'

// Auth Views - loaded eagerly since they don't require auth
import LoginView from '@/views/auth/LoginView.vue'
import VerifyOtpView from '@/views/auth/VerifyOtpView.vue'

// Buyer Views - lazy loaded
const BuyerHomeView = () => import('@/views/buyer/HomeView.vue')
const SearchView = () => import('@/views/buyer/SearchView.vue')
const CreateRequestView = () => import('@/views/buyer/CreateRequestView.vue')
const RequestDetailView = () => import('@/views/buyer/RequestDetailView.vue')
const GroupDetailView = () => import('@/views/buyer/GroupDetailView.vue')
const OfferDetailView = () => import('@/views/buyer/OfferDetailView.vue')
const CheckoutView = () => import('@/views/buyer/CheckoutView.vue')
const OrdersView = () => import('@/views/buyer/OrdersView.vue')
const OrderDetailView = () => import('@/views/buyer/OrderDetailView.vue')
const BuyerProfileView = () => import('@/views/buyer/ProfileView.vue')

// Seller Views - lazy loaded
const SellerDashboardView = () => import('@/views/seller/DashboardView.vue')
const RfqListView = () => import('@/views/seller/RfqListView.vue')
const BidFormView = () => import('@/views/seller/BidFormView.vue')
const SellerOrdersView = () => import('@/views/seller/OrdersView.vue')
const SettlementsView = () => import('@/views/seller/SettlementsView.vue')
const DealsView = () => import('@/views/seller/DealsView.vue')

// Ops Views - lazy loaded
const OpsDashboardView = () => import('@/views/ops/DashboardView.vue')
const ProcurementView = () => import('@/views/ops/ProcurementView.vue')
const CaseDetailView = () => import('@/views/ops/CaseDetailView.vue')
const OffersView = () => import('@/views/ops/OffersView.vue')
const FulfillmentView = () => import('@/views/ops/FulfillmentView.vue')
const DisputesView = () => import('@/views/ops/DisputesView.vue')
const FinanceView = () => import('@/views/ops/FinanceView.vue')
const AdminView = () => import('@/views/ops/AdminView.vue')

const routes: RouteRecordRaw[] = [
  // Auth Routes
  {
    path: '/login',
    component: AuthLayout,
    children: [
      {
        path: '',
        component: LoginView,
      },
    ],
  },
  {
    path: '/verify-otp',
    component: AuthLayout,
    children: [
      {
        path: '',
        component: VerifyOtpView,
      },
    ],
  },

  // Buyer Routes
  {
    path: '/',
    component: BuyerLayout,
    meta: { requiresAuth: true, role: 'buyer' },
    children: [
      {
        path: '',
        component: BuyerHomeView,
        meta: { title: 'Home' },
      },
      {
        path: 'search',
        component: SearchView,
        meta: { title: 'Search' },
      },
      {
        path: 'request/new',
        component: CreateRequestView,
        meta: { title: 'Create Request' },
      },
      {
        path: 'request/:id',
        component: RequestDetailView,
        meta: { title: 'Request Details' },
      },
      {
        path: 'group/:id',
        component: GroupDetailView,
        meta: { title: 'Group Details' },
      },
      {
        path: 'offer/:id',
        component: OfferDetailView,
        meta: { title: 'Offer Details' },
      },
      {
        path: 'checkout/:id',
        component: CheckoutView,
        meta: { title: 'Checkout' },
      },
      {
        path: 'orders',
        component: OrdersView,
        meta: { title: 'My Orders' },
      },
      {
        path: 'order/:id',
        component: OrderDetailView,
        meta: { title: 'Order Details' },
      },
      {
        path: 'profile',
        component: BuyerProfileView,
        meta: { title: 'Profile' },
      },
    ],
  },

  // Seller Routes
  {
    path: '/seller',
    component: SellerLayout,
    meta: { requiresAuth: true, role: 'seller' },
    children: [
      {
        path: 'dashboard',
        component: SellerDashboardView,
        meta: { title: 'Dashboard' },
      },
      {
        path: 'rfqs',
        component: RfqListView,
        meta: { title: 'RFQs' },
      },
      {
        path: 'rfq/:id/bid',
        component: BidFormView,
        meta: { title: 'Create Bid' },
      },
      {
        path: 'orders',
        component: SellerOrdersView,
        meta: { title: 'Orders' },
      },
      {
        path: 'settlements',
        component: SettlementsView,
        meta: { title: 'Settlements' },
      },
      {
        path: 'deals',
        component: DealsView,
        meta: { title: 'Deals' },
      },
    ],
  },

  // Ops Routes
  {
    path: '/ops',
    component: OpsLayout,
    meta: { requiresAuth: true, role: 'ops' },
    children: [
      {
        path: 'dashboard',
        component: OpsDashboardView,
        meta: { title: 'Dashboard' },
      },
      {
        path: 'procurement',
        component: ProcurementView,
        meta: { title: 'Procurement' },
      },
      {
        path: 'case/:id',
        component: CaseDetailView,
        meta: { title: 'Case Details' },
      },
      {
        path: 'offers',
        component: OffersView,
        meta: { title: 'Offers' },
      },
      {
        path: 'fulfillment',
        component: FulfillmentView,
        meta: { title: 'Fulfillment' },
      },
      {
        path: 'disputes',
        component: DisputesView,
        meta: { title: 'Disputes' },
      },
      {
        path: 'finance',
        component: FinanceView,
        meta: { title: 'Finance' },
      },
      {
        path: 'admin',
        component: AdminView,
        meta: { title: 'Administration' },
      },
    ],
  },

  // 404 Catch-all
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

// Navigation guard for auth and role-based access
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth)
  const requiredRole = to.matched.find((record) => record.meta.role)?.meta.role as string | undefined

  if (requiresAuth) {
    if (!authStore.isAuthenticated) {
      next({
        path: '/login',
        query: { redirect: to.fullPath },
      })
    } else if (requiredRole && authStore.user?.role !== requiredRole) {
      // User doesn't have the required role
      next({ path: '/' })
    } else {
      next()
    }
  } else {
    next()
  }
})

export default router
