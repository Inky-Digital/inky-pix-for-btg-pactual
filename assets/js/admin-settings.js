/**
 * Interações da tela de configurações do PIX BTG Pactual.
 *
 * @package Inky_BTG
 */

(function ($) {
    'use strict';

    /**
     * Exibe o resultado do teste de conexão.
     *
     * @param {jQuery} $target Elemento onde o resultado será exibido.
     * @param {string} message Mensagem a exibir.
     * @param {string} state   Estado: running, success ou error.
     */
    function setResult($target, message, state) {
        $target
            .removeClass('is-running is-success is-error')
            .addClass('is-' + state)
            .text(message);
    }

    /**
     * Copia um texto para a área de transferência.
     *
     * @param {string} text Texto a copiar.
     * @return {Promise} Promessa resolvida quando a cópia termina.
     */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var $temp = $('<textarea>')
                .css({ position: 'fixed', top: '-1000px', opacity: 0 })
                .val(text)
                .appendTo('body');

            $temp[0].select();

            try {
                var ok = document.execCommand('copy');
                $temp.remove();

                if (ok) {
                    resolve();
                } else {
                    reject(new Error('execCommand falhou'));
                }
            } catch (error) {
                $temp.remove();
                reject(error);
            }
        });
    }

    /**
     * Renderiza o checklist de conexão devolvido pelo servidor.
     *
     * @param {jQuery} $container Elemento que recebe a lista.
     * @param {Array}  steps      Etapas retornadas pelo AJAX.
     */
    function renderChecklist($container, steps) {
        var $list = $container.find('.inky-btg-checklist-steps');

        $list.empty();

        if (!steps || !steps.length) {
            $container.prop('hidden', true);
            return;
        }

        steps.forEach(function (step) {
            var state = step.skipped ? 'skip' : (step.ok ? 'ok' : 'fail');
            var label = step.skipped
                ? inkyBtgAdmin.strings.stepSkip
                : (step.ok ? inkyBtgAdmin.strings.stepOk : inkyBtgAdmin.strings.stepFail);

            var $item = $('<li>').addClass('inky-btg-step is-' + state);

            $('<span>')
                .addClass('inky-btg-step-status')
                .text(label)
                .appendTo($item);

            $('<strong>')
                .addClass('inky-btg-step-label')
                .text(step.label)
                .appendTo($item);

            $('<p>')
                .addClass('description')
                .text(step.detail)
                .appendTo($item);

            $list.append($item);
        });

        $container.prop('hidden', false);
    }

    $(function () {
        var $testButton = $('#inky-btg-test-connection');
        var $testResult = $('.inky-btg-test-result');
        var $checklist = $('#inky-btg-checklist');

        /*
         * Checklist de conexão em seis etapas.
         *
         * O servidor devolve cada etapa já avaliada. A interface apenas
         * apresenta o resultado, sem repetir a regra de negócio.
         */
        $testButton.on('click', function () {
            var $button = $(this);

            $button.prop('disabled', true);
            setResult($testResult, inkyBtgAdmin.strings.testing, 'running');
            $checklist.prop('hidden', true);

            $.post(inkyBtgAdmin.ajaxUrl, {
                action: 'inky_btg_test_connection',
                nonce: inkyBtgAdmin.nonce
            })
                .done(function (response) {
                    var data = response && response.data ? response.data : {};
                    var steps = data.steps || [];

                    renderChecklist($checklist, steps);

                    if (response && response.success) {
                        setResult($testResult, data.message || inkyBtgAdmin.strings.testDone, 'success');
                        return;
                    }

                    setResult(
                        $testResult,
                        data.message || inkyBtgAdmin.strings.testFailed,
                        'error'
                    );
                })
                .fail(function () {
                    setResult($testResult, inkyBtgAdmin.strings.checkError, 'error');
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        /*
         * Verificação manual do pagamento de um pedido.
         *
         * Usa o mesmo caminho do polling e do webhook, então confirmar por
         * aqui não corre o risco de aplicar a confirmação duas vezes.
         */
        $('#inky-btg-check-order').on('click', function () {
            var $button = $(this);
            var $result = $('.inky-btg-check-result');
            var orderId = parseInt($('#inky-btg-check-order-id').val(), 10);

            if (!orderId || orderId < 1) {
                setResult($result, inkyBtgAdmin.strings.missingOrder, 'error');
                return;
            }

            $button.prop('disabled', true);
            setResult($result, inkyBtgAdmin.strings.checking, 'running');

            $.post(inkyBtgAdmin.ajaxUrl, {
                action: 'inky_btg_admin_check_order',
                nonce: inkyBtgAdmin.nonce,
                order_id: orderId
            })
                .done(function (response) {
                    if (response && response.success) {
                        setResult($result, response.data.message, 'success');
                        return;
                    }

                    var message = response && response.data && response.data.message
                        ? response.data.message
                        : inkyBtgAdmin.strings.checkError;

                    setResult($result, message, 'error');
                })
                .fail(function () {
                    setResult($result, inkyBtgAdmin.strings.checkError, 'error');
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        /*
         * Cópia do log de diagnóstico.
         */
        $('#inky-btg-copy-log').on('click', function () {
            var $button = $(this);
            var $result = $('.inky-btg-copy-result');
            var content = $('#inky-btg-log-output').val();

            copyText(content)
                .then(function () {
                    $result.text(inkyBtgAdmin.strings.copied);

                    window.setTimeout(function () {
                        $result.text('');
                    }, 3000);
                })
                .catch(function () {
                    $result.text(inkyBtgAdmin.strings.copyError);
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        /*
         * Cópia de valores marcados como copiáveis.
         */
        $(document).on('click', '.inky-btg-copyable', function () {
            var $element = $(this);
            var original = $element.text();

            copyText(original).then(function () {
                $element.text('Copiado!');

                window.setTimeout(function () {
                    $element.text(original);
                }, 1500);
            });
        });
    });
})(jQuery);
