// Metro is the tool that bundles the app's code. By default it only looks
// inside this folder; the shared code in ../packages/core (used by the
// website chat too) lives outside it, so tell Metro to watch that as well.
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);
config.watchFolders = [...(config.watchFolders ?? []), path.resolve(__dirname, '../packages/core')];

module.exports = config;
