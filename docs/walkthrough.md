# Guided walkthrough (~6 minutes)

A route through the product that covers every evaluation area. It is also the script for the demo video.

## 0. Start (30s)
- Terminal: `docker compose up --build`. Point out the three services (`db`, `backend`, `web`) and the backend log line `AI mode: LLM enabled`.
- Browser: http://localhost:8080. The header badge shows the active AI mode.

## 1. Customer flow (2 min)
1. **Damaged item → Approved.** Click *Damaged item* (Amara, ORD-1001). The order card shows the verified items. Send the pre-filled message.
   - The reply shows the decision badge, the $129.00 amount and the reference number. The text is AI-written and matches the customer's tone.
   - The order card now shows the item as *Refunded*: the refund was executed before the reply was sent.
2. **Partial refund.** Click *Partial refund* (Kofi, ORD-1011). The final-sale jacket is excluded and only the $70 backpack is refunded. The reply explains why.
3. **Prompt injection → Escalated.** Click *Prompt injection* (Isabel, ORD-1009). The message tries to override the instructions, claims to be a manager, and asks for $750 on a $75 order.
   - It is escalated, and the customer reply is neutral: it doesn't reveal what was detected.
4. **High value → Escalated.** Click *High value* (David, $1,299 laptop). It goes to human review.
5. Optional: *Package in transit*, a denial with helpful guidance ("contact us after 10 days").

## 2. Support dashboard (2 min)
1. Open **Support dashboard** and sign in with `worknoon-admin`. Show the KPIs (auto-resolution rate, refunded total, risk-flagged) and the *Needs review* filter.
2. **Open Isabel's request:**
   - the customer message highlighted in red, with flags *Prompt injection* and *Amount conflict*
   - **AI classification:** the model's own `manipulation_attempt` flag, labelled as *claims, not facts*
   - **Policy evaluation:** `SEC_MANIPULATION` and `CLAIM_AMOUNT_MISMATCH` escalated; all other rules listed as passed
   - **Audit trail:** received → verification → injection scan (which patterns matched) → classification (model, tokens, latency) → policy → reply. Expand *Show data* on one step.
3. **Open David's request → Approve.** Enter a reviewer name and note, then approve $1,299. The audit trail gains a *Human* event. Back on the customer page, *Check for an update* shows the resolution.
4. **CRM:** open Farid to show the four refunded orders behind the *frequent refunder* rule.
5. **Refund policy page:** the published policy is rendered from the same config the engine enforces.

## 3. Architecture and AI (1.5 min)
Use the README diagram (§4) and make three points:
1. **The AI interprets, the rules decide.** The LLM (OpenAI or Claude) is called twice: a forced, schema-validated classification, then reply writing. A deterministic engine makes the decision from verified DB facts, so no prompt can talk its way into a refund.
2. **Layered injection defence:**
   - input normalisation
   - heuristic detector
   - delimited prompts with a random boundary ID
   - a model that only *records* a classification
   - output validation
   - the reply call never sees the raw message
   - an output guard
   - escalation to a human
3. **Resilience:** retries and backoff. With no key, or during an outage, the keyword fallback and templates take over, and the audit log shows why. Close with `php artisan test`: 63 tests, including every demo scenario and AI failure modes via `Http::fake`.

Reset between takes with **Reset demo data** on the dashboard.
