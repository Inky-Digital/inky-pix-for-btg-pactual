/**
 * Registra o PIX do BTG no checkout por blocos.
 *
 * O bloco é apenas visual: a cobrança continua sendo criada pelo gateway no
 * servidor, no mesmo caminho usado pelo checkout clássico.
 *
 * @package Inky_BTG
 */

(function (wc, settings) {
    'use strict';

    if (!wc || !wc.wcBlocksRegistry || !wc.wcSettings || !window.wp || !window.wp.element) {
        return;
    }

    var registry = wc.wcBlocksRegistry;
    var wcSettings = wc.wcSettings;
    var element = window.wp.element;
    var html = element.createElement;

    var settingsKey = 'inky_btg_pix_data';
    var methodName = 'inky_btg_pix';

    var data = wcSettings.getPaymentMethodData(settingsKey, {
        title: settings.title,
        description: settings.description,
        icon: settings.icon
    });

    var Content = function () {
        return html(
            'div',
            { className: 'inky-btg-block-description' },
            data.description
                ? html('p', null, data.description)
                : null
        );
    };

    var Label = function () {
        return html(
            'span',
            { className: 'inky-btg-block-label' },
            data.icon
                ? html('img', {
                    src: data.icon,
                    alt: '',
                    style: { maxHeight: '24px', marginRight: '8px', verticalAlign: 'middle' }
                })
                : null,
            data.title
        );
    };

    registry.registerPaymentMethod({
        name: methodName,
        label: html(Label, null),
        content: html(Content, null),
        edit: html(Content, null),
        canMakePayment: function () {
            return true;
        },
        supports: { features: data.supports || ['products'] },
        ariaLabel: data.title
    });
})(window.wc, window.inkyBtgBlocks || {});
