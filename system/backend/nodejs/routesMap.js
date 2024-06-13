const routesMap = {
  post: {
    login: require('./routes/login.js'),
    logout: require('./routes/logout.js'),
    revertCommit: require('./routes/revertCommit.js'),
    refreshAccessToken: require('./routes/refreshAccessToken.js'),

    formLoad: require('./routes/formLoad.js'),
    formProcess: require('./routes/formProcess.js'),
    getConfig: require('./routes/getConfig.js'),
    setConfig: require('./routes/setConfig.js'),
    getNodeFields: require('./routes/getNodeFields.js'),
    setUserPhoto: require('./routes/setUserPhoto.js'),
    siteUpdateAlternateFormats: require('./routes/siteUpdateAlternateFormats.js'),
    getUserData: require('./routes/getUserData.js'),
    gitImportSite: require('./routes/gitImportSite.js'),
    listFiles: require('./routes/listFiles.js'),
    saveFile: require('./routes/saveFile.js'),
    saveManifest: require('./routes/saveManifest.js'),
    saveOutline: require('./routes/saveOutline.js'),
    openapi: require('./routes/openapi.js'),

    createSite: require('./routes/createSite.js'),
    syncSite: require('./routes/syncSite.js'),
    publishSite: require('./routes/publishSite.js'),
    cloneSite: require('./routes/cloneSite.js'),
    archiveSite: require('./routes/archiveSite.js'),
    deleteSite: require('./routes/deleteSite.js'),
    downloadSite: require('./routes/downloadSite.js'),

    createNode: require('./routes/createNode.js'),
    saveNode: require('./routes/saveNode.js'),
    deleteNode: require('./routes/deleteNode.js'),
    getSitesList: require('./routes/listSites.js'),
  },
  get: {
    logout: require('./routes/logout.js'),
    connectionSettings: require('./routes/connectionSettings.js'),
    generateAppStore: require('./routes/generateAppStore.js'),
  },
};

module.exports = routesMap;