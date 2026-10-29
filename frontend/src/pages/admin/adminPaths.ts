/** The administration paths the server serves (routes/web.php); App.tsx sends only these to AdminApp. */
export function isAdminPath(pathname: string): boolean {
  return ['/admin', '/admin/users', '/admin/applications', '/admin/committees', '/admin/schedule', '/admin/log'].includes(pathname) || /^\/admin\/applications\/\d+$/.test(pathname)
}
