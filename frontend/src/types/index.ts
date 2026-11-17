/**
 * Core TypeScript interfaces for Collective Commerce platform
 * All monetary values are in paise (1 Rupee = 100 paise)
 */

export interface User {
  id: string
  email: string
  phone: string
  name: string
  role: 'buyer' | 'seller' | 'ops'
  organizationId: string
  avatar?: string
  createdAt: Date
  updatedAt: Date
}

export interface Organization {
  id: string
  name: string
  type: 'buyer' | 'seller' | 'ops'
  registrationNumber?: string
  gstNumber?: string
  panNumber?: string
  address: Address
  contactPerson: ContactPerson
  logoUrl?: string
  verificationStatus: 'pending' | 'verified' | 'rejected'
  createdAt: Date
  updatedAt: Date
}

export interface Address {
  street: string
  city: string
  state: string
  postalCode: string
  country: string
}

export interface ContactPerson {
  name: string
  email: string
  phone: string
  designation: string
}

export interface BuyRequest {
  id: string
  organizationId: string
  productName: string
  description: string
  quantity: number
  unit: string
  budgetMin: number // in paise
  budgetMax: number // in paise
  deliveryLocation: Address
  requiredByDate: Date
  status: 'draft' | 'active' | 'closed' | 'fulfilled'
  demandGroupId?: string
  createdAt: Date
  updatedAt: Date
}

export interface DemandGroup {
  id: string
  organizationId: string
  name: string
  description: string
  buyRequestIds: string[]
  estimatedBudgetMin: number // in paise
  estimatedBudgetMax: number // in paise
  targetDeliveryDate: Date
  status: 'forming' | 'active' | 'rfq_sent' | 'completed'
  memberCount: number
  createdAt: Date
  updatedAt: Date
}

export interface RFQ {
  id: string
  demandGroupId: string
  supplierId?: string
  status: 'draft' | 'sent' | 'bidding' | 'evaluation' | 'awarded' | 'rejected'
  requiredQuantity: number
  unit: string
  budgetMin: number // in paise
  budgetMax: number // in paise
  deliveryLocation: Address
  requiredByDate: Date
  attachments: string[]
  createdAt: Date
  updatedAt: Date
}

export interface SellerBid {
  id: string
  rfqId: string
  supplierId: string
  status: 'draft' | 'submitted' | 'withdrawn' | 'accepted' | 'rejected'
  unitPrice: number // in paise
  totalAmount: number // in paise
  deliveryDays: number
  paymentTerms: string
  qualityRating: number
  certifications: string[]
  deliveryAddress: Address
  notes?: string
  createdAt: Date
  updatedAt: Date
}

export interface Offer {
  id: string
  rfqId: string
  sellerBidId: string
  buyerOrganizationId: string
  status: 'pending' | 'accepted' | 'rejected' | 'expired'
  unitPrice: number // in paise
  totalAmount: number // in paise
  quantity: number
  unit: string
  deliveryDate: Date
  paymentTerms: string
  expiryDate: Date
  createdAt: Date
  updatedAt: Date
}

export interface Order {
  id: string
  buyerOrganizationId: string
  sellerId: string
  offerId: string
  status: 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled'
  totalAmount: number // in paise
  taxAmount: number // in paise
  shippingAmount: number // in paise
  paymentStatus: 'pending' | 'partial' | 'completed'
  items: OrderItem[]
  shipments: Shipment[]
  createdAt: Date
  updatedAt: Date
}

export interface OrderItem {
  id: string
  productName: string
  quantity: number
  unit: string
  unitPrice: number // in paise
  totalPrice: number // in paise
  taxRate: number
  taxAmount: number // in paise
}

export interface Payment {
  id: string
  orderId: string
  amount: number // in paise
  method: 'upi' | 'bank_transfer' | 'credit_card' | 'neft' | 'rtgs'
  status: 'pending' | 'completed' | 'failed' | 'refunded'
  transactionId?: string
  gatewayResponse?: Record<string, any>
  createdAt: Date
  updatedAt: Date
}

export interface Shipment {
  id: string
  orderId: string
  carrier: string
  trackingNumber: string
  status: 'pending' | 'picked_up' | 'in_transit' | 'out_for_delivery' | 'delivered'
  originAddress: Address
  destinationAddress: Address
  estimatedDelivery: Date
  actualDelivery?: Date
  createdAt: Date
  updatedAt: Date
}

export interface Dispute {
  id: string
  orderId: string
  initiatedBy: 'buyer' | 'seller'
  type: 'quality' | 'quantity' | 'delivery' | 'payment' | 'other'
  description: string
  status: 'open' | 'in_resolution' | 'resolved' | 'escalated'
  desiredResolution: string
  evidence: string[]
  resolutionNotes?: string
  createdAt: Date
  updatedAt: Date
}

export interface Settlement {
  id: string
  sellerId: string
  orderId: string
  grossAmount: number // in paise
  platformFees: number // in paise
  taxAmount: number // in paise
  netAmount: number // in paise
  status: 'pending' | 'processed' | 'failed'
  bankAccountDetails: BankAccount
  processedDate?: Date
  createdAt: Date
  updatedAt: Date
}

export interface BankAccount {
  accountNumber: string
  accountHolderName: string
  bankName: string
  ifscCode: string
  accountType: 'savings' | 'current'
}

export interface Notification {
  id: string
  userId: string
  type: 'info' | 'success' | 'warning' | 'error'
  title: string
  message: string
  actionUrl?: string
  read: boolean
  createdAt: Date
}
