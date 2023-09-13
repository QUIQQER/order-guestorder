define('package/quiqqer/order-guestorder/bin/frontend/controls/MissingAddressData', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/utils/Form',
    'Ajax'

], function(QUI, QUIControl, QUILoader, QUIFormUtils, QUIAjax) {
    'use strict';

    let LoopCheck = null;

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/order-guestorder/bin/frontend/controls/MissingAddressData',

        Binds: [
            '$onImport'
        ],

        initialize: function(options) {
            this.parent(options);

            this.Loader = new QUILoader();

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function() {
            const Data = this.getElm().getElement('.quiqqer-order-customerData-container');
            const Container = this.getElm().getElement('.customer-data');
            const Form = this.getElm().getElement('form');
            const Submit = this.getElm().getElement('[name="customer-data-save"]');

            this.Loader.inject(this.getElm());

            Form.addEvent('submit', (e) => {
                e.stop();
            });

            Submit.addEvent('click', () => {
                this.submit();
            });

            if (Data.get('data-quiid')) {
                Container.setStyle('display', 'inline');
            } else {
                LoopCheck = setInterval(() => {
                    if (Data.get('data-quiid')) {
                        if (LoopCheck) {
                            clearInterval(LoopCheck);
                        }

                        (() => {
                            Container.setStyle('opacity', 0);
                            Container.setStyle('display', 'inline');

                            this.getElm().getElement('.customer-data--spinner').destroy();

                            moofx(Container).animate({
                                opacity: 1
                            });
                        }).delay(500);
                    }
                }, 500);
            }
        },

        submit: function() {
            const Form = this.getElm().getElement('form');
            const formData = QUIFormUtils.getFormData(Form);

            this.Loader.show();

            QUIAjax.post('package_quiqqer_order-guestorder_ajax_frontend_submitCustomerData', () => {

                // @todo message

                this.Loader.hide();
            }, {
                'package': 'quiqqer/order-guestorder',
                orderHash: formData['order-hash'],
                data: JSON.encode(formData),
                onError: (err) => {
                    console.error(err.getMessage());
                    this.Loader.hide();
                }
            });
        }
    });
});
