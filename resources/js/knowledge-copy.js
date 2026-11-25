/*
 * Copy button of the rendered knowledge articles (KB-05, D-14).
 *
 * One delegated click listener on the document, registered once as a Filament asset. It acts only on the button the
 * renderer emits ([data-kokpit-copy], inside a .kokpit-code block) and copies the textContent of that block's code
 * element: the exact original text, with no highlighting markup, no line numbers and no header text.
 *
 * The script only reads text and data attributes and only assigns textContent and dataset. It never reads or writes
 * markup and never evaluates strings, and it relies on no attribute an author can write (raw HTML is escaped by the
 * renderer and the sanitiser keeps the attributes only on the fixed button). The listener sits on the document, so
 * it survives Livewire morphs and SPA navigation; the flag stops a second load of this file from adding a second one.
 */
(function () {
    'use strict'

    if (window.kokpitKnowledgeCopyBound) {
        return
    }

    window.kokpitKnowledgeCopyBound = true

    var RESET_AFTER_MS = 2000
    var timers = new WeakMap()

    function selectText(element) {
        var selection = window.getSelection ? window.getSelection() : null

        if (!selection) {
            return
        }

        var range = document.createRange()
        range.selectNodeContents(element)
        selection.removeAllRanges()
        selection.addRange(range)
    }

    function show(button, label, state) {
        var labelElement = button.querySelector('.kokpit-code__copy-label')

        if (!labelElement) {
            return
        }

        if (button.dataset.labelDefault === undefined) {
            button.dataset.labelDefault = labelElement.textContent
        }

        labelElement.textContent = label
        button.dataset.state = state

        window.clearTimeout(timers.get(button))
        timers.set(
            button,
            window.setTimeout(function () {
                labelElement.textContent = button.dataset.labelDefault
                delete button.dataset.state
            }, RESET_AFTER_MS),
        )
    }

    function fail(button, codeElement) {
        selectText(codeElement)
        show(button, button.dataset.labelFailed, 'failed')
    }

    document.addEventListener('click', function (event) {
        var target = event.target

        if (!(target instanceof Element)) {
            return
        }

        var button = target.closest('[data-kokpit-copy]')

        if (!button) {
            return
        }

        var block = button.closest('.kokpit-code')
        var codeElement = block ? block.querySelector('pre code') : null

        if (!codeElement) {
            return
        }

        var text = codeElement.textContent
        var pending = null

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            try {
                pending = navigator.clipboard.writeText(text)
            } catch (error) {
                pending = null
            }
        }

        if (!pending || typeof pending.then !== 'function') {
            fail(button, codeElement)

            return
        }

        pending.then(
            function () {
                show(button, button.dataset.labelCopied, 'copied')
            },
            function () {
                fail(button, codeElement)
            },
        )
    })
})()
