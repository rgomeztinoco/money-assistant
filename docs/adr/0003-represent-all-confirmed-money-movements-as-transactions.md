# Represent all confirmed money movements as Transactions

Status: Accepted.

Every confirmed posted movement is represented by one Transaction with two independent facts: Movement Direction records whether money moved out or in, while Transaction Kind records Spending, Refund or reimbursement, Income, Transfer, or Debt. Statement confirmation links every actual Statement Movement to a Transaction and continues to exclude balances, limits, headings, and other informational values.

Period reporting keeps currencies separate and exposes separate summaries: Net Spending is Spending minus Refunds and reimbursements; Income is separate; and Moved to Savings is outbound Savings Transfers minus inbound Savings Transfers. Card payments and ordinary internal Transfers affect none of these summaries. Debt principal contributes neither to Net Spending nor Income. Full repayments made and collected remain visible as separate period totals for each currency. The model does not calculate net external cash flow.

Spending and Refunds may use Spending Categories, Merchant Rules, Receipt Breakdowns, and Refund relationships. Income uses its separate Income Source taxonomy. Transfers use a Transfer Purpose, with Savings summarized and card payments or other internal Transfers excluded from Spending and Income.

The debt-management MVP in issues #255, #257, and #258 intentionally extends this decision with Debt and a limited DebtEntry obligation ledger. Opening balances, explicit dated interest charges, and signed non-cash adjustments create no Transaction. Additional borrowing, lending, repayments, and collections reuse one Transaction for each actual posted movement. Explicit owner allocation preserves a Transaction's identity, full posted amount, source references, currency, and independent Movement Direction. Corrections, voiding, and restoration keep its obligation effect and reports consistent.

A repayment has an exact debt-specific principal/interest allocation, without repurposing Receipt Breakdown. Paid interest contributes to Net Spending and may use an owner-assigned Spending Category; received interest contributes to Income under the fixed Interest received source. The full repayment reduces the obligation once. A newly confirmed interest charge can be saved atomically with a payment but remains independent when the payment is edited, reassigned, unlinked, or voided. An incorrect charge is corrected with a dated signed balance adjustment.

Optional monthly targets compare with full non-voided repayments in the current Lima calendar month. Outgoing and incoming commitments and currencies remain separate. Settled debts add no active commitment. Home shows current outstanding balances separately from selected-period financial totals. Financial MCP exposes owner-scoped read-only debt and allocation reads under the existing financial-data permission and source privacy contract.

This extension does not introduce full account balances, net worth, home-equity accounting, a general accounting engine, or automatic migration of mortgage and card-payment classifications. A full mortgage payment may remain Spending under Housing. It supersedes ADR-0001 and ADR-0002.
