function logoutRoute(req, res)  {
    res.send({
        "status" : 200,
        "data" : 'loggedout',
    })
}

module.exports = logoutRoute;