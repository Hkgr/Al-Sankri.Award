/**
 * The rules that keep a judge appointment bound to the account the
 * administrator saw (AddJudgeDialog). Kept free of imports so the Node test
 * runner can load them directly.
 */

export function normalizeEmail(value: string): string {
  return value.trim().toLowerCase()
}

/**
 * A lookup answer may be shown only when it answers the latest lookup and
 * the field still holds the email it was asked for; an older or
 * out-of-order answer is dropped.
 */
export function isCurrentLookup(requestId: number, latestId: number, requestedEmail: string, currentEmail: string): boolean {
  return requestId === latestId && normalizeEmail(requestedEmail) === normalizeEmail(currentEmail)
}

export type DisplayedAccount = { account_ref: string; email: string }

/**
 * The appointment of a displayed existing account: its own normalized email
 * and the lookup's binding, never the text the field holds now. The server
 * refuses it if that email no longer names that exact account.
 */
export function existingAppointment(account: DisplayedAccount): { existing: true; email: string; account_ref: string } {
  return { existing: true, email: normalizeEmail(account.email), account_ref: account.account_ref }
}
