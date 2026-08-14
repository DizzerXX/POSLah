/**
 * Production configuration.
 *
 * The API origin is configuration, not a constant: the backoffice is served from somewhere
 * and the API lives somewhere else, and hardcoding one host into a compiled bundle means
 * rebuilding to point at a different environment.
 */
export const environment = {
  production: true,
  apiUrl: '/api-host-not-configured',
  /** Reverb, for the kitchen display. Populated per deployment. */
  reverb: { key: '', host: '', port: 443, scheme: 'https' as 'https' | 'http' },
};
