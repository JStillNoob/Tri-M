export function readSessionStorage(key, fallback) {
  try {
    return JSON.parse(sessionStorage.getItem(key)) ?? fallback
  } catch {
    return fallback
  }
}

export function writeSessionStorage(key, value) {
  try {
    sessionStorage.setItem(key, JSON.stringify(value))
  } catch {
    // The session can still use in-memory state.
  }
}
