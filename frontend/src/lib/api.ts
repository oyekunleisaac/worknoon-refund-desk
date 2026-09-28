import type {
  AdminRefundRequest, Customer, Health, LookupResponse, Paginated, RefundResult, Scenario, Stats,
} from './types'

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

const TOKEN_KEY = 'worknoon.adminToken'

export const adminToken = {
  get: () => sessionStorage.getItem(TOKEN_KEY) ?? '',
  set: (token: string) => sessionStorage.setItem(TOKEN_KEY, token),
  clear: () => sessionStorage.removeItem(TOKEN_KEY),
}

async function request<T>(path: string, init: RequestInit = {}, admin = false): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (admin) headers.set('Authorization', `Bearer ${adminToken.get()}`)

  let response: Response
  try {
    response = await fetch(`/api${path}`, { ...init, headers })
  } catch {
    throw new ApiError(0, 'Cannot reach the server. Is the backend running?')
  }

  const body = await response.json().catch(() => ({}))

  if (!response.ok) {
    const message = response.status === 429
      ? 'Too many requests. Please wait a minute and try again.'
      : body.message ?? `Request failed (${response.status})`
    throw new ApiError(response.status, message, body.errors ?? {})
  }

  return body as T
}

const post = (body: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(body) })

export const api = {
  health: () => request<Health>('/health'),
  policy: () => request<{ markdown: string; parameters: Record<string, number> }>('/policy'),
  scenarios: () => request<Scenario[]>('/demo/scenarios'),

  lookupOrder: (email: string, order_number: string) =>
    request<LookupResponse>('/orders/lookup', post({ email, order_number })),

  submitRefund: (email: string, order_number: string, message: string) =>
    request<RefundResult>('/refund-requests', post({ email, order_number, message })),

  refundStatus: (reference: string, email: string) =>
    request<RefundResult>(`/refund-requests/${encodeURIComponent(reference)}?email=${encodeURIComponent(email)}`),

  admin: {
    stats: () => request<Stats>('/admin/stats', {}, true),
    requests: (params: Record<string, string>) =>
      request<Paginated<AdminRefundRequest>>(`/admin/refund-requests?${new URLSearchParams(params)}`, {}, true),
    request: (id: number | string) => request<AdminRefundRequest>(`/admin/refund-requests/${id}`, {}, true),
    review: (id: number, action: 'approve' | 'deny', note: string, reviewer: string) =>
      request<AdminRefundRequest>(`/admin/refund-requests/${id}/review`, post({ action, note, reviewer }), true),
    customers: () => request<Customer[]>('/admin/customers', {}, true),
    resetDemo: () => request<{ message: string }>('/admin/demo/reset', post({}), true),
  },
}
