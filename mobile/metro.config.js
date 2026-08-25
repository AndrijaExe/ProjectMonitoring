const path = require('node:path')
const { getDefaultConfig } = require('expo/metro-config')

const projectRoot = __dirname
const workspaceRoot = path.resolve(projectRoot, '..')
const sharedRoot = path.resolve(workspaceRoot, 'shared')

const config = getDefaultConfig(projectRoot)

// The API client and the store live above this app, so Metro has to watch there too or edits
// to them never reach a running bundler.
config.watchFolders = [sharedRoot]

// Dependencies are hoisted to the workspace root, and files in shared/ ask for React from
// there rather than from this folder.
config.resolver.nodeModulesPaths = [
  path.resolve(projectRoot, 'node_modules'),
  path.resolve(workspaceRoot, 'node_modules'),
]

const SHARED_PREFIX = '@shared/'
const defaultResolveRequest = config.resolver.resolveRequest

/**
 * Points @shared/... at the folder above.
 *
 * extraNodeModules would be the short way, but Metro reads a name beginning with @ as a scope
 * and would look for a package called "@shared/store", so the path is rewritten by hand.
 */
config.resolver.resolveRequest = (context, moduleName, platform) => {
  const resolve = defaultResolveRequest ?? context.resolveRequest

  if (moduleName.startsWith(SHARED_PREFIX)) {
    return resolve(context, path.join(sharedRoot, moduleName.slice(SHARED_PREFIX.length)), platform)
  }

  return resolve(context, moduleName, platform)
}

module.exports = config
