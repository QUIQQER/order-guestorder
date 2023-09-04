define('package/quiqqer/order-guestorder/bin/backend/controls/Settings', [

    'qui/QUI',
    'qui/controls/Control',
    'Locale'

], function(QUI, QUIControl, QUILocale) {
    'use strict';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/order-guestorder/bin/backend/controls/Settings',

        Binds: [
            '$onImport'
        ],

        initialize: function(options) {
            this.parent(options);

            this.$Table = null;
            this.$MaxSum = null;
            this.$MaxSumRow = null;

            this.addEvents({
                onImport: this.$onImport
            });
        },

        $onImport: function() {
            const Select = this.getElm();
            this.$Table = Select.getParent('table');

            this.$MaxSum = this.$Table.getElement('[name="guestorder.anonymous_max_sum"]');
            this.$MaxSumRow = this.$MaxSum.getParent('tr');

            this.hideAnonymousSettings();

            Select.addEvent('change', () => {
                switch (Select.value) {
                    case 'anonymous':
                        this.showAnonymousSettings();
                        break;

                    default:
                        this.hideAnonymousSettings();
                }
            });

            Select.fireEvent('change');
        },

        showAnonymousSettings: function() {
            this.$MaxSumRow.setStyle('display', null);
        },

        hideAnonymousSettings: function() {
            this.$MaxSumRow.setStyle('display', 'none');
        }
    });
});
