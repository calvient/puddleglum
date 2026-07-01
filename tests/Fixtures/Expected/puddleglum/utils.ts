/**
 * This file is auto generated using 'php artisan puddleglum:generate'
 *
 * Changes to this file will be lost when the command is run again
 */
export function transformToQueryString(params: Record<string, any>): string {
  return Object.entries(params)
    .filter(([, value]) => value !== null && value !== undefined)
    .map(([key, value]) => {
      if (Array.isArray(value)) {
        return value
          .map(
            (arrayItem) =>
              `${encodeURIComponent(key)}[]=${encodeURIComponent(arrayItem)}`,
          )
          .join('&');
      }

      return `${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
    })
    .join('&');
}

export type PaginatedResponse<T> = {
  current_page: number;
  data: T[];
  from: number;
  last_page: number;
  last_page_url: string | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
  next_page_url: string | null;
  per_page: number;
  prev_page_url: string | null;
  to: number;
  total: number;
};
