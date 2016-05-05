// TODO Some of these requires could be moved further down into modules which use them.  This would speed loading
// of the user site.
define([
    'jquery',
    'underscore',
    'backbone',
    'iznik/base',
    'iznik/accordionpersist',
    'iznik/selectpersist',
    'iznik/models/session',
    'iznik/models/message',
    'iznik/models/location',
    'iznik/models/group',
    'iznik/models/config/modconfig',
    'iznik/models/config/stdmsg',
    'iznik/models/config/bulkop',
    'iznik/models/spammer',
    'iznik/models/user/user',
    'iznik/models/yahoo/user',
    'iznik/models/membership',
    'iznik/models/user/message',
    'iznik/views/modal',
    'iznik/views/user/user',
    'iznik/views/plugin',
    'iznik/views/help',
    'iznik/views/signinup',
    'iznik/views/dashboard',
    'iznik/views/yahoo/user',
    'iznik/views/group/select',
    'iznik/views/pages/modtools/messages'
], function($, _, Backbone, Iznik) {
    Iznik.Session = new Iznik.Models.Session();

    var IznikRouter = Backbone.Router.extend({
        initialize: function () {
            var self = this;

            // We want the ability to abort all outstanding requests, for example when we switch to a new route.
            self.xhrPool = [];
            self.abortAll = function() {
                _.each(self.xhrPool, function(jqXHR) {
                    try {
                        jqXHR.abort();
                    } catch (e) {}
                });
                self.xhrPool = [];
            };

            $.ajaxSetup({
                beforeSend: function(jqXHR) {
                    self.xhrPool.push(jqXHR);
                },
                complete: function(jqXHR) {
                    var index = self.xhrPool.indexOf(jqXHR);
                    if (index > -1) {
                        self.xhrPool.splice(index, 1);
                    }
                }
            });

            this.bind('route', this.pageView);
        },

        pageView: function () {
            var url = Backbone.history.getFragment();

            if (!/^\//.test(url) && url != "") {
                url = "/" + url;
            }

            // Make sure we have google analytics for Backbone routes.
            require(["ga"], function(ga) {
                ga('create', 'UA-10627716-9');
                ga('send', 'event', 'pageView', url);
            });
        },

        routes: {
            "yahoologin": "yahoologin",
            "modtools": "modtools",
            "modtools/supporters": "supporters",
            "modtools/messages/pending": "pendingMessages",
            "modtools/messages/approved/messagesearch/:search": "approvedMessagesSearchMessages",
            "modtools/messages/approved/membersearch/:search": "approvedMessagesSearchMembers",
            "modtools/messages/approved": "approvedMessages",
            "modtools/messages/spam": "spamMessages",
            "modtools/members/pending(/:search)": "pendingMembers",
            "modtools/members/approved(/:search)": "approvedMembers",
            "modtools/members/spam": "spamMembers",
            "modtools/spammerlist/pendingadd(/:search)": "spammerListPendingAdd",
            "modtools/spammerlist/confirmed(/:search)": "spammerListConfirmed",
            "modtools/spammerlist/pendingremove(/:search)": "spammerListPendingRemove",
            "modtools/spammerlist/whitelisted(/:search)": "spammerListWhitelisted",
            "modtools/settings/:id/map": "mapSettings",
            "modtools/settings/confirmmail/(:key)": "confirmMail",
            "modtools/settings": "settings",
            "modtools/support": "support",
            "modtools/replay/(:id)": "replay",
            "find/whereami": "userFindWhereAmI",
            "find/search/(:search)": "userSearched",
            "find/search": "userSearch",
            "give/whereami": "userGiveWhereAmI",
            "give/whatisit": "userGiveWhatIsIt",
            "give/whoami": "userGiveWhoAmI",
            "give/whatnext": "userWhatNext",
            "post": "userHome", // legacy route
            "*path": "userHome"
        },

        loadRoute: function (routeOptions) {
            var self = this;

            // We're no longer interested in any outstanding requests, and we also want to avoid them clogging up
            // our per-host limit.
            self.abortAll();

            // Tidy any modal grey.
            $('.modal-backdrop').remove();

            // The top button might be showing.
            $('.js-scrolltop').addClass('hidden');

            //console.log("loadRoute"); console.log(routeOptions);
            routeOptions = routeOptions || {};

            self.modtools = routeOptions.modtools;

            function loadPage() {
                firstbeep = true;

                // Hide the page loader, which might still be there.
                $('#pageloader').remove();

                routeOptions.page.render();
            }

            loadPage();
        },

        userHome: function () {
            var self = this;

            if (document.URL.indexOf('modtools') !== -1) {
                Router.navigate('/modtools', true);
            } else {
                self.listenToOnce(Iznik.Session, 'isLoggedIn', function (loggedIn) {
                    if (loggedIn) {
                        $('body').addClass('bodyback');
                        require(["iznik/views/pages/user/home"], function() {
                            var page = new Iznik.Views.User.Pages.Home();
                            self.loadRoute({page: page});
                        });
                    } else {
                        require(["iznik/views/pages/user/landing"], function() {
                            var page = new Iznik.Views.User.Pages.Landing();
                            self.loadRoute({page: page});
                        });
                    }
                });

                Iznik.Session.testLoggedIn();
            }
        },

        userFindWhereAmI: function () {
            var self = this;

            require(["iznik/views/pages/user/find"], function() {
                var page = new Iznik.Views.User.Pages.Find.WhereAmI();
                self.loadRoute({page: page});
            });
        },

        userSearch: function () {
            var self = this;

            require(["iznik/views/pages/user/find"], function() {
                var page = new Iznik.Views.User.Pages.Find.Search();
                self.loadRoute({page: page});
            });
        },

        userSearched: function (query) {
            var self = this;

            require(["iznik/views/pages/user/find"], function() {
                var page = new Iznik.Views.User.Pages.Find.Search({
                    search: query
                });

                self.loadRoute({page: page});
            });
        },

        userGiveWhereAmI: function () {
            var self = this;

            require(["iznik/views/pages/user/give"], function() {
                var page = new Iznik.Views.User.Pages.Give.WhereAmI();
                self.loadRoute({page: page});
            });
        },

        userGiveWhatIsIt: function () {
            var self = this;

            require(["iznik/views/pages/user/give"], function() {
                var page = new Iznik.Views.User.Pages.Give.WhatIsIt();
                self.loadRoute({page: page});
            });
        },

        userGiveWhoAmI: function () {
            var self = this;

            require(["iznik/views/pages/user/give"], function() {
                var page = new Iznik.Views.User.Pages.Give.WhoAmI();
                self.loadRoute({page: page});
            });
        },

        userWhatNext: function () {
            var self = this;

            require(["iznik/views/pages/user/give"], function() {
                var page = new Iznik.Views.User.Pages.Give.WhatNext();
                self.loadRoute({page: page});
            });
        },

        getURLParam: function (name) {
            name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
            var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
                results = regex.exec(location.search);
            return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
        },

        yahoologin: function (path) {
            var self = this;

            // We have been redirected here after an attempt to sign in with Yahoo.  We now try again to login
            // on the server.  This time we should succeed.
            var returnto = this.getURLParam('returnto');

            self.listenToOnce(Iznik.Session, 'yahoologincomplete', function (ret) {
                if (ret.ret == 0) {
                    if (returnto) {
                        window.location = returnto;
                    } else {
                        self.home.call(self);
                    }
                } else {
                    // TODO
                    window.location = '/';
                }
            });

            Iznik.Session.yahooLogin();
        },

        modtools: function () {
            var self = this;
            require(["iznik/views/pages/modtools/landing"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.Landing();
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        supporters: function () {
            var page = new Iznik.Views.ModTools.Pages.Supporters();
            this.loadRoute({page: page});
        },

        pendingMessages: function () {
            var self = this;

            require(["iznik/views/pages/modtools/messages_pending"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.PendingMessages();
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spamMessages: function () {
            var self = this;

            require(["iznik/views/pages/modtools/messages_spam"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpamMessages();
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        approvedMessagesSearchMessages: function (search) {
            this.approvedMessages(search, null);
        },

        approvedMessagesSearchMembers: function (search) {
            this.approvedMessages(null, search);
        },

        approvedMessages: function (searchmess, searchmemb) {
            var self = this;

            require(["iznik/views/pages/modtools/messages_approved"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.ApprovedMessages({
                        searchmess: searchmess,
                        searchmemb: searchmemb
                    });
                    self.loadRoute({
                        page: page,
                        modtools: true
                    });
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        pendingMembers: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/members_pending", "iznik/views/pages/modtools/messages_pending"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.PendingMembers({
                        search: search
                    });
                    self.loadRoute({
                        page: page,
                        modtools: true
                    });
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        approvedMembers: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/members_approved"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.ApprovedMembers({
                        search: search
                    });
                    self.loadRoute({
                        page: page,
                        modtools: true
                    });
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spamMembers: function () {
            var self = this;

            require(["iznik/views/pages/modtools/members_spam"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpamMembers();
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spammerListPendingAdd: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/spammerlist"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpammerList({
                        search: search,
                        urlfragment: 'pendingadd',
                        collection: 'PendingAdd',
                        helpTemplate: 'modtools_spammerlist_help_pendingadd'
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spammerListPendingRemove: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/spammerlist"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpammerList({
                        search: search,
                        urlfragment: 'pendingremove',
                        collection: 'PendingRemove',
                        helpTemplate: 'modtools_spammerlist_help_pendingremove'
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spammerListConfirmed: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/spammerlist"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpammerList({
                        search: search,
                        urlfragment: 'confirmed',
                        collection: 'Spammer',
                        helpTemplate: 'modtools_spammerlist_help_confirmed'
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        spammerListWhitelisted: function (search) {
            var self = this;

            require(["iznik/views/pages/modtools/spammerlist"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.SpammerList({
                        search: search,
                        urlfragment: 'whitelisted',
                        collection: 'Whitelisted',
                        helpTemplate: 'modtools_spammerlist_help_whitelisted'
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        support: function () {
            var self = this;

            require(["iznik/views/pages/modtools/support"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    if (!Iznik.Session.isAdminOrSupport()) {
                        // You're not supposed to be here, are you?
                        Router.navigate('/', true);
                    } else {
                        var page = new Iznik.Views.ModTools.Pages.Support();
                        this.loadRoute({page: page, modtools: true});
                    }
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        confirmMail: function (key) {
            var self = this;

            require(["iznik/views/pages/modtools/settings"], function() {
                $.ajax({
                    type: 'PATCH',
                    url: API + 'session',
                    data: {
                        key: key
                    },
                    success: function (ret) {
                        var v;

                        if (ret.ret == 0) {
                            v = new Iznik.Views.ModTools.Settings.VerifySucceeded();
                        } else {
                            v = new Iznik.Views.ModTools.Settings.VerifyFailed();
                        }
                        self.listenToOnce(v, 'modalCancelled modalClosed', function () {
                            Router.navigate('/modtools/settings', true)
                        });

                        v.render();
                    },
                    error: function () {
                        var v = new Iznik.Views.ModTools.Settings.VerifyFailed();
                        self.listenToOnce(v, 'modalCancelled modalClosed', function () {
                            Router.navigate('/modtools/settings', true)
                        });

                        v.render();
                    }
                });
            });
        },

        settings: function () {
            var self = this;

            require(["iznik/views/pages/modtools/settings"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.Settings();
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        mapSettings: function (groupid) {
            var self = this;

            require(["iznik/views/pages/modtools/settings"], function() {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.MapSettings({
                        groupid: groupid
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        },

        replay: function(sessionid) {
            var self = this;
            require(["iznik/views/pages/modtools/replay"], function () {
                self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                    var page = new Iznik.Views.ModTools.Pages.Replay({
                        sessionid: sessionid
                    });
                    self.loadRoute({page: page, modtools: true});
                });

                Iznik.Session.forceLogin({
                    modtools: true
                });
            });
        }
    });

    // We're ready.  Get backbone up and running.
    var Router = new IznikRouter();

    try {
        Backbone.history.start({
            pushState: true
        });
    } catch (e) {
        // We've got an uncaught exception.
        // TODO Log it to the server.
        window.alert("Top-level exception " + e);
        console.log("Top-level exception", e);
        console.trace();
    }

    if (document.URL.indexOf('action=') !== -1) {
        Router.navigate('/modtools', true);
    }

    // We can flag anchors as not to be handled via Backbone using data-realurl
    $(document).on('click', 'a:not([data-realurl]):not([data-toggle])', function (evt) {
        // Only trigger for our own anchors, except selectpicker which relies on #.
        // console.log("a click", $(this), $(this).parents('#bodyEnvelope').length);
        if ($(this).parents('#bodyEnvelope').length > 0 &&
            $(this).parents('.selectpicker').length == 0) {
            evt.preventDefault();
            evt.stopPropagation();
            var href = $(this).attr('href');
            var ret = Router.navigate(href, {trigger: true});

            if (ret === undefined && $link.hasClass('allow-reload')) {
                Backbone.history.loadUrl(href);
            }
        }
    });

    window.Router = Router;
});