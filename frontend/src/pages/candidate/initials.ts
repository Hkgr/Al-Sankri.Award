const LATIN_LETTER = /^\p{Script=Latin}/u

/**
 * One or two initials for the avatar: two when the first and last names
 * both start with a Latin letter, one otherwise, because Arabic letters
 * placed side by side would join into a word.
 */
export function initialsOf(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '؟'
  const first = Array.from(words[0])[0]
  const last = Array.from(words[words.length - 1])[0]
  if (words.length > 1 && LATIN_LETTER.test(first) && LATIN_LETTER.test(last)) return `${first}${last}`.toUpperCase()
  return first.toUpperCase()
}
