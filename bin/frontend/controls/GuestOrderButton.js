define('package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax',
    'Locale'

], function(QUI, QUIControl, QUIAjax, QUILocale) {
    'use strict';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton',

        Binds: [
            '$onImport'
        ],

        initialize: function(options) {
            this.parent(options);

            this.$steps = [];
            this.$currentStep = null;
            this.$Current = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function() {
            this.$steps = this.getElm().getElements('.step');
            this.next();

            this.getElm().getElement('[name="guest-order"]').addEvent('click', (e) => {
                e.stop();
                this.next();
            });

            this.getElm().getElement('[name="guest-order-enter-emailSubmit"]').addEvent('click', (e) => {
                e.stop();

                const Email = this.getElm().getElement('[name="guest-order-enter-email"]');

                if (Email.value === '') {
                    Email.focus();

                    return;
                }

                if ('checkValidity' in Email) {
                    if (!Email.checkValidity()) {
                        Email.reportValidity();
                        return;
                    }
                }

                this.next();
            });
        },

        next: function() {
            let StepNode = null;

            if (this.$currentStep === null) {
                this.$currentStep = 0;
                StepNode = this.$steps[0];
            } else {
                if (typeof this.$steps[this.$currentStep + 1] !== 'undefined') {
                    StepNode = this.$steps[this.$currentStep + 1];
                    this.$currentStep++;
                }
            }

            if (!StepNode) {
                if (this.$steps.length >= this.$currentStep) {
                    return this.submit();
                }

                return Promise.reject();
            }

            return this.$hide(this.$Current).then(() => {
                this.$Current = null;
                return this.$show(StepNode);
            }).then(() => {
                this.$Current = StepNode;

                if (StepNode.getElement('input')) {
                    StepNode.getElement('input').focus();
                }
            });
        },

        $hide: function(Node) {
            if (!Node) {
                return Promise.resolve();
            }

            return new Promise(function(resolve) {
                moofx(Node).animate({
                    opacity: 0
                }, {
                    duration: 200,
                    callback: () => {
                        Node.setStyle('display', 'none');
                        resolve();
                    }
                });
            });
        },

        $show: function(Node) {
            return new Promise((resolve) => {
                Node.setStyle('opacity', 0);
                Node.setStyle('display', null);

                moofx(Node).animate({
                    opacity: 1
                }, {
                    duration: 200,
                    callback: resolve
                });
            });
        },

        submit: function() {
            const Email = this.getElm().getElement('[name="guest-order-enter-email"]');
            const ProcessNode = this.getElm().getParent(
                '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
            );
            const OrderProcess = QUI.Controls.getById(ProcessNode.get('data-quiid'));

            if (Email.value === '') {
                return;
            }

            if ('checkValidity' in Email) {
                if (!Email.checkValidity()) {
                    return;
                }
            }

            OrderProcess.Loader.show();

            // email check
            // wenn email konto aktiv, bitte anmelden
            QUIAjax.get('package_quiqqer_order-guestorder_ajax_frontend_checkMail', function(mailExists) {
                if (mailExists) {
                    OrderProcess.Loader.hide();

                    QUI.getMessageHandler().then((MH) => {
                        MH.addInformation(
                            QUILocale.get('quiqqer/order-guestorder', 'mail.is.already.registered')
                        );
                    });

                    require([
                        'package/quiqqer/frontend-users/bin/frontend/controls/login/Window'
                    ], function(LoginWindow) {
                        new LoginWindow().open();
                    });

                    return;
                }

                QUIAjax.post('package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest', () => {
                    window.location.reload();
                }, {
                    'package': 'quiqqer/order-guestorder',
                    email: Email.value
                });
            }, {
                'package': 'quiqqer/order-guestorder',
                email: Email.value
            });
        }
    });
});
