device         = {};
cordova        = {
    platformId : "android",
    //platformId : "ios",

    exec          : function () {},
    getAppVersion : {
        getVersionCode   : function(){return new Promise(function(){})},
        getAppName       : function(){return new Promise(function(){})},
        getPackageName   : function(){return new Promise(function(){})},
        getVersionNumber : function(){return new Promise(function(){})},
    },
    InAppBrowser  : {
        open : function ( url ) {
            console.log(url);
            alert ( "Abrir: " + url );
            return {
                addEventListener : function () {},
                close            : function () {}
            }
        },
    },
    version       : false
};
FCMPlugin      = {
    getToken       : function () {},
    onNotification : function () {}
};
FirebasePlugin = {
    getToken          : function ( success, error ) {},
    onMessageReceived : function ( success, error ) {}
};

window = {
    plugin : {
        backgroundMode : {
            setEnabled : function () {},
            disable    : function () {},
            enable     : function () {}
        }
    },
    requestFileSystem : function( LocalFileSystem, error, successCallback ){
        successCallback( { root : "pasta_root" } );
    }
};

LocalFileSystem = {
    PERSISTENT : 1,
    TEMPORARY  : 0,
};

AppRate = {
    preferences     : {},
    promptForRating : function () {}
};

Idfa = {
    getInfo                            : function ( func ) {
        func ( {
            trackingLimited    : true,
            trackingPermission : 3,
        } );
    },
    requestPermission                  : function () {},
    TRACKING_PERMISSION_NOT_DETERMINED : 0,
    TRACKING_PERMISSION_RESTRICTED     : 1,
    TRACKING_PERMISSION_DENIED         : 2,
    TRACKING_PERMISSION_AUTHORIZED     : 3
};

KopereMediaNotification = {
    start             : function(data, success, error) {},
    update            : function(data, success, error) {},
    stop              : function(success, error) {},
    hasPermission     : function(success, error) {},
    requestPermission : function(success, error) {},
    startDownload     : function(data, success, error) {},
    updateDownload    : function(data, success, error) {},
    completeDownload  : function(data, success, error) {},
    failDownload      : function(data, success, error) {},
    cancelDownload    : function(data, success, error) {}
};

openApp = false;

function resolveLocalFileSystemURL( url, resolve, reject ){
    reject();
}

setTimeout( function(){
    var event = document.createEvent( 'CustomEvent' );
    event.initCustomEvent( 'deviceready' );
    document.dispatchEvent( event );
}, 500 );