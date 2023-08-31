define('package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton', [

    'qui/QUI',
    'qui/controls/Control',
    'Ajax'

], function(QUI, QUIControl, QUIAjax) {
    'use strict';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton',

        Binds: [
            '$onImport'
        ],

        initialize: function(options) {
            this.parent(options);

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function() {
            this.getElm().getElement('button').addEvent('click', (e) => {
                e.stop();

                const ProcessNode = this.getElm().getParent(
                    '[data-qui="package/quiqqer/order/bin/frontend/controls/OrderProcess"]'
                );

                const OrderProcess = QUI.Controls.getById(ProcessNode.get('data-quiid'));

                OrderProcess.Loader.show();

                QUIAjax.post('package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest', () => {
                    window.location.reload();
                }, {
                    'package': 'quiqqer/order-guestorder'
                });
            });
        }

    });
});