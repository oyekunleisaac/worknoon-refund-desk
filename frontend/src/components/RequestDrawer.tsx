import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../lib/api'
import type { AdminRefundRequest, AuditEvent, RuleResult } from '../lib/types'
import { dateTime, humanize, money, tier } from '../lib/format'
import { Alert, Button, DecisionBadge, Field, SectionTitle, Spinner, StatusBadge, Tag } from './ui'
import { cx, inputClass } from '../lib/styles'

export default function RequestDrawer({ id, onClose, onReviewed }: { id: number; onClose: () => void; onReviewed: () => void }) {
  const [request, setRequest] = useState<AdminRefundRequest | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api.admin.request(id).then(setRequest).catch((e) => setError(e instanceof ApiError ? e.message : 'Failed to load.'))
  }, [id])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-40 flex justify-end">
      <div className="absolute inset-0 bg-slate-900/30" onClick={onClose} />
      <aside className="relative flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl">
        <div className="flex items-start justify-between border-b border-slate-200 px-6 py-4">
          {request ? (
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-mono text-base font-semibold text-slate-900">{request.reference}</h2>
                <DecisionBadge decision={request.decision} />
                <StatusBadge status={request.status} />
              </div>
              <p className="mt-1 text-xs text-slate-500">
                {request.customer?.name ?? 'Unverified requester'} · {request.submitted_order_number} · {dateTime(request.created_at)}
              </p>
            </div>
          ) : <div />}
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">✕</button>
        </div>

        <div className="flex-1 space-y-6 overflow-y-auto px-6 py-5">
          {error && <Alert>{error}</Alert>}
          {!request && !error && <div className="grid place-items-center py-20 text-slate-400"><Spinner className="h-6 w-6" /></div>}
          {request && (
            <>
              {(request.status === 'pending_review' || request.status === 'failed') && (
                <ReviewPanel request={request} onDone={(r) => { setRequest(r); onReviewed() }} />
              )}
              {request.status === 'reviewed' && (
                <Alert tone="info">
                  <strong>{request.reviewed_by}</strong> {request.decision} this request on {dateTime(request.reviewed_at)}: “{request.review_note}”
                </Alert>
              )}
              <CustomerSection request={request} />
              {request.classification && <ClassificationSection request={request} />}
              {request.policy_evaluation && <PolicySection request={request} />}
              <ReplySection request={request} />
              {request.audit_events && <AuditTimeline events={request.audit_events} />}
            </>
          )}
        </div>
      </aside>
    </div>
  )
}

function ReviewPanel({ request, onDone }: { request: AdminRefundRequest; onDone: (r: AdminRefundRequest) => void }) {
  const [reviewer, setReviewer] = useState(() => localStorage.getItem('worknoon.reviewer') ?? '')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState<'approve' | 'deny' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const proposed = request.policy_evaluation?.eligible_items ?? []

  const submit = async (action: 'approve' | 'deny', e?: FormEvent) => {
    e?.preventDefault()
    setBusy(action)
    setError(null)
    localStorage.setItem('worknoon.reviewer', reviewer)
    try {
      onDone(await api.admin.review(request.id, action, note, reviewer))
    } catch (err) {
      setError(err instanceof ApiError ? Object.values(err.errors)[0]?.[0] ?? err.message : 'Review failed.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
      <h3 className="text-sm font-semibold text-amber-900">Human review required</h3>
      <p className="mt-0.5 text-xs text-amber-800">
        {proposed.length
          ? <>Approving refunds <strong>{money(request.refund_amount)}</strong> for {proposed.map((i) => i.name).join(', ')}.</>
          : 'No refundable items were proposed; this request can only be denied.'}
      </p>
      <div className="mt-3 grid gap-3 sm:grid-cols-[180px_1fr]">
        <Field label="Your name">
          <input className={inputClass} value={reviewer} onChange={(e) => setReviewer(e.target.value)} placeholder="Sam (Support)" />
        </Field>
        <Field label="Review note (audit log)">
          <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Photos confirm the damage" />
        </Field>
      </div>
      {error && <div className="mt-3"><Alert>{error}</Alert></div>}
      <div className="mt-3 flex gap-2">
        <Button variant="success" disabled={!reviewer || note.length < 5 || !proposed.length} loading={busy === 'approve'} onClick={() => submit('approve')}>
          Approve {proposed.length ? money(request.refund_amount) : ''}
        </Button>
        <Button variant="danger" disabled={!reviewer || note.length < 5} loading={busy === 'deny'} onClick={() => submit('deny')}>Deny</Button>
      </div>
    </div>
  )
}

function CustomerSection({ request }: { request: AdminRefundRequest }) {
  const flagged = request.risk_flags.length > 0
  return (
    <section>
      <SectionTitle>Customer message</SectionTitle>
      <blockquote className={cx('whitespace-pre-line rounded-lg border-l-4 px-4 py-3 text-sm text-slate-800',
        flagged ? 'border-rose-400 bg-rose-50/60' : 'border-slate-300 bg-slate-50')}>
        {request.message}
      </blockquote>
      <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
        <span>{request.submitted_email}</span>
        {request.customer && <Tag>{request.customer.customer_number}</Tag>}
        {request.customer && <Tag tone="brand">{tier(request.customer.tier)}</Tag>}
        {request.customer?.account_flagged && <Tag tone="rose">Account flagged</Tag>}
        {request.risk_flags.map((f) => <Tag key={f} tone="rose">⚑ {humanize(f)}</Tag>)}
      </div>
    </section>
  )
}

function ClassificationSection({ request }: { request: AdminRefundRequest }) {
  const c = request.classification!
  return (
    <section>
      <SectionTitle aside={<Tag tone={c.source === 'llm' ? 'brand' : 'amber'}>{c.source === 'llm' ? `AI · ${c.model}` : 'Keyword fallback'}</Tag>}>
        AI classification <span className="font-normal normal-case text-slate-400">(claims, not facts)</span>
      </SectionTitle>
      <div className="grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-4 text-sm sm:grid-cols-4">
        <Metric label="Reason" value={c.reason_label} />
        <Metric label="Sentiment" value={humanize(c.sentiment)} />
        <Metric label="Claimed amount" value={c.claimed_amount != null ? money(c.claimed_amount) : '—'} />
        <div>
          <div className="text-xs text-slate-500">Confidence</div>
          <div className="mt-1 flex items-center gap-2">
            <div className="h-1.5 flex-1 rounded-full bg-slate-100">
              <div className={cx('h-1.5 rounded-full', c.confidence >= 0.6 ? 'bg-emerald-500' : 'bg-amber-500')} style={{ width: `${c.confidence * 100}%` }} />
            </div>
            <span className="text-xs tabular-nums text-slate-700">{Math.round(c.confidence * 100)}%</span>
          </div>
        </div>
        <div className="col-span-full text-slate-700"><span className="text-xs text-slate-500">Summary · </span>{c.summary}</div>
        {c.referenced_skus.length > 0 && (
          <div className="col-span-full text-xs text-slate-500">Items referenced: {c.referenced_skus.map((s) => <Tag key={s}>{s}</Tag>)}</div>
        )}
        {c.manipulation_attempt && (
          <div className="col-span-full"><Alert>Manipulation attempt detected by the model{c.manipulation_notes ? `: ${c.manipulation_notes}` : '.'}</Alert></div>
        )}
        {c.inconsistencies.length > 0 && (
          <div className="col-span-full">
            <Alert tone="warning">
              <div className="font-medium">Inconsistencies with the order record</div>
              <ul className="mt-1 list-disc pl-5">{c.inconsistencies.map((i) => <li key={i}>{i}</li>)}</ul>
            </Alert>
          </div>
        )}
      </div>
    </section>
  )
}

const ruleStyle: Record<RuleResult['outcome'], [string, string]> = {
  pass: ['✓', 'text-emerald-600 bg-emerald-50'],
  deny: ['✕', 'text-rose-600 bg-rose-50'],
  escalate: ['↗', 'text-amber-700 bg-amber-50'],
  info: ['i', 'text-brand-600 bg-brand-50'],
}

function PolicySection({ request }: { request: AdminRefundRequest }) {
  const p = request.policy_evaluation!
  const important = p.rules.filter((r) => r.outcome !== 'pass')
  const passed = p.rules.filter((r) => r.outcome === 'pass')
  return (
    <section>
      <SectionTitle>Policy evaluation <span className="font-normal normal-case text-slate-400">(deterministic, decides the outcome)</span></SectionTitle>
      <div className="rounded-lg border border-slate-200">
        <ul className="divide-y divide-slate-100">
          {[...important, ...passed].map((r) => {
            const [icon, style] = ruleStyle[r.outcome]
            return (
              <li key={r.code + r.outcome} className="flex gap-3 px-4 py-2.5">
                <span className={cx('mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-bold', style)}>{icon}</span>
                <div className="min-w-0 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-800">{r.title}</span>
                    <code className="text-[10px] text-slate-400">{r.code}</code>
                  </div>
                  <p className="text-slate-600">{r.detail}</p>
                </div>
              </li>
            )
          })}
        </ul>
        {(p.eligible_items.length > 0 || p.excluded_items.length > 0) && (
          <div className="grid gap-3 border-t border-slate-200 bg-slate-50/60 px-4 py-3 text-xs sm:grid-cols-2">
            <ItemList title={request.decision === 'approved' ? 'Refunded items' : 'Proposed items'} items={p.eligible_items} />
            {p.excluded_items.length > 0 && <ItemList title="Excluded items" items={p.excluded_items} muted />}
          </div>
        )}
      </div>
    </section>
  )
}

function ItemList({ title, items, muted }: { title: string; items: { id: number; name: string; amount: number }[]; muted?: boolean }) {
  return (
    <div>
      <div className="mb-1 font-medium text-slate-500">{title}</div>
      {items.map((i) => (
        <div key={i.id} className={cx('flex justify-between', muted ? 'text-slate-400 line-through' : 'text-slate-700')}>
          <span>{i.name}</span><span className="tabular-nums">{money(i.amount)}</span>
        </div>
      ))}
    </div>
  )
}

function ReplySection({ request }: { request: AdminRefundRequest }) {
  return (
    <section>
      <SectionTitle>Sent to customer</SectionTitle>
      <div className="space-y-2">
        {request.customer_reply && (
          <div className="whitespace-pre-line rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">{request.customer_reply}</div>
        )}
        {request.resolution_message && (
          <div className="whitespace-pre-line rounded-lg bg-brand-50 px-4 py-3 text-sm text-slate-700">
            <div className="mb-1 text-xs font-medium text-brand-700">Follow-up after review</div>
            {request.resolution_message}
          </div>
        )}
      </div>
    </section>
  )
}

const actorStyle: Record<AuditEvent['actor'], string> = {
  system: 'bg-slate-100 text-slate-600',
  ai: 'bg-brand-100 text-brand-700',
  policy_engine: 'bg-violet-100 text-violet-700',
  human: 'bg-emerald-100 text-emerald-700',
}

function AuditTimeline({ events }: { events: AuditEvent[] }) {
  const [open, setOpen] = useState<number | null>(null)
  return (
    <section>
      <SectionTitle>Audit trail</SectionTitle>
      <ol className="relative space-y-3 border-l border-slate-200 pl-5">
        {events.map((e) => (
          <li key={e.id} className="relative">
            <span className="absolute -left-[25px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-slate-300" />
            <div className="flex flex-wrap items-center gap-2 text-xs">
              <span className="font-medium text-slate-800">{humanize(e.stage)}</span>
              <span className={cx('rounded px-1.5 py-0.5 text-[10px] font-medium', actorStyle[e.actor])}>{humanize(e.actor)}</span>
              {e.duration_ms != null && <span className="text-slate-400">{e.duration_ms} ms</span>}
              <span className="text-slate-400">{dateTime(e.created_at)}</span>
            </div>
            <p className="text-sm text-slate-600">{e.summary}</p>
            {e.data && (
              <>
                <button onClick={() => setOpen(open === e.id ? null : e.id)} className="text-[11px] font-medium text-brand-600 hover:underline">
                  {open === e.id ? 'Hide data' : 'Show data'}
                </button>
                {open === e.id && (
                  <pre className="mt-1 max-h-64 overflow-auto rounded-md bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-100">
                    {JSON.stringify(e.data, null, 2)}
                  </pre>
                )}
              </>
            )}
          </li>
        ))}
      </ol>
    </section>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-slate-500">{label}</div>
      <div className="mt-0.5 font-medium text-slate-800">{value}</div>
    </div>
  )
}
