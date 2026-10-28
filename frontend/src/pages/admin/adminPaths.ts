/** The administration paths the server serves (routes/web.php); App.tsx sends only these to AdminApp. */
export function isAdminPath(pathname: string): boolean {
  return ['/admin', '/admin/applications', '/admin/committees'].includes(pathname) || /^\/admin\/applications\/\d+$/.test(pathname)
}
