<?php

namespace QUI\ERP\Accounting\Invoice\Utils;

if (!class_exists(Invoice::class)) {
    class Invoice
    {
        /**
         * @param array<string, mixed> $address
         * @return list<string>
         */
        public static function getMissingAddressData(array $address): array
        {
            $missing = [];

            if (empty($address['lastname']) && !empty($address['company'])) {
                $address['lastname'] = $address['company'];
            }

            foreach (['lastname', 'street_no', 'city', 'country'] as $name) {
                if (empty($address[$name])) {
                    $missing[] = 'invoice_address_' . $name;
                }
            }

            return $missing;
        }

        public static function getMissingAttributeMessage(string $missingAttribute): string
        {
            return $missingAttribute;
        }
    }
}
