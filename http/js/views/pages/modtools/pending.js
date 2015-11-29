Iznik.Views.ModTools.Pages.Pending = Iznik.Views.Page.extend({
    modtools: true,

    template: "modtools_pending_main",

    fetch: function() {
        var self = this;
        self.$('.js-none').hide();
        this.msgs.fetch({
            data: {
                collection: 'Pending'
            }
        }).then(function() {
            if (self.msgs.length == 0) {
                self.$('.js-none').fadeIn('slow');
            } else {
                // CollectionView handles adding/removing/sorting for us.
                self.collectionView = new Backbone.CollectionView( {
                    el : self.$('.js-list'),
                    modelView : Iznik.Views.ModTools.Message.Pending,
                    collection : self.msgs
                } );

                self.collectionView.render();

                // Unfortunately collectionView doesn't have an event for when the length changes.
                function checkLength(self) {
                    var lastlen = 0;
                    return(function() {
                        if (lastlen != self.collectionView.length) {
                            console.log("Lengthchanged ", lastlen, self.collectionView.length);
                            lastlen = self.collectionView.length;
                            if (self.collectionView.length == 0) {
                                self.$('.js-none').fadeIn('slow');
                            } else {
                                self.$('.js-none').hide();
                            }
                        }

                        window.setTimeout(checkLength, 2000);
                    });
                }

                window.setTimeout(checkLength, 2000);
            }
        });
    },

    render: function() {
        Iznik.Views.Page.prototype.render.call(this);

        this.msgs = new Iznik.Collections.Message();

        // If we detect that the pending counts have changed on the server, refetch the messages so that we add/remove
        // appropriately.
        this.listenTo(Iznik.Session, 'pendingcountschanged', this.fetch);
        this.fetch();
    }
});

Iznik.Views.ModTools.Message.Pending = Iznik.Views.ModTools.Message.extend({
    template: 'modtools_pending_message',

    events: {
        'click .js-viewsource': 'viewSource',
        'click .js-rarelyused': 'rarelyUsed',
        'click .js-savesubj': 'saveSubject'
    },

    render: function() {
        var self = this;

        self.$el.html(window.template(self.template)(self.model.toJSON2()));
        _.each(self.model.get('groups'), function(group, index, list) {
            var mod = new IznikModel(group);

            // Add in the message, because we need some values from that
            mod.set('message', self.model.toJSON());

            var v = new Iznik.Views.ModTools.Message.Pending.Group({
                model: mod
            });
            self.$('.js-grouplist').append(v.render().el);

            var mod = new Iznik.Models.ModTools.User(self.model.get('fromuser'));
            var v = new Iznik.Views.ModTools.User({
                model: mod
            });

            self.$('.js-user').html(v.render().el);

            // The Yahoo part of the user
            var mod = IznikYahooUsers.findUser({
                email: self.model.get('envelopefrom') ? self.model.get('envelopefrom') : self.model.get('fromaddr'),
                group: group.nameshort,
                groupid: group.id
            });

            mod.fetch().then(function() {
                var v = new Iznik.Views.ModTools.Yahoo.User({
                    model: mod
                });
                self.$('.js-yahoo').html(v.render().el);
            });

            // Add any attachments.
            _.each(self.model.get('attachments'), function(att) {
                var v = new Iznik.Views.ModTools.Message.Photo({
                    model: new IznikModel(att)
                });

                self.$('.js-attlist').append(v.render().el);
            });

            // Add the default standard actions.
            var configs = Iznik.Session.get('configs');
            console.log("configs", configs, group.id, Iznik.Session.get('groups'));
            var sessgroup = Iznik.Session.get('groups').get(group.id);
            console.log("sessgroup", sessgroup);
            var config = configs.get(sessgroup.get('configid'));

            if (self.model.get('heldby')) {
                // Message is held - just show Release button.
                self.$('.js-stdmsgs').append(new Iznik.Views.ModTools.StdMessage.Button({
                    model: new IznikModel({
                        title: 'Release',
                        action: 'Release',
                        message: self.model,
                        messageView: self,
                        config: config
                    })
                }).render().el);
            } else {
                // Message is not held - we see all buttons.
                self.$('.js-stdmsgs').append(new Iznik.Views.ModTools.StdMessage.Button({
                    model: new IznikModel({
                        title: 'Approve',
                        action: 'Approve',
                        message: self.model,
                        messageView: self,
                        config: config
                    })
                }).render().el);

                self.$('.js-stdmsgs').append(new Iznik.Views.ModTools.StdMessage.Button({
                    model: new IznikModel({
                        title: 'Reject',
                        action: 'Reject',
                        message: self.model,
                        messageView: self,
                        config: config
                    })
                }).render().el);

                self.$('.js-stdmsgs').append(new Iznik.Views.ModTools.StdMessage.Button({
                    model: new IznikModel({
                        title: 'Delete',
                        action: 'Delete',
                        message: self.model,
                        messageView: self,
                        config: config
                    })
                }).render().el);

                self.$('.js-stdmsgs').append(new Iznik.Views.ModTools.StdMessage.Button({
                    model: new IznikModel({
                        title: 'Hold',
                        action: 'Hold',
                        message: self.model,
                        messageView: self,
                        config: config
                    })
                }).render().el);

                if (config) {
                    // Add the other standard messages, in the order requested.
                    var stdmsgs = config.get('stdmsgs');
                    var order = JSON.parse(config.get('messageorder'));
                    var sortmsgs = [];
                    _.each(order, function (id) {
                        var stdmsg = null;
                        _.each(stdmsgs, function (thisone) {
                            if (thisone.id == id) {
                                stdmsg = thisone;
                            }
                        });

                        if (stdmsg) {
                            sortmsgs.push(stdmsg);
                            stdmsgs = _.without(stdmsgs, stdmsg);
                        }
                    });

                    sortmsgs = $.merge(sortmsgs, stdmsgs);

                    _.each(sortmsgs, function (stdmsg) {
                        if (_.contains(['Approve', 'Reject', 'Delete', 'Leave', 'Edit'], stdmsg.action)) {
                            stdmsg.message = self.model;
                            stdmsg.messageView = self;
                            var v = new Iznik.Views.ModTools.StdMessage.Button({
                                model: new IznikModel(stdmsg),
                                config: config
                            });

                            var el = v.render().el;
                            self.$('.js-stdmsgs').append(el);

                            if (stdmsg.rarelyused) {
                                $(el).hide();
                            }
                        }
                    });
                }
            }

            // If the message is held or released, we re-render, showing the appropriate buttons.
            self.listenToOnce(self.model, 'change:heldby', self.render);
        });

        this.$('.timeago').timeago();
        this.checkDuplicates();
        this.$el.fadeIn('slow');

        // If we reject, approve or delete this message then the view should go.
        this.listenToOnce(self.model, 'approved rejected deleted', function() {
            self.$el.fadeOut('slow', function() {
                self.remove();
            });
        });

        return(this);
    }
});

Iznik.Views.ModTools.Message.Pending.Group = IznikView.extend({
    template: 'modtools_pending_group',

    render: function() {
        var self = this;
        self.$el.html(window.template(self.template)(self.model.toJSON2()));

        return(this);
    }
});

Iznik.Views.ModTools.StdMessage.Pending.Approve = Iznik.Views.ModTools.StdMessage.Modal.extend({
    template: 'modtools_pending_approve',

    events: {
        'click .js-send': 'send'
    },

    send: function() {
        this.model.approve(
            this.options.stdmsg.get('subjpref') ? this.$('.js-subject').val() : null,
            this.options.stdmsg.get('subjpref') ? this.$('.js-text').val() : null
        );
    },

    render: function() {
        this.expand();
        return(this);
    }
});

Iznik.Views.ModTools.StdMessage.Pending.Reject = Iznik.Views.ModTools.StdMessage.Modal.extend({
    template: 'modtools_pending_reject',

    events: {
        'click .js-send': 'send'
    },

    send: function() {
        this.model.reject(
            this.$('.js-subject').val(),
            this.$('.js-text').val()
        );
    },

    render: function() {
        this.expand();
        return(this);
    }
});
