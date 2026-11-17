# Quick Start Guide

Get the Collective Commerce frontend running in minutes.

## Prerequisites

- Node.js 16+ and npm
- Code editor (VS Code recommended)
- Backend API running on `http://localhost:8000`

## Installation (2 minutes)

```bash
# Navigate to frontend directory
cd c:\laragon1\www\BuyLot\frontend

# Install all dependencies
npm install
```

## Development Mode (1 command)

```bash
npm run dev
```

The application will be available at `http://localhost:5173`

## First Login

1. Navigate to `http://localhost:5173/login`
2. Use test credentials (backend should provide these)
3. The app will redirect you based on your user role:
   - **Buyer** → Dashboard at `/`
   - **Seller** → Dashboard at `/seller/dashboard`
   - **Ops** → Dashboard at `/ops/dashboard`

## Folder Guide

| Folder | Purpose |
|--------|---------|
| `src/views/buyer/` | 10 buyer portal pages |
| `src/views/seller/` | 6 seller portal pages |
| `src/views/ops/` | 8 operations portal pages |
| `src/components/common/` | Reusable UI components |
| `src/stores/` | Pinia state management |
| `src/router/` | Vue Router with role guards |

## Development Workflow

### Add a New Page

1. Create component in `src/views/[portal]/MyPage.vue`
2. Add route in `src/router/index.ts`
3. Import component with dynamic import
4. Test in browser

Example:
```typescript
// In router/index.ts
const MyView = () => import('@/views/buyer/MyPage.vue')

// Add to routes array
{
  path: '/my-page',
  component: MyView,
  meta: { title: 'My Page' }
}
```

### Add a New Component

1. Create in `src/components/common/MyComponent.vue`
2. Import in any view: `import MyComponent from '@/components/common/MyComponent.vue'`
3. Use in template

### Connect to Backend API

The app automatically proxies `/api` calls to `http://localhost:8000`.

```typescript
import apiClient from '@/api/client'

// Make requests
const response = await apiClient.get('/endpoint')
const data = await apiClient.post('/endpoint', { payload })
```

Errors and 401s are handled automatically with redirects to login.

## Common Tasks

### Change Colors

Edit `tailwind.config.js`:
```javascript
colors: {
  primary: { /* update colors */ }
}
```

### Change API URL

Edit `vite.config.ts`:
```typescript
'/api': {
  target: 'http://your-api-url:8000',
  // ...
}
```

### Add a Status Badge

The `StatusBadge` component is pre-configured with these statuses:
```
active, pending, completed, cancelled, rejected, draft,
submitted, accepted, in_transit, delivered, open, resolved
```

### Format Money

The `PriceDisplay` component converts paise to rupees:
```vue
<PriceDisplay :paise="500000" /> <!-- Shows ₹5,000 -->
```

## Useful Commands

```bash
# Development
npm run dev

# Type checking
npm run type-check

# Production build
npm run build

# Preview production build
npm run preview
```

## Testing URLs

After `npm run dev`:

| Portal | URL |
|--------|-----|
| Auth | http://localhost:5173/login |
| Buyer | http://localhost:5173/ |
| Seller | http://localhost:5173/seller/dashboard |
| Ops | http://localhost:5173/ops/dashboard |

## Debugging Tips

1. **Vue DevTools**: Install Vue DevTools browser extension
2. **Network Tab**: Check API requests and responses
3. **Console Logs**: Check browser console for errors
4. **TypeScript**: Let IDE catch errors before runtime

## File Structure Quick Reference

```
📁 src/
 ├─ 📁 views/              ← Add new pages here
 ├─ 📁 components/common/  ← Reusable UI components
 ├─ 📁 stores/            ← Global state (auth, notifications)
 ├─ 📁 api/               ← API client configuration
 ├─ 📁 types/             ← TypeScript interfaces
 ├─ 📁 router/            ← Route configuration
 ├─ 📁 layouts/           ← Portal layouts
 ├─ 📁 styles/            ← Global CSS
 ├─ App.vue               ← Root component
 └─ main.ts               ← App entry point
```

## Common Issues

### "Cannot find module '@/...'"
- Check the import path is correct
- Restart dev server: `npm run dev`

### API requests failing
- Ensure backend is running on `http://localhost:8000`
- Check network tab in browser DevTools
- Verify CORS headers from backend

### TypeScript errors
- Run `npm run type-check`
- Check `src/types/index.ts` for correct interfaces
- Ensure imports use correct paths

### Styling not working
- Clear browser cache
- Rebuild with `npm run dev`
- Check Tailwind classes are spelled correctly

## Production Build

```bash
# Build for production
npm run build

# Output goes to dist/ folder
# Deploy the dist folder to your web server
```

## Need Help?

1. Check `README.md` for detailed documentation
2. Review `SCAFFOLDING_SUMMARY.md` for complete file listing
3. Check `src/types/index.ts` for data structure examples
4. Review existing views for implementation patterns

---

**Happy coding! 🚀**
