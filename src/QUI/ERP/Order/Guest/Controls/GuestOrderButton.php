<?php

namespace QUI\ERP\Order\Guest\Controls;

use QUI;

use function dirname;

/**
 *
 */
class GuestOrderButton extends QUI\Control
{
    /**
     * @param $attributes
     */
    public function __construct($attributes = [])
    {
        $this->setAttributes([
            'data-qui' => 'package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton'
        ]);

        $this->addCSSFile(dirname(__FILE__) . '/GuestOrderButton.css');
        $this->addCSSClass('guest-order-login');

        parent::__construct($attributes);
    }

    /**
     * @return string
     * @throws QUI\Exception
     */
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        $Project = $this->getProject();

        // privacy and terms of use message
        /* @var $SiteTerms QUI\Projects\Site */
        /* @var $SitePrivacy QUI\Projects\Site */
        $SiteTerms = null;
        $SitePrivacy = null;

        // AGB
        $result = $Project->getSites([
            'where' => [
                'type' => 'quiqqer/sitetypes:types/generalTermsAndConditions'
            ],
            'limit' => 1
        ]);

        if (isset($result[0])) {
            $SiteTerms = $result[0];
        }

        // Privacy
        $result = $Project->getSites([
            'where' => [
                'type' => 'quiqqer/sitetypes:types/privacypolicy'
            ],
            'limit' => 1
        ]);

        if (isset($result[0])) {
            $SitePrivacy = $result[0];
        }

        $termsPrivacyMessage = QUI::getLocale()->get(
            'quiqqer/order-guestorder',
            'control.registration.terms_of_use.info'
        );

        if ($SiteTerms && $SitePrivacy) {
            $termsPrivacyMessage = QUI::getLocale()->get(
                'quiqqer/order-guestorder',
                'control.sign.up.terms_of_use_and_privacy_policy.label',
                [
                    'termsOfUseUrl' => $SiteTerms->getUrlRewritten(),
                    'termsOfUseSiteTitle' => $SiteTerms->getAttribute('title'),
                    'privacyPolicyUrl' => $SitePrivacy->getUrlRewritten(),
                    'privacyPolicySiteTitle' => $SitePrivacy->getAttribute('title')
                ]
            );
        } elseif ($SiteTerms) {
            $termsPrivacyMessage = QUI::getLocale()->get(
                'quiqqer/order-guestorder',
                'control.sign.up.terms_of_use.label',
                [
                    'termsOfUseUrl' => $SiteTerms->getUrlRewritten(),
                    'termsOfUseSiteTitle' => $SiteTerms->getAttribute('title')
                ]
            );
        } elseif ($SitePrivacy) {
            $termsPrivacyMessage = QUI::getLocale()->get(
                'quiqqer/order-guestorder',
                'control.sign.up.privacy_policy.label',
                [
                    'privacyPolicyUrl' => $SitePrivacy->getUrlRewritten(),
                    'privacyPolicySiteTitle' => $SitePrivacy->getAttribute('title')
                ]
            );
        }

        $Engine->assign([
            'termsPrivacyMessage' => $termsPrivacyMessage
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/GuestOrderButton.html');
    }
}
