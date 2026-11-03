// React SPA (např. MVR) – npm i oidc-client-ts react-oidc-context
import { WebStorageStateStore } from 'oidc-client-ts'
import { AuthProvider, useAuth, type AuthProviderProps } from 'react-oidc-context'
import type { ReactNode } from 'react'

const authority = import.meta.env.VITE_OIDC_ISSUER // https://auth.klub.test (bez lomítka)

export const oidcConfig: AuthProviderProps = {
  authority,
  client_id: 'mvr-web',
  redirect_uri: `${window.location.origin}/auth/callback`,
  post_logout_redirect_uri: window.location.origin,
  scope: 'openid profile email roles teams offline_access',
  response_type: 'code', // PKCE (S256) řeší knihovna sama
  userStore: new WebStorageStateStore({ store: window.sessionStorage }),
  automaticSilentRenew: true, // obnova přes refresh_token
  onSigninCallback: () => {
    window.history.replaceState({}, document.title, window.location.pathname)
  },
}

export function ClubAuthProvider({ children }: { children: ReactNode }) {
  return <AuthProvider {...oidcConfig}>{children}</AuthProvider>
}

type ClubProfile = {
  sub: string
  name?: string
  roles?: string[]
  global_roles?: string[]
  teams?: { slug: string; name: string; membership: string }[]
}

/** Stejná logika jako ClubUser::hasRole na backendu. */
export function useClubUser() {
  const auth = useAuth()
  const profile = auth.user?.profile as ClubProfile | undefined

  const hasRole = (role: string, team?: string) => {
    if (!profile) return false
    if (profile.global_roles?.includes('superadmin')) return true
    if (profile.roles?.includes(role)) return true
    return team !== undefined && (profile.roles?.includes(`${role}@${team}`) ?? false)
  }

  return {
    isAuthenticated: auth.isAuthenticated,
    isLoading: auth.isLoading,
    profile,
    hasRole,
    login: () => auth.signinRedirect(),
    logout: () => auth.signoutRedirect(),
    accessToken: auth.user?.access_token,
  }
}

/** fetch na vlastní API s Bearer tokenem */
export async function apiFetch(token: string | undefined, input: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  return fetch(input, { ...init, headers })
}
