const fs = require('node:fs')
const path = require('node:path')

/**
 * Everything static lives in app.json. This file exists for one decision that cannot be static:
 * whether this machine has the Firebase file that Android push needs.
 *
 * google-services.json identifies one person's Firebase project, so it is not in the repository.
 * Naming it unconditionally in app.json would mean a clone could not build the app at all, and
 * the board is worth having without alerts. So the file is wired in when it is there, and left
 * out when it is not — the app already says "alerts off" when it cannot register.
 */
module.exports = ({ config }) => {
  const services = path.join(__dirname, 'google-services.json')

  if (fs.existsSync(services)) {
    config.android = { ...config.android, googleServicesFile: './google-services.json' }
  }

  return config
}
