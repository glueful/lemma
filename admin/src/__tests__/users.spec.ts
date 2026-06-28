import { describe, it, expect, vi, beforeEach } from 'vitest'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))

import { fetchUser } from '@/queries/users'
import { generatePassword } from '@/lib/password'

describe('fetchUser', () => {
  beforeEach(() => authFetch.mockReset())

  it('GETs /v1/users/{uuid} and returns the record', async () => {
    authFetch.mockResolvedValue({ data: { uuid: 'u1', username: 'jdoe', roles: [] } })
    const u = await fetchUser('u1')
    expect(authFetch).toHaveBeenCalledWith('/v1/users/u1')
    expect(u.username).toBe('jdoe')
  })

  it('encodes the uuid', async () => {
    authFetch.mockResolvedValue({ data: { uuid: 'a/b' } })
    await fetchUser('a/b')
    expect(authFetch).toHaveBeenCalledWith('/v1/users/a%2Fb')
  })
})

describe('generatePassword', () => {
  it('returns a string of the requested length (default 16, min 12)', () => {
    expect(generatePassword()).toHaveLength(16)
    expect(generatePassword(20)).toHaveLength(20)
    expect(generatePassword(4).length).toBeGreaterThanOrEqual(12)
  })
  it('includes lower, upper, digit and symbol', () => {
    const p = generatePassword()
    expect(p).toMatch(/[a-z]/)
    expect(p).toMatch(/[A-Z]/)
    expect(p).toMatch(/[0-9]/)
    expect(p).toMatch(/[^A-Za-z0-9]/)
  })
})
