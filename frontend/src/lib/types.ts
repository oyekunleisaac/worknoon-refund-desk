export type Decision = 'approved' | 'denied' | 'escalated'
export type RequestStatus = 'processing' | 'resolved' | 'pending_review' | 'reviewed' | 'failed'

export interface OrderItem {
  id: number
  sku: string
  name: string
  category: string
  quantity: number
  unit_price: number
  final_sale: boolean
  refunded: boolean
}

export interface Order {
  order_number: string
  status: string
  carrier_status: string | null
  total: number
  currency: string
  placed_at: string
  shipped_at: string | null
  delivered_at: string | null
  items?: OrderItem[]
}

export interface RefundResult {
  reference: string
  order_number: string
  decision: Decision | null
  status: RequestStatus
  refund_amount: number | null
  reply: string | null
  resolution: string | null
  created_at: string
  reviewed_at: string | null
}

export interface LookupResponse {
  customer: { first_name: string }
  order: Order
  recent_requests: RefundResult[]
}

export interface Scenario {
  customer: string
  email: string
  order_number: string
  title: string
  expected: Decision
  message: string
}

export interface Health {
  status: string
  database: string
  ai: { mode: 'llm' | 'fallback'; model: string | null }
  demo_mode: boolean
}

export interface RuleResult {
  code: string
  title: string
  outcome: 'pass' | 'deny' | 'escalate' | 'info'
  detail: string
  customer_message: string | null
}

export interface PolicyItem {
  id: number
  sku: string
  name: string
  amount: number
}

export interface PolicyEvaluation {
  decision: Decision
  refund_amount: number
  partial: boolean
  eligible_items: PolicyItem[]
  excluded_items: PolicyItem[]
  rules: RuleResult[]
  risk_flags: string[]
}

export interface Classification {
  reason: string
  reason_label: string
  referenced_skus: string[]
  claimed_amount: number | null
  summary: string
  sentiment: string
  confidence: number
  manipulation_attempt: boolean
  manipulation_notes: string | null
  inconsistencies: string[]
  source: 'llm' | 'fallback'
  model: string | null
  usage?: Record<string, unknown>
}

export interface AuditEvent {
  id: number
  stage: string
  actor: 'system' | 'ai' | 'policy_engine' | 'human'
  summary: string
  data: Record<string, unknown> | null
  duration_ms: number | null
  created_at: string
}

export interface AdminRefundRequest {
  id: number
  reference: string
  decision: Decision | null
  status: RequestStatus
  reason_category: string | null
  refund_amount: number
  risk_flags: string[]
  ai_mode: 'llm' | 'fallback' | null
  ai_model: string | null
  submitted_email: string
  submitted_order_number: string
  message: string
  summary: string | null
  customer?: {
    id: number
    customer_number: string
    name: string
    email: string
    tier: string
    account_flagged: boolean
  } | null
  order?: Order | null
  customer_reply: string | null
  resolution_message: string | null
  classification: Classification | null
  policy_evaluation: PolicyEvaluation | null
  reviewed_by: string | null
  review_note: string | null
  reviewed_at: string | null
  audit_events?: AuditEvent[]
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export interface Stats {
  total: number
  approved: number
  denied: number
  escalated: number
  pending_review: number
  automation_rate: number | null
  refunded_amount: number
  flagged: number
  by_reason: Record<string, number>
}

export interface Customer {
  id: number
  customer_number: string
  name: string
  email: string
  phone: string | null
  tier: string
  account_flagged: boolean
  flag_reason: string | null
  notes: string | null
  customer_since: string | null
  refund_requests_count: number
  orders: Order[]
}
