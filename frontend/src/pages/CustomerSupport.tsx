import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { api, ApiError } from '../lib/api'
import type { LookupResponse, RefundResult, Scenario } from '../lib/types'
import { date, humanize, money } from '../lib/format'
import { Alert, Button, Card, DecisionBadge, Field, SectionTitle, Tag } from '../components/ui'
import { inputClass, cx } from '../lib/styles'

type ChatMessage =
  | { id: string; role: 'assistant'; text: string; result?: RefundResult }
  | { id: string; role: 'customer'; text: string }

const MAX_LENGTH = 2000
let seq = 0
const uid = () => `m${++seq}`

export default function CustomerSupport() {
  const [email, setEmail] = useState('')
  const [orderNumber, setOrderNumber] = useState('')
  const [session, setSession] = useState<LookupResponse | null>(null)
  const [lookupError, setLookupError] = useState<string | null>(null)
  const [lookingUp, setLookingUp] = useState(false)
  const [scenarios, setScenarios] = useState<Scenario[]>([])
  const [draft, setDraft] = useState('')
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState<string | null>(null)

  useEffect(() => {
    api.scenarios().then(setScenarios).catch(() => setScenarios([]))
  }, [])

  const verify = async (mail: string, order: string, suggestedMessage?: string) => {
    setLookingUp(true)
    setLookupError(null)
    try {
      const result = await api.lookupOrder(mail, order)
      setSession(result)
      setEmail(mail)
      setOrderNumber(result.order.order_number)
      setDraft(suggestedMessage ?? '')
      setSendError(null)
      setMessages([
        ...result.recent_requests.slice().reverse().map((r): ChatMessage => ({
          id: uid(), role: 'assistant', text: r.resolution ?? r.reply ?? '', result: r,
        })),
        {
          id: uid(),
          role: 'assistant',
          text: `Hi ${result.customer.first_name}, I'm the Worknoon refund assistant. I can see order ${result.order.order_number}. Tell me what went wrong and I'll check it against our refund policy right away.`,
        },
      ])
    } catch (e) {
      setLookupError(e instanceof ApiError ? e.message : 'Something went wrong.')
    } finally {
      setLookingUp(false)
    }
  }

  const reset = () => {
    setSession(null)
    setMessages([])
    setDraft('')
    setSendError(null)
  }

  const refreshOrder = async () => {
    try {
      const updated = await api.lookupOrder(email, orderNumber)
      setSession((s) => (s ? { ...s, order: updated.order } : s))
    } catch { /* non-critical */ }
  }

  const send = async () => {
    const text = draft.trim()
    if (!session || !text || sending) return
    setSending(true)
    setSendError(null)
    setMessages((m) => [...m, { id: uid(), role: 'customer', text }])
    setDraft('')
    try {
      const result = await api.submitRefund(email, orderNumber, text)
      setMessages((m) => [...m, { id: uid(), role: 'assistant', text: result.reply ?? '', result }])
      refreshOrder()
    } catch (e) {
      const message = e instanceof ApiError
        ? Object.values(e.errors)[0]?.[0] ?? e.message
        : 'Something went wrong.'
      setSendError(message)
      setDraft(text)
      setMessages((m) => m.slice(0, -1))
    } finally {
      setSending(false)
    }
  }

  const checkStatus = async (reference: string) => {
    try {
      const result = await api.refundStatus(reference, email)
      setMessages((m) => [...m, {
        id: uid(),
        role: 'assistant',
        text: result.resolution ?? `Your request ${reference} is still with our support team. We'll update you as soon as it has been reviewed.`,
        result: result.resolution ? result : undefined,
      }])
      if (result.resolution) refreshOrder()
    } catch (e) {
      setSendError(e instanceof ApiError ? e.message : 'Could not check status.')
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
      <aside className="space-y-4">
        {session ? (
          <OrderCard session={session} onReset={reset} />
        ) : (
          <VerifyForm
            email={email}
            orderNumber={orderNumber}
            setEmail={setEmail}
            setOrderNumber={setOrderNumber}
            onSubmit={() => verify(email, orderNumber)}
            loading={lookingUp}
            error={lookupError}
          />
        )}
        {scenarios.length > 0 && (
          <ScenarioList scenarios={scenarios} active={session?.order.order_number} onPick={(s) => verify(s.email, s.order_number, s.message)} />
        )}
      </aside>

      <Card className="flex h-[calc(100vh-9rem)] min-h-[520px] flex-col overflow-hidden">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <div>
            <h1 className="text-sm font-semibold text-slate-900">Refund assistant</h1>
            <p className="text-xs text-slate-500">Decisions follow our published refund policy. Some requests are reviewed by a person.</p>
          </div>
        </div>

        <ChatThread messages={messages} sending={sending} onCheckStatus={checkStatus} empty={!session} />

        <div className="border-t border-slate-200 bg-slate-50/60 p-3">
          {sendError && <div className="mb-2"><Alert>{sendError}</Alert></div>}
          <Composer
            value={draft}
            onChange={setDraft}
            onSend={send}
            disabled={!session}
            sending={sending}
          />
        </div>
      </Card>
    </div>
  )
}

function VerifyForm(props: {
  email: string; orderNumber: string; setEmail: (v: string) => void; setOrderNumber: (v: string) => void
  onSubmit: () => void; loading: boolean; error: string | null
}) {
  const submit = (e: FormEvent) => { e.preventDefault(); props.onSubmit() }
  return (
    <Card className="p-5">
      <h2 className="text-base font-semibold text-slate-900">Find your order</h2>
      <p className="mb-4 mt-1 text-sm text-slate-500">We use your email and order number to verify it's you.</p>
      <form onSubmit={submit} className="space-y-3">
        <Field label="Email address">
          <input className={inputClass} type="email" required value={props.email} onChange={(e) => props.setEmail(e.target.value)} placeholder="you@example.com" autoComplete="email" />
        </Field>
        <Field label="Order number">
          <input className={inputClass} required value={props.orderNumber} onChange={(e) => props.setOrderNumber(e.target.value)} placeholder="ORD-1001" />
        </Field>
        {props.error && <Alert>{props.error}</Alert>}
        <Button type="submit" className="w-full" loading={props.loading}>Continue</Button>
      </form>
    </Card>
  )
}

function OrderCard({ session, onReset }: { session: LookupResponse; onReset: () => void }) {
  const { order } = session
  return (
    <Card className="p-5">
      <SectionTitle aside={<button onClick={onReset} className="text-xs font-medium text-brand-600 hover:underline">Change order</button>}>
        Your order
      </SectionTitle>
      <div className="flex items-baseline justify-between">
        <span className="text-lg font-semibold text-slate-900">{order.order_number}</span>
        <Tag tone={order.status === 'delivered' ? 'emerald' : order.status.includes('refund') ? 'brand' : 'slate'}>{humanize(order.status)}</Tag>
      </div>
      <dl className="mt-2 grid grid-cols-2 gap-y-1 text-xs text-slate-500">
        <dt>Placed</dt><dd className="text-right text-slate-700">{date(order.placed_at)}</dd>
        <dt>Delivered</dt><dd className="text-right text-slate-700">{order.delivered_at ? date(order.delivered_at) : humanize(order.carrier_status) }</dd>
        <dt>Total</dt><dd className="text-right font-medium text-slate-900">{money(order.total)}</dd>
      </dl>
      <ul className="mt-4 divide-y divide-slate-100 border-t border-slate-100">
        {order.items?.map((item) => (
          <li key={item.id} className="flex items-start justify-between gap-3 py-2.5">
            <div className="min-w-0">
              <p className={cx('text-sm text-slate-800', item.refunded && 'text-slate-400 line-through')}>{item.name}</p>
              <p className="mt-0.5 flex flex-wrap gap-1 text-xs text-slate-400">
                <span>{item.sku} · qty {item.quantity}</span>
                {item.final_sale && <Tag tone="rose">Final sale</Tag>}
                {item.refunded && <Tag tone="brand">Refunded</Tag>}
              </p>
            </div>
            <span className="text-sm text-slate-700">{money(item.unit_price * item.quantity)}</span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

const expectedTone = { approved: 'emerald', denied: 'rose', escalated: 'amber' } as const

function ScenarioList({ scenarios, active, onPick }: { scenarios: Scenario[]; active?: string; onPick: (s: Scenario) => void }) {
  return (
    <Card className="p-4">
      <SectionTitle>Demo scenarios</SectionTitle>
      <p className="mb-3 text-xs text-slate-500">Pick a test customer: it verifies the order and pre-fills a message you can edit.</p>
      <ul className="max-h-[360px] space-y-1 overflow-y-auto pr-1">
        {scenarios.map((s) => (
          <li key={`${s.order_number}-${s.title}`}>
            <button
              onClick={() => onPick(s)}
              className={cx('w-full rounded-lg px-2.5 py-2 text-left transition hover:bg-slate-50',
                active === s.order_number && 'bg-brand-50 hover:bg-brand-50')}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-800">{s.title}</span>
                <Tag tone={expectedTone[s.expected]}>{s.expected}</Tag>
              </div>
              <div className="text-xs text-slate-500">{s.customer} · {s.order_number}</div>
            </button>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function ChatThread({ messages, sending, empty, onCheckStatus }: {
  messages: ChatMessage[]; sending: boolean; empty: boolean; onCheckStatus: (ref: string) => void
}) {
  const bottom = useRef<HTMLDivElement>(null)
  useEffect(() => { bottom.current?.scrollIntoView({ behavior: 'smooth' }) }, [messages, sending])

  if (empty) {
    return (
      <div className="grid flex-1 place-items-center p-8 text-center">
        <div className="max-w-sm">
          <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-brand-50 text-xl">💬</div>
          <p className="text-sm font-medium text-slate-800">Start by finding your order</p>
          <p className="mt-1 text-sm text-slate-500">Enter your email and order number, or pick one of the demo scenarios.</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex-1 space-y-4 overflow-y-auto px-5 py-5">
      {messages.map((m) => (m.role === 'customer'
        ? (
          <div key={m.id} className="flex justify-end">
            <div className="max-w-[80%] whitespace-pre-line rounded-2xl rounded-br-sm bg-brand-600 px-4 py-2.5 text-sm text-white shadow-sm">{m.text}</div>
          </div>
        )
        : <AssistantMessage key={m.id} message={m} onCheckStatus={onCheckStatus} />))}
      {sending && (
        <div className="flex items-center gap-3">
          <Avatar />
          <div className="rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-3 text-sm text-slate-500">
            <span className="typing mr-2 inline-flex gap-0.5 text-lg leading-none"><span>•</span><span>•</span><span>•</span></span>
            Checking your order against our refund policy…
          </div>
        </div>
      )}
      <div ref={bottom} />
    </div>
  )
}

function Avatar() {
  return <div className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">W</div>
}

function AssistantMessage({ message, onCheckStatus }: { message: Extract<ChatMessage, { role: 'assistant' }>; onCheckStatus: (ref: string) => void }) {
  const r = message.result
  return (
    <div className="flex items-start gap-3">
      <Avatar />
      <div className="max-w-[85%] space-y-2">
        {r && (
          <div className="flex flex-wrap items-center gap-2">
            <DecisionBadge decision={r.decision} size="lg" />
            {r.decision === 'approved' && r.refund_amount != null && (
              <span className="text-sm font-semibold text-emerald-700">{money(r.refund_amount)} refunded</span>
            )}
            {r.status === 'pending_review' && <span className="text-xs text-slate-500">With a specialist · 1-2 business days</span>}
            <span className="font-mono text-[11px] text-slate-400">{r.reference}</span>
          </div>
        )}
        <div className="whitespace-pre-line rounded-2xl rounded-tl-sm bg-slate-100 px-4 py-3 text-sm leading-relaxed text-slate-800">{message.text}</div>
        {r && (r.status === 'pending_review' || r.status === 'failed') && (
          <button onClick={() => onCheckStatus(r.reference)} className="text-xs font-medium text-brand-600 hover:underline">
            Check for an update
          </button>
        )}
      </div>
    </div>
  )
}

function Composer({ value, onChange, onSend, disabled, sending }: {
  value: string; onChange: (v: string) => void; onSend: () => void; disabled: boolean; sending: boolean
}) {
  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSend() }
  }
  const tooLong = value.length > MAX_LENGTH
  return (
    <div className="flex items-end gap-2">
      <div className="flex-1">
        <textarea
          rows={2}
          value={value}
          disabled={disabled || sending}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder={disabled ? 'Find your order to start…' : 'Describe the problem, e.g. "The headphones arrived with a cracked ear cup"'}
          className={cx(inputClass, 'resize-none bg-white disabled:bg-slate-100')}
        />
        <div className={cx('mt-1 text-right text-[11px]', tooLong ? 'text-rose-600' : 'text-slate-400')}>
          {value.length}/{MAX_LENGTH} · Enter to send, Shift+Enter for a new line
        </div>
      </div>
      <Button onClick={onSend} disabled={disabled || !value.trim() || tooLong} loading={sending} className="mb-5">
        Send
      </Button>
    </div>
  )
}
