# Separate Statement Movements from spending Transactions

Status: Superseded by ADR-0003.

A Statement Import retains every posted Statement Movement, but creates a linked Transaction only when that movement contributes to spending. This keeps income, card payments, and ordinary transfers visible in the import without leaking them into spending reports, the Review Queue, Merchant Rules, Refund relationships, or receipt behavior, all of which assume that a Transaction is a purchase or Refund.
