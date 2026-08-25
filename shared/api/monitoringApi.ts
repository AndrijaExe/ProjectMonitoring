import {
  createApi,
  fetchBaseQuery,
  type BaseQueryFn,
  type FetchArgs,
  type FetchBaseQueryError,
} from '@reduxjs/toolkit/query/react'
import type {
  LogQueryArgs,
  OverviewResponse,
  ProjectDetail,
  ProjectLogs,
  ServiceAction,
  SessionResponse,
  ServiceStatus,
} from '../model/monitoring'
import { clearToken } from '../store/authSlice'
import { getApiBaseUrl } from './config'

type AuthRoot = {
  auth: { token: string | null }
}

const rawBaseQuery = fetchBaseQuery({
  // Resolved per request in baseQuery below, because the host is not known at module load.
  baseUrl: '',
  prepareHeaders: (headers, { getState }) => {
    const token = (getState() as AuthRoot).auth.token
    if (token) {
      headers.set('X-Admin-Token', token)
    }
    headers.set('Accept', 'application/json')
    return headers
  },
})

/**
 * True when the server said the token is fine but the request was out of scope.
 *
 * A read-only token gets this for anything that acts. Treating it as a dead token would sign
 * the operator out of the board over a button that should never have been offered.
 */
function isOutOfScope(error: FetchBaseQueryError): boolean {
  const data = (error as { data?: { error?: { code?: unknown } } }).data
  return data?.error?.code === 'FORBIDDEN'
}

const baseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = async (
  args,
  api,
  extraOptions,
) => {
  const prefix = getApiBaseUrl()
  const withHost = typeof args === 'string' ? { url: prefix + args } : { ...args, url: prefix + args.url }

  const result = await rawBaseQuery(withHost, api, extraOptions)
  // These two statuses are reserved for the token being unusable, because this is what happens
  // when one arrives: the session ends. An endpoint refusing a request for any other reason has
  // to say so with a different code, or it signs the operator out mid-incident.
  if (result.error && (result.error.status === 401 || result.error.status === 403)) {
    const url = typeof args === 'string' ? args : args.url
    if (!url.includes('/auth/login') && !isOutOfScope(result.error)) {
      api.dispatch(clearToken())
    }
  }
  return result
}

export const monitoringApi = createApi({
  reducerPath: 'monitoringApi',
  baseQuery,
  tagTypes: ['Overview', 'Project', 'Service'],
  endpoints: (builder) => ({
    login: builder.mutation<SessionResponse, string>({
      query: (token) => ({
        url: '/api/v1/auth/login',
        method: 'POST',
        body: { token },
      }),
    }),
    /**
     * Accepts either token, so this is how a client checks what its secret is worth. The phone
     * app uses it instead of login, which only takes the token that may act.
     */
    getSession: builder.query<SessionResponse, void>({
      query: () => '/api/v1/auth/session',
    }),
    getOverview: builder.query<OverviewResponse, void>({
      query: () => '/api/v1/overview',
      providesTags: ['Overview'],
    }),
    getProject: builder.query<ProjectDetail, string>({
      query: (gameId) => `/api/v1/projects/${gameId}`,
      providesTags: (_result, _error, gameId) => [{ type: 'Project', id: gameId }],
    }),
    getProjectLogs: builder.query<ProjectLogs, LogQueryArgs>({
      query: ({ gameId, level, text }) => {
        const params = new URLSearchParams()
        if (level) {
          params.set('level', level)
        }
        if (text) {
          params.set('text', text)
        }
        const search = params.toString()

        return `/api/v1/projects/${gameId}/logs${search === '' ? '' : `?${search}`}`
      },
    }),
    getSystemLogs: builder.query<ProjectLogs, Omit<LogQueryArgs, 'gameId'>>({
      query: ({ level, text }) => {
        const params = new URLSearchParams()
        if (level) {
          params.set('level', level)
        }
        if (text) {
          params.set('text', text)
        }
        const search = params.toString()

        return `/api/v1/system/logs${search === '' ? '' : `?${search}`}`
      },
    }),
    getServiceStatus: builder.query<ServiceStatus, string>({
      query: (gameId) => `/api/v1/projects/${gameId}/service`,
      providesTags: (_result, _error, gameId) => [{ type: 'Service', id: gameId }],
    }),
    controlService: builder.mutation<ServiceStatus, { gameId: string; action: ServiceAction }>({
      query: ({ gameId, action }) => ({
        url: `/api/v1/projects/${gameId}/service`,
        method: 'POST',
        body: { action },
      }),
      // The host reports the old state for a while after accepting, so the probes and the run
      // state are both worth asking about again rather than trusting this answer.
      invalidatesTags: (_result, _error, { gameId }) => [
        'Overview',
        { type: 'Project', id: gameId },
        { type: 'Service', id: gameId },
      ],
    }),
    clearHistory: builder.mutation<{ cleared: number }, string>({
      query: (gameId) => ({
        url: `/api/v1/projects/${gameId}/snapshots`,
        method: 'DELETE',
      }),
      invalidatesTags: (_result, _error, gameId) => ['Overview', { type: 'Project', id: gameId }],
    }),
    sendTestAlert: builder.mutation<{ sent: boolean; note: string }, void>({
      query: () => ({
        url: '/api/v1/alerts/test',
        method: 'POST',
      }),
    }),
    pollAll: builder.mutation<{ polled: number }, void>({
      query: () => ({
        url: '/api/v1/poll',
        method: 'POST',
      }),
      invalidatesTags: ['Overview', 'Project'],
    }),
    pollProject: builder.mutation<{ snapshots: unknown[] }, string>({
      query: (gameId) => ({
        url: `/api/v1/projects/${gameId}/poll`,
        method: 'POST',
      }),
      invalidatesTags: (_result, _error, gameId) => [
        'Overview',
        { type: 'Project', id: gameId },
      ],
    }),
  }),
})

export const {
  useLoginMutation,
  useGetSessionQuery,
  useLazyGetSessionQuery,
  useGetOverviewQuery,
  useGetProjectQuery,
  useGetProjectLogsQuery,
  useGetSystemLogsQuery,
  useGetServiceStatusQuery,
  useControlServiceMutation,
  useClearHistoryMutation,
  useSendTestAlertMutation,
  usePollAllMutation,
  usePollProjectMutation,
} = monitoringApi
