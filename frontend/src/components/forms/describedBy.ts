/** Joins the ids of a control's hint and error for aria-describedby. */
export function describedBy(...ids: (string | false | undefined)[]): string | undefined {
  const value = ids.filter(Boolean).join(' ')
  return value || undefined
}
