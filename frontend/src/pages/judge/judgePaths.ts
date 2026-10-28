/** The judge portal paths the server serves (routes/web.php). */
export function isJudgePath(pathname: string): boolean {
  return pathname === '/judge' || /^\/judge\/projects\/\d+$/.test(pathname)
}
