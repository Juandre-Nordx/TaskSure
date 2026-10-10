export function responseError(data, fallback = 'Something went wrong. Please try again.') {
  return Object.values(data?.errors || {}).flat().join(' ') || data?.message || fallback;
}
