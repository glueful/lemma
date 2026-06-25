// Client-side password generator for admin-created accounts. Uses crypto for unbiased picks and
// guarantees one of each class (lower/upper/digit/symbol). Length is clamped to a 12 minimum.
const LOWER = 'abcdefghijkmnpqrstuvwxyz'
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const DIGIT = '23456789'
const SYMBOL = '!@#$%^&*-_=+'
const ALL = LOWER + UPPER + DIGIT + SYMBOL

function pick(set: string): string {
  return set[crypto.getRandomValues(new Uint32Array(1))[0] % set.length]
}

export function generatePassword(length = 16): string {
  const len = Math.max(12, length)
  const required = [pick(LOWER), pick(UPPER), pick(DIGIT), pick(SYMBOL)]
  const rest = Array.from({ length: len - required.length }, () => pick(ALL))
  // Shuffle so the required chars aren't always in the first 4 positions.
  const chars = [...required, ...rest]
  for (let i = chars.length - 1; i > 0; i--) {
    const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }
  return chars.join('')
}
