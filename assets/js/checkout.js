/**
 * Comportamento do bloco PIX na página de obrigado.
 *
 * Cuida de copiar o código e de verificar o pagamento sob demanda. A
 * verificação automática por polling é responsabilidade do módulo de polling
 * no servidor; aqui apenas consultamos o status do pedido quando o cliente
 * pede.
 *
 * @package Inky_BTG
 */

(function () {
    'use strict';

    /**
     * Copia o conteúdo de um campo para a área de transferência.
     *
     * @param {string} selector Seletor do campo de origem.
     * @return {void}
     */
    function copyFrom(selector) {
        var field = document.querySelector(selector);

        if (!field) {
            return;
        }

        var text = field.value || field.textContent || '';

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(
                function () {
                    announce(window.inkyBtgCheckout.copied);
                },
                function () {
                    fallbackCopy(field);
                }
            );

            return;
        }

        fallbackCopy(field);
    }

    /**
     * Copia usando a seleção nativa, para navegadores sem Clipboard API.
     *
     * @param {HTMLElement} field Campo de origem.
     * @return {void}
     */
    function fallbackCopy(field) {
        try {
            field.select();
            field.setSelectionRange(0, 99999);
            document.execCommand('copy');
            announce(window.inkyBtgCheckout.copied);
        } catch (error) {
            announce(window.inkyBtgCheckout.error, true);
        }
    }

    /**
     * Atualiza a mensagem de status exibida ao cliente.
     *
     * @param {string}  message Texto a exibir.
     * @param {boolean} isError Indica se é uma mensagem de erro.
     * @return {void}
     */
    function announce(message, isError) {
        var status = document.getElementById('inky-btg-status');

        if (!status) {
            return;
        }

        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    /**
     * Liga os botões do bloco.
     *
     * @return {void}
     */
    function bind() {
        var copyButton = document.querySelector('[data-inky-btg-copy]');

        if (copyButton) {
            copyButton.addEventListener('click', function () {
                copyFrom(copyButton.getAttribute('data-inky-btg-copy'));
            });
        }

        var verifyButton = document.querySelector('[data-inky-btg-verify]');

        if (verifyButton) {
            verifyButton.addEventListener('click', function () {
                verify(verifyButton);
            });
        }
    }

    /**
     * Consulta o status do pedido no servidor.
     *
     * @param {HTMLElement} button Botão acionado.
     * @return {void}
     */
    function verify(button) {
        var orderId = button.getAttribute('data-inky-btg-verify');

        if (!orderId || !window.inkyBtgCheckout || !window.inkyBtgCheckout.ajaxUrl) {
            return;
        }

        button.disabled = true;

        var body = new URLSearchParams();
        body.append('action', 'inky_btg_check_payment');
        body.append('order_id', orderId);
        body.append('nonce', window.inkyBtgCheckout.nonce);

        window
            .fetch(window.inkyBtgCheckout.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                if (payload && payload.data && payload.data.paid) {
                    announce(payload.data.message, false);
                    window.location.reload();
                    return;
                }

                announce(payload && payload.data ? payload.data.message : '', true);
            })
            .catch(function () {
                announce(window.inkyBtgCheckout.error, true);
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
