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
} from '../../model/monitoring'
import { clearToken } from '../store/authSlice'

type AuthRoot = {
  auth: { token: string | null }
}

const rawBaseQuery = fetchBaseQuery({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? '',
  prepareHeaders: (headers, { getState }) => {
    const token = (getState() as AuthRoot).auth.token
    if (token) {
      headers.set('X-Admin-Token', token)
    }
    headers.set('Accept', 'application/json')
    return headers
  },
})

const baseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = async (
  args,
  api,
  extraOptions,
) => {
  const result = await rawBaseQuery(args, api, extraOptions)
  if (result.error && (result.error.status === 401 || result.error.status === 403)) {
    const url = typeof args === 'string' ? args : args.url
    if (!url.includes('/auth/login')) {
      api.dispatch(clearToken())
    }
  }
  return result
}

export const monitoringApi = createApi({
  reducerPath: 'monitoringApi',
  baseQuery,
  tagTypes: ['Overview', 'Project'],
  endpoints: (builder) => ({
    login: builder.mutation<{ authenticated: boolean }, string>({
      query: (token) => ({
        url: '/api/v1/auth/login',
        method: 'POST',
        body: { token },
      }),
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
  useGetOverviewQuery,
  useGetProjectQuery,
  useGetProjectLogsQuery,
  useGetSystemLogsQuery,
  useClearHistoryMutation,
  useSendTestAlertMutation,
  usePollAllMutation,
  usePollProjectMutation,
} = monitoringApi
