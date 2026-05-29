import { api } from './client'

export interface RevenueCategory {
  id: number
  code: string
  label: string
  display_order: number
  archived: boolean
  invoices_count?: number
  created_at: string
}

export interface RevenueCategoryCreatePayload {
  code: string
  label: string
  display_order?: number
}

export const revenueCategoriesApi = {
  list: (includeArchived = false) =>
    api.get<RevenueCategory[]>('/revenue-categories', {
      params: includeArchived ? { include_archived: 1 } : undefined,
    }).then(r => r.data),
  create: (data: RevenueCategoryCreatePayload) =>
    api.post<RevenueCategory>('/revenue-categories', data).then(r => r.data),
  update: (id: number, data: Partial<RevenueCategoryCreatePayload> & { archived?: boolean }) =>
    api.put<RevenueCategory>(`/revenue-categories/${id}`, data).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: boolean; archived: boolean; usage_count?: number }>(
      `/revenue-categories/${id}`,
    ).then(r => r.data),
}
