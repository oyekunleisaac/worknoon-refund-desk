import { Fragment, useEffect, useState } from 'react'
import { api, ApiError } from '../lib/api'
import type { Customer } from '../lib/types'
import { date, humanize, money, tier } from '../lib/format'
import { Alert, Card, Spinner, Tag } from '../components/ui'
import { cx } from '../lib/styles'

export default function AdminCustomers() {
  const [customers, setCustomers] = useState<Customer[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<number | null>(null)

  useEffect(() => {
    api.admin.customers().then(setCustomers).catch((e) => setError(e instanceof ApiError ? e.message : 'Failed to load.'))
  }, [])

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Mock CRM</h1>
        <p className="text-sm text-slate-500">Synthetic customers and order histories the refund engine reads from. Click a customer to see their orders.</p>
      </div>
      {error && <Alert>{error}</Alert>}
      <Card className="overflow-x-auto">
        {!customers ? (
          <div className="grid place-items-center py-16 text-slate-400"><Spinner className="h-6 w-6" /></div>
        ) : (
          <table className="min-w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-2.5">Customer</th>
                <th className="px-4 py-2.5">Tier</th>
                <th className="px-4 py-2.5">Since</th>
                <th className="px-4 py-2.5">Orders</th>
                <th className="px-4 py-2.5">Refund requests</th>
                <th className="px-4 py-2.5">Account</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {customers.map((c) => (
                <Fragment key={c.id}>
                  <tr onClick={() => setExpanded(expanded === c.id ? null : c.id)} className="cursor-pointer hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <div className="font-medium text-slate-800">{c.name}</div>
                      <div className="text-xs text-slate-500">{c.email} · {c.customer_number}</div>
                    </td>
                    <td className="px-4 py-3"><Tag tone={c.tier === 'vip' ? 'brand' : c.tier === 'gold' ? 'amber' : 'slate'}>{tier(c.tier)}</Tag></td>
                    <td className="px-4 py-3 text-slate-600">{date(c.customer_since)}</td>
                    <td className="px-4 py-3 text-slate-600">{c.orders.length}</td>
                    <td className="px-4 py-3 text-slate-600">{c.refund_requests_count}</td>
                    <td className="px-4 py-3">
                      {c.account_flagged ? <span title={c.flag_reason ?? ''}><Tag tone="rose">Flagged</Tag></span> : <Tag tone="emerald">Good standing</Tag>}
                      {c.notes && <div className="mt-1 text-xs text-slate-500">{c.notes}</div>}
                    </td>
                  </tr>
                  {expanded === c.id && (
                    <tr className="bg-slate-50/70">
                      <td colSpan={6} className="px-4 py-3">
                        {c.flag_reason && <div className="mb-2"><Alert tone="warning">{c.flag_reason}</Alert></div>}
                        <div className="grid gap-3 md:grid-cols-2">
                          {c.orders.map((o) => (
                            <div key={o.order_number} className="rounded-lg border border-slate-200 bg-white p-3">
                              <div className="flex items-center justify-between">
                                <span className="font-medium text-slate-800">{o.order_number}</span>
                                <Tag tone={o.status === 'delivered' ? 'emerald' : o.status.includes('refund') ? 'brand' : 'slate'}>{humanize(o.status)}</Tag>
                              </div>
                              <div className="mt-0.5 text-xs text-slate-500">
                                Placed {date(o.placed_at)} · {o.delivered_at ? `Delivered ${date(o.delivered_at)}` : `Carrier: ${humanize(o.carrier_status)}`}
                              </div>
                              <ul className="mt-2 space-y-1 text-xs">
                                {o.items?.map((i) => (
                                  <li key={i.id} className="flex justify-between gap-2">
                                    <span className={cx('text-slate-700', i.refunded && 'text-slate-400 line-through')}>
                                      {i.name} {i.final_sale && <Tag tone="rose">Final sale</Tag>}
                                    </span>
                                    <span className="tabular-nums text-slate-600">{money(i.unit_price * i.quantity)}</span>
                                  </li>
                                ))}
                              </ul>
                            </div>
                          ))}
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  )
}
