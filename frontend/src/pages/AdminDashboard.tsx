import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../lib/api'
import type { AdminRefundRequest, Paginated, Stats } from '../lib/types'
import { humanize, money, relative } from '../lib/format'
import { Alert, Button, Card, DecisionBadge, Spinner, StatusBadge, Tag } from '../components/ui'
import { cx, inputClass } from '../lib/styles'
import RequestDrawer from '../components/RequestDrawer'

const filters = [
  { key: 'all', label: 'All', params: {} },
  { key: 'review', label: 'Needs review', params: { status: 'pending_review' } },
  { key: 'approved', label: 'Approved', params: { decision: 'approved' } },
  { key: 'denied', label: 'Denied', params: { decision: 'denied' } },
  { key: 'escalated', label: 'Escalated', params: { decision: 'escalated' } },
  { key: 'flagged', label: 'Flagged', params: { flagged: '1' } },
] as const

export default function AdminDashboard() {
  const [stats, setStats] = useState<Stats | null>(null)
  const [list, setList] = useState<Paginated<AdminRefundRequest> | null>(null)
  const [filter, setFilter] = useState<(typeof filters)[number]['key']>('all')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [resetting, setResetting] = useState(false)
  const [confirmReset, setConfirmReset] = useState(false)

  const load = useCallback(async () => {
    const params: Record<string, string> = { ...filters.find((f) => f.key === filter)!.params, page: String(page), per_page: '15' }
    if (search.trim()) params.search = search.trim()
    try {
      const [s, l] = await Promise.all([api.admin.stats(), api.admin.requests(params)])
      setStats(s)
      setList(l)
      setError(null)
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Failed to load dashboard.')
    }
  }, [filter, search, page])

  useEffect(() => {
    load()
    const timer = setInterval(load, 10_000)   // keep the queue live while agents work
    return () => clearInterval(timer)
  }, [load])

  const resetDemo = async () => {
    setResetting(true)
    try {
      await api.admin.resetDemo()
      setConfirmReset(false)
      setSelected(null)
      await load()
    } finally {
      setResetting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Refund requests</h1>
          <p className="text-sm text-slate-500">Every decision, the reasoning behind it, and the queue of requests waiting for a person.</p>
        </div>
        <div className="flex items-center gap-2">
          {confirmReset ? (
            <>
              <span className="text-xs text-slate-600">Delete all requests and restore mock data?</span>
              <Button variant="danger" onClick={resetDemo} loading={resetting}>Reset</Button>
              <Button variant="ghost" onClick={() => setConfirmReset(false)}>Cancel</Button>
            </>
          ) : (
            <Button variant="secondary" onClick={() => setConfirmReset(true)}>Reset demo data</Button>
          )}
        </div>
      </div>

      {error && <Alert>{error}</Alert>}
      <StatGrid stats={stats} />

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div className="flex flex-wrap gap-1">
            {filters.map((f) => (
              <button
                key={f.key}
                onClick={() => { setFilter(f.key); setPage(1) }}
                className={cx('rounded-md px-2.5 py-1 text-sm font-medium transition',
                  filter === f.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100')}
              >
                {f.label}
                {f.key === 'review' && stats?.pending_review ? <span className="ml-1.5 rounded-full bg-amber-400 px-1.5 text-[11px] text-slate-900">{stats.pending_review}</span> : null}
              </button>
            ))}
          </div>
          <input
            className={cx(inputClass, 'max-w-xs')}
            placeholder="Search reference, email, order…"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          />
        </div>
        <RequestTable list={list} onSelect={setSelected} selected={selected} />
        {list && list.meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-2 text-sm text-slate-500">
            <span>Page {list.meta.current_page} of {list.meta.last_page} · {list.meta.total} requests</span>
            <div className="flex gap-2">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
              <Button variant="secondary" disabled={page >= list.meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
            </div>
          </div>
        )}
      </Card>

      {selected !== null && (
        <RequestDrawer key={selected} id={selected} onClose={() => setSelected(null)} onReviewed={load} />
      )}
    </div>
  )
}

function StatGrid({ stats }: { stats: Stats | null }) {
  const cards = [
    { label: 'Total requests', value: stats?.total ?? '—' },
    { label: 'Approved', value: stats?.approved ?? '—', tone: 'text-emerald-600' },
    { label: 'Denied', value: stats?.denied ?? '—', tone: 'text-rose-600' },
    { label: 'Escalated', value: stats?.escalated ?? '—', tone: 'text-amber-600' },
    { label: 'Awaiting review', value: stats?.pending_review ?? '—', tone: stats?.pending_review ? 'text-amber-600' : '' },
    { label: 'Auto-resolved', value: stats?.automation_rate != null ? `${Math.round(stats.automation_rate * 100)}%` : '—' },
    { label: 'Refunded', value: stats ? money(stats.refunded_amount) : '—' },
    { label: 'Risk-flagged', value: stats?.flagged ?? '—', tone: stats?.flagged ? 'text-rose-600' : '' },
  ]
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
      {cards.map((c) => (
        <Card key={c.label} className="px-4 py-3">
          <div className="text-xs text-slate-500">{c.label}</div>
          <div className={cx('mt-1 text-xl font-semibold text-slate-900', c.tone)}>{c.value}</div>
        </Card>
      ))}
    </div>
  )
}

function RequestTable({ list, onSelect, selected }: { list: Paginated<AdminRefundRequest> | null; onSelect: (id: number) => void; selected: number | null }) {
  if (!list) return <div className="grid place-items-center py-16 text-slate-400"><Spinner className="h-6 w-6" /></div>
  if (list.data.length === 0) {
    return <p className="px-4 py-16 text-center text-sm text-slate-500">No refund requests yet. Submit one from the customer support page.</p>
  }
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-4 py-2.5">Request</th>
            <th className="px-4 py-2.5">Customer</th>
            <th className="px-4 py-2.5">Reason</th>
            <th className="px-4 py-2.5">Decision</th>
            <th className="px-4 py-2.5 text-right">Amount</th>
            <th className="px-4 py-2.5">Signals</th>
            <th className="px-4 py-2.5 text-right">When</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {list.data.map((r) => (
            <tr
              key={r.id}
              onClick={() => onSelect(r.id)}
              className={cx('cursor-pointer transition hover:bg-slate-50', selected === r.id && 'bg-brand-50/60')}
            >
              <td className="px-4 py-3">
                <div className="font-mono text-xs font-medium text-slate-900">{r.reference}</div>
                <div className="text-xs text-slate-500">{r.submitted_order_number}</div>
              </td>
              <td className="px-4 py-3">
                <div className="text-slate-800">{r.customer?.name ?? <span className="text-rose-600">Unverified</span>}</div>
                <div className="max-w-[220px] truncate text-xs text-slate-500" title={r.summary ?? r.message}>{r.summary ?? r.message}</div>
              </td>
              <td className="px-4 py-3 text-slate-600">{humanize(r.reason_category)}</td>
              <td className="px-4 py-3">
                <div className="flex flex-col items-start gap-1">
                  <DecisionBadge decision={r.decision} />
                  <StatusBadge status={r.status} />
                </div>
              </td>
              <td className="px-4 py-3 text-right tabular-nums text-slate-800">{r.decision === 'denied' ? '—' : money(r.refund_amount)}</td>
              <td className="px-4 py-3">
                <div className="flex max-w-[200px] flex-wrap gap-1">
                  {r.risk_flags.map((f) => <Tag key={f} tone="rose">{humanize(f)}</Tag>)}
                  {r.ai_mode && <Tag tone={r.ai_mode === 'llm' ? 'brand' : 'amber'}>{r.ai_mode === 'llm' ? 'AI' : 'Fallback'}</Tag>}
                </div>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-right text-xs text-slate-500">{relative(r.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
