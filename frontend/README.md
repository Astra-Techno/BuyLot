# Collective Commerce - Frontend

Vue 3 + TypeScript + Vite + Pinia + Tailwind CSS frontend for the Collective Commerce B2B marketplace platform.

## Project Structure

```
frontend/
├── src/
│   ├── components/
│   │   └── common/              # Reusable UI components
│   │       ├── LoadingSpinner.vue
│   │       ├── EmptyState.vue
│   │       ├── ErrorState.vue
│   │       ├── PriceDisplay.vue
│   │       └── StatusBadge.vue
│   ├── layouts/                 # Layout components for different portals
│   │   ├── BuyerLayout.vue
│   │   ├── SellerLayout.vue
│   │   ├── OpsLayout.vue
│   │   └── AuthLayout.vue
│   ├── views/                   # Page components organized by portal
│   │   ├── auth/
│   │   │   ├── LoginView.vue
│   │   │   └── VerifyOtpView.vue
│   │   ├── buyer/
│   │   │   ├── HomeView.vue
│   │   │   ├── SearchView.vue
│   │   │   ├── CreateRequestView.vue
│   │   │   ├── RequestDetailView.vue
│   │   │   ├── GroupDetailView.vue
│   │   │   ├── OfferDetailView.vue
│   │   │   ├── CheckoutView.vue
│   │   │   ├── OrdersView.vue
│   │   │   ├── OrderDetailView.vue
│   │   │   └── ProfileView.vue
│   │   ├── seller/
│   │   │   ├── DashboardView.vue
│   │   │   ├── RfqListView.vue
│   │   │   ├── BidFormView.vue
│   │   │   ├── OrdersView.vue
│   │   │   ├── SettlementsView.vue
│   │   │   └── DealsView.vue
│   │   └── ops/
│   │       ├── DashboardView.vue
│   │       ├── ProcurementView.vue
│   │       ├── CaseDetailView.vue
│   │       ├── OffersView.vue
│   │       ├── FulfillmentView.vue
│   │       ├── DisputesView.vue
│   │       ├── FinanceView.vue
│   │       └── AdminView.vue
│   ├── api/
│   │   └── client.ts            # Axios instance with interceptors
│   ├── stores/                  # Pinia stores
│   │   ├── auth.ts
│   │   └── notifications.ts
│   ├── types/
│   │   └── index.ts             # TypeScript interfaces
│   ├── styles/
│   │   └── main.css             # Tailwind CSS entry point
│   ├── router/
│   │   └── index.ts             # Vue Router configuration
│   ├── App.vue
│   └── main.ts
├── index.html
├── vite.config.ts
├── tsconfig.json
├── tailwind.config.js
├── postcss.config.js
├── package.json
└── .gitignore
```

## Setup & Installation

1. Install dependencies:
```bash
npm install
```

2. Start development server:
```bash
npm run dev
```

3. Build for production:
```bash
npm run build
```

4. Preview production build:
```bash
npm run preview
```

## Configuration

### Environment Variables
Create a `.env.local` file in the root directory:
```
VITE_API_URL=http://localhost:8000/api
VITE_APP_NAME=Collective Commerce
```

### API Proxy
The Vite config automatically proxies `/api` requests to `http://localhost:8000`. Adjust in `vite.config.ts` if needed.

## Key Features

- **Three Portal Architecture**: Separate layouts and routes for Buyers, Sellers, and Operations teams
- **Role-based Access Control**: Navigation guards check user role before allowing access
- **Responsive Design**: Mobile-first approach with Tailwind CSS
- **Type Safety**: Full TypeScript support throughout the application
- **State Management**: Pinia stores for auth and notifications
- **API Integration**: Axios client with automatic token injection and error handling
- **Lazy Loading**: All routes use dynamic imports for better performance

## Portal Routes

### Buyer Portal (`/`)
- `/` - Home
- `/search` - Search & browse offers
- `/request/new` - Create buy request
- `/request/:id` - View request details
- `/group/:id` - View demand group
- `/offer/:id` - View offer
- `/checkout/:id` - Checkout
- `/orders` - My orders
- `/order/:id` - Order details
- `/profile` - User profile

### Seller Portal (`/seller`)
- `/seller/dashboard` - Dashboard
- `/seller/rfqs` - RFQ list
- `/seller/rfq/:id/bid` - Submit bid
- `/seller/orders` - Orders
- `/seller/settlements` - Payouts
- `/seller/deals` - Deals & promotions

### Operations Portal (`/ops`)
- `/ops/dashboard` - Dashboard
- `/ops/procurement` - Procurement management
- `/ops/case/:id` - Bid evaluation case
- `/ops/offers` - Active offers
- `/ops/fulfillment` - Shipment tracking
- `/ops/disputes` - Dispute management
- `/ops/finance` - Financial analytics
- `/ops/admin` - Administration

### Auth Routes
- `/login` - Login page
- `/verify-otp` - OTP verification

## Component Usage

### StatusBadge
```vue
<StatusBadge status="active" size="md" />
```

### PriceDisplay
```vue
<PriceDisplay :paise="500000" show-currency />
```

### LoadingSpinner
```vue
<LoadingSpinner label="Loading..." />
```

## Store Usage

### Auth Store
```typescript
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
authStore.login(email, password)
authStore.logout()
authStore.user // Current user
authStore.isAuthenticated // Check auth status
```

### Notification Store
```typescript
import { useNotificationStore } from '@/stores/notifications'

const notificationStore = useNotificationStore()
notificationStore.addSuccess('Title', 'Message')
notificationStore.addError('Title', 'Message')
notificationStore.addWarning('Title', 'Message')
notificationStore.addInfo('Title', 'Message')
```

## API Integration

The API client automatically:
- Adds JWT token to request headers
- Handles 401 responses by redirecting to login
- Implements error logging
- Supports request/response interceptors

```typescript
import apiClient from '@/api/client'

// Make requests
const response = await apiClient.get('/endpoint')
const data = await apiClient.post('/endpoint', { payload })
```

## Styling with Tailwind

Custom color palette:
- Primary: Sky blue (#0ea5e9)
- Success: Green (#22c55e)
- Warning: Yellow (#eab308)
- Danger: Red (#ef4444)
- Neutral: Gray (#various)

Custom components (in `main.css`):
- `.btn-primary` / `.btn-secondary` / `.btn-danger`
- `.input-field`
- `.card`
- `.page-container`
- `.section-title`

## Development Tips

1. **Mock API Responses**: The views include hardcoded demo data for development
2. **TypeScript Interfaces**: All data types are defined in `src/types/index.ts`
3. **Component Reusability**: Create new components in `src/components/` for reuse
4. **State Management**: Use Pinia stores for global state
5. **PWA Ready**: Configure `tailwind.config.js` with safe area insets for mobile

## Money Format

All monetary values in the backend use **paise** (1 Rupee = 100 paise).

The `PriceDisplay` component automatically converts paise to rupees:
```typescript
// Backend sends 500000 (paise)
// Component displays ₹5,000 (rupees)
<PriceDisplay :paise="500000" /> <!-- Output: ₹5,000 -->
```

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## License

Proprietary - Collective Commerce
