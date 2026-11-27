/*
 * Task-list button and image insertion of the knowledge article editor (D-07, D-01).
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
 *
 * Image flow (IMG-01, D-01, D-03). Every editor the form marks with data-kokpit-image gets, after the table button,
 * an "insert image" button (class kokpit-insert-image, label from the attribute), a hidden file input and a status
 * line (role status) below the toolbar. A file reaches the same queue from three places: the file picker of the
 * button, a paste of image files (a paste without image files is left to CodeMirror, so a text paste is never
 * touched) and a drop of files on the editor (the cursor moves to the drop point first). The queue is strictly
 * serial, one file at a time:
 *
 *   1. a file over data-kokpit-image-max-bytes is refused with a notification and never uploaded
 *   2. the file goes to the imageUpload property of the page through the Livewire temporary upload
 *   3. the page method storeImageUpload() stores it and answers with the reference to insert, or with a refusal
 *   4. an ok answer inserts the reference inside one CodeMirror operation at the cursor (on a new line when the
 *      cursor ends a line with text) and selects the alt part; a refusal, an upload error or a failed call shows a
 *      danger notification and inserts nothing
 *
 * A failure never stops the next file. While the queue runs the status line shows the uploading text with a spinner
 * and the submit buttons of the page are disabled (re-applied after every Livewire request, because a re-render
 * resets them); when it ends they are enabled again and the done text shows for four seconds. Every text travels in
 * a data attribute: this file holds no user-facing words. The reply of the server is only ever used as text (a
 * notification title and body) or as the Markdown reference that CodeMirror inserts.
 */
(function () {
    'use strict'

    if (window.kokpitKnowledgeEditorBound) {
        return
    }

    window.kokpitKnowledgeEditorBound = true

    var FIELD_SELECTOR = '.fi-fo-markdown-editor[data-kokpit-task-list]'
    var IMAGE_FIELD_SELECTOR = '.fi-fo-markdown-editor[data-kokpit-image]'
    var BUTTON_CLASS = 'kokpit-task-list'
    var IMAGE_BUTTON_CLASS = 'kokpit-insert-image'
    var STATUS_CLASS = 'kokpit-image-status'
    var BUSY_CLASS = 'kokpit-image-status--busy'
    var LOCK_ATTRIBUTE = 'data-kokpit-image-locked'
    var DONE_MILLISECONDS = 4000
    var BIND_RETRIES = 30
    var TASK_LINE = /^(\s*)[-*+]\s+\[[ xX]\]\s?/
    var BULLET_LINE = /^(\s*)[-*+]\s+/
    var ICON =
        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='currentColor'%3E" +
        "%3Cpath fill-rule='evenodd' d='M4.25 2A2.25 2.25 0 0 0 2 4.25v11.5A2.25 2.25 0 0 0 4.25 18h11.5A2.25 2.25 0 0 0 " +
        '18 15.75V4.25A2.25 2.25 0 0 0 15.75 2H4.25Zm12.03 5.28a.75.75 0 0 0-1.06-1.06L8.75 12.69 6.28 10.22a.75.75 0 0 ' +
        "0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l7-7Z' clip-rule='evenodd' /%3E%3C/svg%3E\")"
    var IMAGE_ICON =
        "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='currentColor'%3E" +
        "%3Cpath fill-rule='evenodd' d='M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 " +
        '16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm1.5 5.81v3.69c0 .414.336.75.75.75h13.5a.75.75 0 0 0 .75-.75v-2.69l-2.22' +
        '-2.219a.75.75 0 0 0-1.06 0l-1.91 1.909.47.47a.75.75 0 1 1-1.06 1.06L6.53 8.091a.75.75 0 0 0-1.06 0l-2.97 2.97ZM12 ' +
        "7a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z' clip-rule='evenodd' /%3E%3C/svg%3E\")"

    var scheduled = false
    var doneTimer = null
    var commitHooked = false

    // The component roots whose upload queue is running: their submit buttons stay disabled, also after a re-render.
    var lockedRoots = []

    function addIconStyle() {
        var css =
            '.fi-fo-markdown-editor .editor-toolbar .' + BUTTON_CLASS + '::before { mask-image: ' + ICON + '; }' +
            '.fi-fo-markdown-editor .editor-toolbar .' + IMAGE_BUTTON_CLASS + '::before { mask-image: ' + IMAGE_ICON + '; }' +
            '.fi-fo-markdown-editor .' + STATUS_CLASS + ' { display: flex; align-items: center; gap: 0.5rem; ' +
            'margin-top: 0.5rem; font-size: 0.875rem; line-height: 1.5; color: var(--gray-500, inherit); }' +
            '.fi-fo-markdown-editor .' + STATUS_CLASS + ':empty { margin-top: 0; }' +
            '.fi-fo-markdown-editor .' + BUSY_CLASS + '::before { content: ""; flex: none; width: 1rem; height: 1rem; ' +
            'box-sizing: border-box; border: 2px solid var(--primary-600, currentColor); border-right-color: transparent; ' +
            'border-radius: 9999px; animation: kokpit-image-spin 0.8s linear infinite; }' +
            '@keyframes kokpit-image-spin { to { transform: rotate(360deg); } }' +
            '@media (prefers-reduced-motion: reduce) { .fi-fo-markdown-editor .' + BUSY_CLASS +
            '::before { animation-duration: 3s; } }'

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

    function attributeOf(field, name) {
        return field.getAttribute('data-kokpit-image-' + name) || ''
    }

    function componentRootOf(field) {
        return field.closest('[wire\\:id]')
    }

    function wireOf(field) {
        var root = componentRootOf(field)

        if (!root || !window.Livewire || typeof window.Livewire.find !== 'function') {
            return null
        }

        var component = window.Livewire.find(root.getAttribute('wire:id'))

        if (!component) {
            return null
        }

        return component.$wire ? component.$wire : component
    }

    function notify(field, title, body) {
        if (typeof window.FilamentNotification !== 'function') {
            return
        }

        try {
            new window.FilamentNotification()
                .title(title || attributeOf(field, 'error-title'))
                .body(body || attributeOf(field, 'failed'))
                .danger()
                .send()
        } catch (error) {
            // A notification that cannot be shown must never stop the queue.
        }
    }

    function statusOf(field) {
        return field.querySelector('.' + STATUS_CLASS)
    }

    function showStatus(field, text, busy) {
        var status = statusOf(field)

        if (!status) {
            return
        }

        window.clearTimeout(doneTimer)
        status.textContent = text
        status.classList.toggle(BUSY_CLASS, busy)

        if (!busy && text !== '') {
            doneTimer = window.setTimeout(function () {
                status.textContent = ''
            }, DONE_MILLISECONDS)
        }
    }

    function applyLocks() {
        lockedRoots.forEach(function (root) {
            root.querySelectorAll('button[type=submit]').forEach(function (button) {
                if (!button.disabled) {
                    button.disabled = true
                    button.setAttribute(LOCK_ATTRIBUTE, '')
                }
            })
        })
    }

    function releaseLocks(root) {
        lockedRoots = lockedRoots.filter(function (candidate) {
            return candidate !== root
        })

        root.querySelectorAll('button[' + LOCK_ATTRIBUTE + ']').forEach(function (button) {
            button.removeAttribute(LOCK_ATTRIBUTE)
            button.disabled = false
        })
    }

    // A server re-render resets the disabled attribute, so the locks are applied again after every Livewire request.
    function hookCommits() {
        if (commitHooked || !window.Livewire || typeof window.Livewire.hook !== 'function') {
            return
        }

        commitHooked = true

        window.Livewire.hook('commit', function (context) {
            context.succeed(function () {
                window.setTimeout(applyLocks, 0)
            })
        })
    }

    function stateOf(codemirror) {
        if (!codemirror.kokpitImageState) {
            codemirror.kokpitImageState = { files: [], running: false, inserted: 0, after: null, selection: null }
        }

        return codemirror.kokpitImageState
    }

    function samePosition(a, b) {
        return a.line === b.line && a.ch === b.ch
    }

    // Where the next reference goes: the cursor, or the end of the previous reference of this batch when the Admin has
    // not touched the selection that insertion left behind (so several files each get their own line).
    function insertionPoint(codemirror, state) {
        var at = codemirror.getCursor('to')

        if (
            state.after &&
            state.selection &&
            samePosition(codemirror.getCursor('from'), state.selection.from) &&
            samePosition(at, state.selection.to)
        ) {
            return state.after
        }

        return at
    }

    function insertReference(codemirror, state, reference) {
        var altEnd = reference.lastIndexOf('](')

        if (reference.indexOf('![') !== 0 || altEnd < 2) {
            return false
        }

        var at = insertionPoint(codemirror, state)

        codemirror.operation(function () {
            var line = codemirror.getLine(at.line)
            var ownLine = at.ch === line.length && line.trim() !== ''
            var start = ownLine ? { line: at.line + 1, ch: 0 } : { line: at.line, ch: at.ch }
            var from = { line: start.line, ch: start.ch + 2 }
            var to = { line: start.line, ch: start.ch + altEnd }

            codemirror.replaceRange((ownLine ? '\n' : '') + reference, at)
            codemirror.setSelection(from, to)

            state.after = { line: start.line, ch: start.ch + reference.length }
            state.selection = { from: from, to: to }
        })

        return true
    }

    function handleReply(field, codemirror, state, reply) {
        if (reply && reply.ok === true && typeof reply.reference === 'string' && insertReference(codemirror, state, reply.reference)) {
            state.inserted++

            return
        }

        if (reply && reply.ok === false) {
            notify(field, typeof reply.title === 'string' ? reply.title : '', typeof reply.body === 'string' ? reply.body : '')

            return
        }

        notify(field, '', '')
    }

    function uploadOne(field, codemirror, state, file) {
        return new Promise(function (resolve) {
            function failed() {
                notify(field, '', '')
                resolve()
            }

            function stored() {
                Promise.resolve(wire.storeImageUpload()).then(function (reply) {
                    handleReply(field, codemirror, state, reply)
                    resolve()
                }, failed)
            }

            if (file.size > parseInt(attributeOf(field, 'max-bytes'), 10)) {
                notify(field, attributeOf(field, 'error-title'), attributeOf(field, 'too-large'))
                resolve()

                return
            }

            var wire = wireOf(field)

            if (!wire) {
                failed()

                return
            }

            try {
                wire.upload('imageUpload', file, stored, failed, function () {})
            } catch (error) {
                failed()
            }
        })
    }

    function enqueue(field, codemirror, files) {
        var state = stateOf(codemirror)

        files.forEach(function (file) {
            state.files.push(file)
        })

        if (state.running || state.files.length === 0) {
            return
        }

        var root = componentRootOf(field) || field

        state.running = true
        state.inserted = 0
        state.after = null
        state.selection = null

        lockedRoots.push(root)
        applyLocks()
        showStatus(field, attributeOf(field, 'uploading'), true)

        function finish() {
            state.running = false
            releaseLocks(root)
            showStatus(field, state.inserted > 0 ? attributeOf(field, 'done') : '', false)
            codemirror.focus()
        }

        function next() {
            var file = state.files.shift()

            if (!file) {
                finish()

                return
            }

            uploadOne(field, codemirror, state, file).then(next, next)
        }

        next()
    }

    function filesOf(list, onlyImages) {
        var files = []

        if (!list) {
            return files
        }

        for (var index = 0; index < list.length; index++) {
            if (!onlyImages || (list[index].type || '').indexOf('image/') === 0) {
                files.push(list[index])
            }
        }

        return files
    }

    // Paste and drop are bound once per CodeMirror instance (a flag on the instance): Filament rebuilds the editor
    // on some updates and the new instance has no flag yet.
    function bindTransfers(field, codemirror) {
        if (codemirror.kokpitImageBound) {
            return
        }

        codemirror.kokpitImageBound = true

        codemirror.on('paste', function (cm, event) {
            var files = filesOf(event.clipboardData ? event.clipboardData.files : null, true)

            // A paste without image files (plain text, rich text) is left to CodeMirror.
            if (files.length === 0) {
                return
            }

            event.preventDefault()
            enqueue(field, cm, files)
        })

        codemirror.on('drop', function (cm, event) {
            var files = filesOf(event.dataTransfer ? event.dataTransfer.files : null, false)

            if (files.length === 0) {
                return
            }

            event.preventDefault()
            cm.setCursor(cm.coordsChar({ left: event.clientX, top: event.clientY }, 'window'))
            enqueue(field, cm, files)
        })
    }

    function createImageButton(field, toolbar, input) {
        var label = field.getAttribute('data-kokpit-image') || ''
        var button = document.createElement('button')

        button.type = 'button'
        button.className = IMAGE_BUTTON_CLASS
        button.tabIndex = -1
        button.title = label
        button.setAttribute('aria-label', label)

        button.addEventListener('click', function (event) {
            event.preventDefault()
            input.click()
        })

        input.addEventListener('change', function () {
            var files = filesOf(input.files, false)
            var editor = editorOf(toolbar)

            input.value = ''

            if (files.length > 0 && editor && editor.codemirror) {
                enqueue(field, editor.codemirror, files)
            }
        })

        return button
    }

    function enhanceImage(field) {
        var toolbar = field.querySelector('.editor-toolbar')
        var anchor = toolbar ? toolbar.querySelector('button.table') : null

        // The toolbar is built asynchronously by Filament and rebuilt on some updates: add the pieces once per toolbar.
        if (!toolbar || !anchor) {
            return
        }

        if (!toolbar.querySelector('.' + IMAGE_BUTTON_CLASS)) {
            var input = document.createElement('input')

            input.type = 'file'
            input.accept = attributeOf(field, 'accept')
            input.multiple = true
            input.hidden = true
            input.tabIndex = -1

            var button = createImageButton(field, toolbar, input)

            anchor.parentNode.insertBefore(button, anchor.nextSibling)
            button.parentNode.insertBefore(input, button.nextSibling)
        }

        var status = statusOf(field)

        if (!status || status.previousElementSibling !== toolbar) {
            if (status) {
                status.remove()
            }

            status = document.createElement('div')
            status.className = STATUS_CLASS
            status.setAttribute('role', 'status')
            toolbar.parentNode.insertBefore(status, toolbar.nextSibling)
        }

        var editor = editorOf(toolbar)

        if (editor && editor.codemirror) {
            bindTransfers(field, editor.codemirror)
            field.setAttribute('data-kokpit-image-ready', '')

            return
        }

        // Filament assigns the editor right after it builds the toolbar; look again a moment later.
        var tries = parseInt(field.getAttribute('data-kokpit-image-tries') || '0', 10)

        if (tries < BIND_RETRIES) {
            field.setAttribute('data-kokpit-image-tries', String(tries + 1))
            window.setTimeout(schedule, 100)
        }
    }

    function scan() {
        scheduled = false

        document.querySelectorAll(FIELD_SELECTOR).forEach(enhance)
        document.querySelectorAll(IMAGE_FIELD_SELECTOR).forEach(enhanceImage)
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
        hookCommits()

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
    document.addEventListener('livewire:init', hookCommits)
})()
