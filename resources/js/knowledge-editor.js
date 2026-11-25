/*
 * Task-list button of the knowledge article editor (D-07).
 *
 * Filament's Markdown editor has no toolbar entry for a task list, so this script adds one button after the
 * ordered-list button of every editor the form marks with data-kokpit-task-list (the attribute holds the label of the
 * button). The button works on the EasyMDE instance that Filament keeps in the `_editor` property of the Alpine root
 * of the field, and edits the text only through the CodeMirror API: the selected lines are changed in one
 * `operation()`, so one undo step reverts the whole click.
 *
 *   - a line that starts with a task marker loses it ("- [ ] " or "- [x] ")
 *   - a bullet line gains "[ ] " after its bullet
 *   - any other line gains "- [ ] " (after its indentation)
 *
 * The script builds its button with createElement, sets text and attributes only, and never parses or writes markup
 * strings and never evaluates strings. The toolbar icon is a CSS mask, exactly the way Filament draws the other
 * buttons (their rule paints a solid square for a class without a mask), added once as a constructed stylesheet.
 * The editor is rebuilt by Filament on some updates, so a mutation observer (and the Livewire navigation event)
 * looks for editors that lack the button; the flag stops a second load of this file from binding a second observer.
 * The observer watches the document element, not the body element: Livewire's SPA navigation replaces the body
 * element with the body of the next page, so an observer on the old body would watch a detached node and the button
 * would be lost on every editor built after the first navigation. The document element survives navigation.
 */
(function () {
    'use strict'

    if (window.kokpitKnowledgeEditorBound) {
        return
    }

    window.kokpitKnowledgeEditorBound = true

    var FIELD_SELECTOR = '.fi-fo-markdown-editor[data-kokpit-task-list]'
    var BUTTON_CLASS = 'kokpit-task-list'
    var TASK_LINE = /^(\s*)[-*+]\s+\[[ xX]\]\s?/
    var BULLET_LINE = /^(\s*)[-*+]\s+/
    var ICON =
        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='currentColor'%3E" +
        "%3Cpath fill-rule='evenodd' d='M4.25 2A2.25 2.25 0 0 0 2 4.25v11.5A2.25 2.25 0 0 0 4.25 18h11.5A2.25 2.25 0 0 0 " +
        '18 15.75V4.25A2.25 2.25 0 0 0 15.75 2H4.25Zm12.03 5.28a.75.75 0 0 0-1.06-1.06L8.75 12.69 6.28 10.22a.75.75 0 0 ' +
        "0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l7-7Z' clip-rule='evenodd' /%3E%3C/svg%3E\")"

    var scheduled = false

    function addIconStyle() {
        var css = '.fi-fo-markdown-editor .editor-toolbar .' + BUTTON_CLASS + '::before { mask-image: ' + ICON + '; }'

        if ('adoptedStyleSheets' in document && typeof CSSStyleSheet === 'function') {
            try {
                var sheet = new CSSStyleSheet()
                sheet.replaceSync(css)
                document.adoptedStyleSheets = document.adoptedStyleSheets.concat([sheet])

                return
            } catch (error) {
                // Fall through to a style element.
            }
        }

        var style = document.createElement('style')
        style.textContent = css
        document.head.appendChild(style)
    }

    function toggleTaskList(codemirror) {
        var from = codemirror.getCursor('from')
        var to = codemirror.getCursor('to')
        var last = to.line

        // A selection that ends at the very start of a line does not include that line.
        if (to.line > from.line && to.ch === 0) {
            last = to.line - 1
        }

        var single = from.line === last

        codemirror.operation(function () {
            for (var number = from.line; number <= last; number++) {
                var text = codemirror.getLine(number)
                var match = TASK_LINE.exec(text)

                if (match) {
                    codemirror.replaceRange('', { line: number, ch: match[1].length }, { line: number, ch: match[0].length })

                    continue
                }

                match = BULLET_LINE.exec(text)

                if (match) {
                    codemirror.replaceRange('[ ] ', { line: number, ch: match[0].length }, { line: number, ch: match[0].length })

                    continue
                }

                // Blank lines inside a selection stay blank; a lone empty line still gets the marker.
                if (!single && text.trim() === '') {
                    continue
                }

                var indent = /^\s*/.exec(text)[0].length

                codemirror.replaceRange('- [ ] ', { line: number, ch: indent }, { line: number, ch: indent })
            }
        })

        codemirror.focus()
    }

    function editorOf(toolbar) {
        var root = toolbar.closest('[x-data]')

        return root && root._editor ? root._editor : null
    }

    function createButton(label, toolbar) {
        var button = document.createElement('button')

        button.type = 'button'
        button.className = BUTTON_CLASS
        button.tabIndex = -1
        button.title = label
        button.setAttribute('aria-label', label)

        button.addEventListener('click', function (event) {
            event.preventDefault()

            var editor = editorOf(toolbar)

            if (editor && editor.codemirror) {
                toggleTaskList(editor.codemirror)
            }
        })

        return button
    }

    function enhance(field) {
        var toolbar = field.querySelector('.editor-toolbar')
        var anchor = toolbar ? toolbar.querySelector('button.ordered-list') : null

        // The toolbar is built asynchronously by Filament; wait until it exists, and add the button only once.
        if (!toolbar || !anchor || toolbar.querySelector('.' + BUTTON_CLASS)) {
            return
        }

        var label = field.getAttribute('data-kokpit-task-list') || ''

        anchor.parentNode.insertBefore(createButton(label, toolbar), anchor.nextSibling)
        field.setAttribute('data-kokpit-task-list-ready', '')
    }

    function scan() {
        scheduled = false

        document.querySelectorAll(FIELD_SELECTOR).forEach(enhance)
    }

    function schedule() {
        if (scheduled) {
            return
        }

        scheduled = true
        window.requestAnimationFrame(scan)
    }

    function start() {
        addIconStyle()

        new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true })

        schedule()
    }

    if (document.body) {
        start()
    } else {
        document.addEventListener('DOMContentLoaded', start, { once: true })
    }

    document.addEventListener('DOMContentLoaded', schedule)
    document.addEventListener('livewire:navigated', schedule)
})()
